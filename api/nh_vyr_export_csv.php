<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';
require_once __DIR__ . '/nh_vyr_helpers.php';

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
    if ($vpFrom !== null) {
        $where[] = nh_vyr_digits_condition('v.cislo_vp', 'vp_from', '>=');
        $params[':vp_from'] = $vpFrom;
    }
    if ($vpTo !== null) {
        $where[] = nh_vyr_digits_condition('v.cislo_vp', 'vp_to', '<=');
        $params[':vp_to'] = $vpTo;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $orderSql = nh_vyr_digits_expr('v') . ' ASC';
    $limitSql = '';
    if ($limit !== null) {
        $limitSql = ' LIMIT :limit OFFSET :offset';
    }

    $sql = "SELECT v.id, v.cislo_vp, v.datum_vyroby, v.vyrobit_g, v.poznamka, nh.cislo AS cislo_nh, nh.nazev AS nazev_nh
            FROM $table AS v
            $join
            $whereSql
            ORDER BY $orderSql$limitSql";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
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
        $cisloVp = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? '');
        $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';
        fputcsv($out, [
            $row['id'] ?? '',
            $cisloVp,
            $datum,
            $row['cislo_nh'] ?? '',
            $row['nazev_nh'] ?? '',
            $row['vyrobit_g'] ?? '',
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
