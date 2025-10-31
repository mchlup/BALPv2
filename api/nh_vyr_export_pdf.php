<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';
require_once __DIR__ . '/nh_vyr_helpers.php';
require_once __DIR__ . '/pdf_helpers.php';

header('Content-Type: application/pdf');
$filename = 'balp_vyrobni_prikazy_' . date('Ymd_His') . '.pdf';
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

    $pdo = db();

    $vpFrom = nh_vyr_normalize_vp_digits($_GET['vp_od'] ?? $_GET['od'] ?? null);
    $vpTo   = nh_vyr_normalize_vp_digits($_GET['vp_do'] ?? $_GET['do'] ?? null);

    $table = sql_quote_ident(nh_vyr_table_name());
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $fkToNh = nh_vyr_vyr_nh_fk($pdo);
    $join = "LEFT JOIN $nhTable AS nh ON nh.id = v." . sql_quote_ident($fkToNh);

    $where = [];
    $params = [];
    if ($vpFrom !== null) {
        $where[] = nh_vyr_digits_condition('v.cislo_vp', 'vp_from', '>=');
        $params[':vp_from'] = $vpFrom;
    }
    if ($vpTo !== null) {
        $where[] = nh_vyr_digits_condition('v.cislo_vp', 'vp_to', '<=');
        $params[':vp_to'] = $vpTo;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT v.id, v.cislo_vp, v.datum_vyroby, nh.cislo AS cislo_nh, nh.nazev AS nazev_nh, v.vyrobit_g, v.poznamka
            FROM $table AS v
            $join
            $whereSql
            ORDER BY " . nh_vyr_digits_expr('v');

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lines = [];
    $lines[] = 'Výrobní příkazy NH';
    $lines[] = 'Generováno: ' . date('d.m.Y H:i');
    $lines[] = '';
    $lines[] = 'ID | Číslo VP | Datum výroby | Číslo NH | Název NH | Vyrobit (g) | Poznámka';
    foreach ($rows as $row) {
        $cisloVp = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? '');
        $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';
        $line = ($row['id'] ?? '') . ' | ' . $cisloVp . ' | ' . $datum . ' | ' . ($row['cislo_nh'] ?? '') . ' | '
            . ($row['nazev_nh'] ?? '') . ' | ' . ($row['vyrobit_g'] ?? '') . ' | ' . ($row['poznamka'] ?? '');
        $lines[] = $line;
    }
    if (count($rows) === 0) {
        $lines[] = 'Žádné záznamy.';
    }

    echo balp_simple_pdf($lines, 'Výrobní příkazy NH');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo balp_simple_pdf(['Došlo k chybě:', $e->getMessage()], 'Chyba');
    exit;
}
