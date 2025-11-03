<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('nh_vyroba', 'helpers');

header('Content-Type: text/csv; charset=utf-8');
$filename = 'balp_vyrobni_prikazy_' . date('Ymd_His') . '.csv';
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

    $pdo = db();

    $vpColumn = nh_vyr_vp_column($pdo);
    $dateColumn = nh_vyr_date_column($pdo);
    $qtyColumn = nh_vyr_qty_column($pdo);
    $noteColumn = nh_vyr_note_column($pdo);
    $alias = 'v';

    $vpFrom = nh_vyr_normalize_vp_digits($_GET['vp_od'] ?? $_GET['od'] ?? null);
    $vpTo   = nh_vyr_normalize_vp_digits($_GET['vp_do'] ?? $_GET['do'] ?? null);
    $limit  = null;
    $offset = null;
    if (empty($_GET['all'])) {
        $limit = max(1, min(1000, (int)($_GET['limit'] ?? 500)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
    }

    $table = sql_quote_ident(nh_vyr_table_name());
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $fkToNh = nh_vyr_vyr_nh_fk($pdo);
    $join = "LEFT JOIN $nhTable AS nh ON nh.id = v." . sql_quote_ident($fkToNh);

    $where = [];
    $params = [];
    $paramTypes = [];
    if ($vpFrom !== null) {
        $where[] = nh_vyr_digits_condition($pdo, $alias, 'vp_from', '>=');
        $params[':vp_from'] = (int)$vpFrom;
        $paramTypes[':vp_from'] = PDO::PARAM_INT;
    }
    if ($vpTo !== null) {
        $where[] = nh_vyr_digits_condition($pdo, $alias, 'vp_to', '<=');
        $params[':vp_to'] = (int)$vpTo;
        $paramTypes[':vp_to'] = PDO::PARAM_INT;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $orderSql = nh_vyr_digits_expr($pdo, $alias) . ' ASC';
    $limitSql = '';
    if ($limit !== null) {
        $limitSql = ' LIMIT :limit OFFSET :offset';
    }

    $vpSelect = nh_vyr_column_ref($alias, $vpColumn) . ' AS cislo_vp_raw';
    $dateSelect = $dateColumn ? nh_vyr_column_ref($alias, $dateColumn) . ' AS datum_vyroby_raw' : 'NULL AS datum_vyroby_raw';
    $qtySelect = $qtyColumn ? nh_vyr_column_ref($alias, $qtyColumn) . ' AS vyrobit_g_raw' : 'NULL AS vyrobit_g_raw';
    $noteSelect = $noteColumn ? nh_vyr_column_ref($alias, $noteColumn) . ' AS poznamka_raw' : 'NULL AS poznamka_raw';

    $sql = "SELECT v.id, $vpSelect, $dateSelect, $qtySelect, $noteSelect, nh.cislo AS cislo_nh, nh.nazev AS nazev_nh
            FROM $table AS v
            $join
            $whereSql
            ORDER BY $orderSql$limitSql";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $type = $paramTypes[$k] ?? (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->bindValue($k, $v, $type);
    }
    if ($limit !== null) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Číslo VP', 'Datum výroby', 'Číslo NH', 'Název NH', 'Vyrobit (g)', 'Poznámka'], ';');
    foreach ($rows as $row) {
        $row = nh_vyr_normalize_header_row($pdo, $row);
        $cisloVp = $row['cislo_vp'] ?? '';
        $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';
        $vyrobit = $row['vyrobit_g'] ?? '';
        if (is_float($vyrobit) || is_int($vyrobit)) {
            $vyrobit = number_format((float)$vyrobit, 3, ',', ' ');
            $vyrobit = rtrim(rtrim($vyrobit, '0'), ',');
        }
        fputcsv($out, [
            $row['id'] ?? '',
            $cisloVp,
            $datum,
            $row['cislo_nh'] ?? '',
            $row['nazev_nh'] ?? '',
            $vyrobit,
            $row['poznamka'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error: ' . $e->getMessage();
    exit;
}
