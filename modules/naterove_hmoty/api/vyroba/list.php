<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('naterove_hmoty', 'vyroba_helpers');

header('Content-Type: application/json; charset=utf-8');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        respond_json(['error' => 'Auth disabled'], 403);
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        respond_json(['error' => 'missing token'], 401);
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $pdo = db();

    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $sortCol = strtolower((string)($_GET['sort_col'] ?? 'cislo_vp'));
    $sortDir = strtoupper((string)($_GET['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $vpFromRaw = $_GET['vp_od'] ?? $_GET['od'] ?? null;
    $vpToRaw   = $_GET['vp_do'] ?? $_GET['do'] ?? null;

    $vpFromDigits = nh_vyr_normalize_vp_digits($vpFromRaw);
    $vpToDigits   = nh_vyr_normalize_vp_digits($vpToRaw);

    $table = sql_quote_ident(nh_vyr_table_name());
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $alias = 'v';
    $fkToNh = nh_vyr_vyr_nh_fk($pdo);
    $join = "LEFT JOIN $nhTable AS nh ON nh.id = v." . sql_quote_ident($fkToNh);

    $columns = [
        'id' => 'v.id',
        'cislo_vp' => nh_vyr_digits_expr($alias),
        'datum_vyroby' => 'v.datum_vyroby',
        'cislo_nh' => 'nh.cislo',
        'nazev_nh' => 'nh.nazev',
        'vyrobit_g' => 'v.vyrobit_g',
        'poznamka' => 'v.poznamka',
    ];

    $orderExpr = $columns['cislo_vp'];
    if (isset($columns[$sortCol])) {
        if ($sortCol === 'cislo_vp') {
            $orderExpr = $columns[$sortCol];
        } else {
            $orderExpr = $columns[$sortCol];
        }
    }

    $where = [];
    $params = [];

    if ($vpFromDigits !== null) {
        $where[] = nh_vyr_digits_condition('v.cislo_vp', 'vp_from', '>=');
        $params[':vp_from'] = $vpFromDigits;
    }
    if ($vpToDigits !== null) {
        $where[] = nh_vyr_digits_condition('v.cislo_vp', 'vp_to', '<=');
        $params[':vp_to'] = $vpToDigits;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) FROM $table AS v $join $whereSql";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $orderSql = $orderExpr . ' ' . $sortDir;

    $selectSql = "SELECT v.id, v.cislo_vp, v.datum_vyroby, v.vyrobit_g, v.poznamka, v." . sql_quote_ident($fkToNh) . " AS idnh,
        nh.cislo AS cislo_nh, nh.nazev AS nazev_nh
        FROM $table AS v
        $join
        $whereSql
        ORDER BY $orderSql
        LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($selectSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['cislo_vp'] = nh_vyr_format_vp($row['cislo_vp']) ?? ($row['cislo_vp'] ?? null);
        if (isset($row['datum_vyroby']) && $row['datum_vyroby'] !== null) {
            $row['datum_vyroby'] = substr((string)$row['datum_vyroby'], 0, 10);
        }
    }
    unset($row);

    echo json_encode([
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'items' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
