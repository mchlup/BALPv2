<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('naterove_hmoty', 'vyroba_helpers');
require_once balp_api_path('pdf_helpers.php');

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

    $vpColumn = nh_vyr_vp_column($pdo);
    $dateColumn = nh_vyr_date_column($pdo);
    $qtyColumn = nh_vyr_qty_column($pdo);
    $noteColumn = nh_vyr_note_column($pdo);
    $alias = 'v';

    $vpFrom = nh_vyr_normalize_vp_digits($_GET['vp_od'] ?? $_GET['od'] ?? null);
    $vpTo   = nh_vyr_normalize_vp_digits($_GET['vp_do'] ?? $_GET['do'] ?? null);

    $table = sql_quote_ident(nh_vyr_table_name());
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $fkToNh = nh_vyr_vyr_nh_fk($pdo);
    $join = "LEFT JOIN $nhTable AS nh ON nh.id = v." . sql_quote_ident($fkToNh);

    $where = [];
    $params = [];
    if ($vpFrom !== null) {
        $where[] = nh_vyr_digits_condition($pdo, $alias, 'vp_from', '>=');
        $params[':vp_from'] = $vpFrom;
    }
    if ($vpTo !== null) {
        $where[] = nh_vyr_digits_condition($pdo, $alias, 'vp_to', '<=');
        $params[':vp_to'] = $vpTo;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $vpSelect = nh_vyr_column_ref($alias, $vpColumn) . ' AS cislo_vp_raw';
    $dateSelect = $dateColumn ? nh_vyr_column_ref($alias, $dateColumn) . ' AS datum_vyroby_raw' : 'NULL AS datum_vyroby_raw';
    $qtySelect = $qtyColumn ? nh_vyr_column_ref($alias, $qtyColumn) . ' AS vyrobit_g_raw' : 'NULL AS vyrobit_g_raw';
    $noteSelect = $noteColumn ? nh_vyr_column_ref($alias, $noteColumn) . ' AS poznamka_raw' : 'NULL AS poznamka_raw';

    $sql = "SELECT v.id, $vpSelect, $dateSelect, $qtySelect, $noteSelect, nh.cislo AS cislo_nh, nh.nazev AS nazev_nh
            FROM $table AS v
            $join
            $whereSql
            ORDER BY " . nh_vyr_digits_expr($pdo, $alias);

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
        $row = nh_vyr_normalize_header_row($pdo, $row);
        $cisloVp = $row['cislo_vp'] ?? '';
        $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';
        $vyrobit = $row['vyrobit_g'] ?? '';
        if (is_float($vyrobit) || is_int($vyrobit)) {
            $vyrobit = number_format((float)$vyrobit, 3, ',', ' ');
            $vyrobit = rtrim(rtrim($vyrobit, '0'), ',');
        }
        $line = ($row['id'] ?? '') . ' | ' . $cisloVp . ' | ' . $datum . ' | ' . ($row['cislo_nh'] ?? '') . ' | '
            . ($row['nazev_nh'] ?? '') . ' | ' . $vyrobit . ' | ' . ($row['poznamka'] ?? '');
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
