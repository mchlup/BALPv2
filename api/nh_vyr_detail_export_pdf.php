<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';
require_once __DIR__ . '/nh_vyr_helpers.php';
require_once __DIR__ . '/pdf_helpers.php';

header('Content-Type: application/pdf');
$filename = 'balp_vyrobni_prikaz_' . date('Ymd_His') . '.pdf';
header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        http_response_code(403);
        echo balp_simple_pdf(['Přístup byl odepřen.'], 'Chyba');
        exit;
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        http_response_code(401);
        echo balp_simple_pdf(['Chybí token.'], 'Chyba');
        exit;
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo balp_simple_pdf(['Chybí parametr ID.'], 'Chyba');
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
        echo balp_simple_pdf(['Záznam nebyl nalezen.'], 'Chyba');
        exit;
    }

    $cisloVp = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? '');
    $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';

    $lines = [];
    $lines[] = 'Výrobní příkaz NH';
    $lines[] = 'Číslo VP: ' . $cisloVp;
    $lines[] = 'Datum výroby: ' . $datum;
    $lines[] = 'Číslo NH: ' . ($row['cislo_nh'] ?? '');
    $lines[] = 'Název NH: ' . ($row['nazev_nh'] ?? '');
    $lines[] = 'Vyrobit (g): ' . ($row['vyrobit_g'] ?? '');
    $lines[] = 'Poznámka: ' . ($row['poznamka'] ?? '');

    echo balp_simple_pdf($lines, 'Výrobní příkaz ' . $cisloVp);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo balp_simple_pdf(['Došlo k chybě:', $e->getMessage()], 'Chyba');
    exit;
}
