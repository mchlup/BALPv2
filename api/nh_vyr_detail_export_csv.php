<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';
require_once __DIR__ . '/nh_vyr_helpers.php';

header('Content-Type: text/csv; charset=utf-8');
$filename = 'balp_vyrobni_prikaz_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        http_response_code(403);
        echo 'error: auth disabled';
        exit;
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        http_response_code(401);
        echo 'error: missing token';
        exit;
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo 'error: missing id';
        exit;
    }

    $pdo = db();

    $table = sql_quote_ident('balp_nhods_vyr');
    $nhTable = sql_quote_ident(balp_nh_table_name());

    $sql = "SELECT v.*, nh.cislo AS cislo_nh, nh.nazev AS nazev_nh
            FROM $table AS v
            LEFT JOIN $nhTable AS nh ON nh.id = v.idnh
            WHERE v.id = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo 'error: not found';
        exit;
    }

    $cisloVp = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? '');
    $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Číslo VP', 'Datum výroby', 'Číslo NH', 'Název NH', 'Vyrobit (g)', 'Poznámka'], ';');
    fputcsv($out, [
        $row['id'] ?? '',
        $cisloVp,
        $datum,
        $row['cislo_nh'] ?? '',
        $row['nazev_nh'] ?? '',
        $row['vyrobit_g'] ?? '',
        $row['poznamka'] ?? '',
    ], ';');
    fclose($out);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error: ' . $e->getMessage();
    exit;
}
