<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('nh_vyroba', 'helpers');
balp_include_module_include('vzornik_ral', 'helpers');

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

    $vpColumn = nh_vyr_vp_column($pdo);
    $dateColumn = nh_vyr_date_column($pdo);
    $qtyColumn = nh_vyr_qty_column($pdo);
    $noteColumn = nh_vyr_note_column($pdo);
    $digitsExpr = nh_vyr_digits_expr($pdo, 'v');

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
    $joins = ["LEFT JOIN $nhTable AS nh ON nh.id = v." . sql_quote_ident($fkToNh)];

    $ralFk = nh_vyr_ral_fk($pdo);
    $ralAlias = 'ral';
    $ralIdColumn = null;
    $ralCodeColumn = null;
    $ralNameColumn = null;
    $ralHexColumn = null;
    $ralRgbColumn = null;
    $ralRColumn = null;
    $ralGColumn = null;
    $ralBColumn = null;
    if ($ralFk) {
        $ralTable = sql_quote_ident(balp_ral_table_name());
        $ralIdColumn = balp_ral_id_column($pdo);
        $ralCodeColumn = balp_ral_code_column($pdo);
        $ralNameColumn = balp_ral_name_column($pdo);
        $ralHexColumn = balp_ral_hex_column($pdo);
        $ralRgbColumn = balp_ral_rgb_column($pdo);
        $ralRColumn = balp_ral_rgb_component_column($pdo, 'r');
        $ralGColumn = balp_ral_rgb_component_column($pdo, 'g');
        $ralBColumn = balp_ral_rgb_component_column($pdo, 'b');
        $joins[] = "LEFT JOIN $ralTable AS $ralAlias ON $ralAlias." . sql_quote_ident($ralIdColumn)
            . ' = v.' . sql_quote_ident($ralFk);
    }
    $join = implode(' ', $joins);

    $columns = [
        'id' => 'v.id',
        'cislo_vp' => $digitsExpr,
        'datum_vyroby' => $dateColumn ? nh_vyr_column_ref($alias, $dateColumn) : 'v.id',
        'cislo_nh' => 'nh.cislo',
        'nazev_nh' => 'nh.nazev',
        'vyrobit_g' => $qtyColumn ? nh_vyr_column_ref($alias, $qtyColumn) : 'v.id',
        'poznamka' => $noteColumn ? nh_vyr_column_ref($alias, $noteColumn) : 'v.id',
    ];
    if ($ralFk && $ralCodeColumn) {
        $columns['ral_cislo'] = "$ralAlias." . sql_quote_ident($ralCodeColumn);
    }
    if ($ralFk && $ralNameColumn) {
        $columns['ral_nazev'] = "$ralAlias." . sql_quote_ident($ralNameColumn);
    }

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
    $paramTypes = [];

    if ($vpFromDigits !== null) {
        $where[] = nh_vyr_digits_condition($pdo, $alias, 'vp_from', '>=');
        $params[':vp_from'] = (int)$vpFromDigits;
        $paramTypes[':vp_from'] = PDO::PARAM_INT;
    }
    if ($vpToDigits !== null) {
        $where[] = nh_vyr_digits_condition($pdo, $alias, 'vp_to', '<=');
        $params[':vp_to'] = (int)$vpToDigits;
        $paramTypes[':vp_to'] = PDO::PARAM_INT;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) FROM $table AS v $join $whereSql";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $type = $paramTypes[$k] ?? (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $countStmt->bindValue($k, $v, $type);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $orderSql = $orderExpr . ' ' . $sortDir;

    $vpSelect = nh_vyr_column_ref($alias, $vpColumn) . ' AS cislo_vp_raw';
    $dateSelect = $dateColumn ? nh_vyr_column_ref($alias, $dateColumn) . ' AS datum_vyroby_raw' : 'NULL AS datum_vyroby_raw';
    $qtySelect = $qtyColumn ? nh_vyr_column_ref($alias, $qtyColumn) . ' AS vyrobit_g_raw' : 'NULL AS vyrobit_g_raw';
    $noteSelect = $noteColumn ? nh_vyr_column_ref($alias, $noteColumn) . ' AS poznamka_raw' : 'NULL AS poznamka_raw';

    $selectParts = [
        'v.id',
        $vpSelect,
        $dateSelect,
        $qtySelect,
        $noteSelect,
        'v.' . sql_quote_ident($fkToNh) . ' AS idnh',
        'nh.cislo AS cislo_nh',
        'nh.nazev AS nazev_nh',
    ];
    if ($ralFk) {
        $selectParts[] = 'v.' . sql_quote_ident($ralFk) . ' AS ral_id_raw';
        if ($ralIdColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralIdColumn) . ' AS ral_id';
        }
        if ($ralCodeColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralCodeColumn) . ' AS ral_cislo';
        }
        if ($ralNameColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralNameColumn) . ' AS ral_nazev';
        }
        if ($ralHexColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralHexColumn) . ' AS ral_hex';
        }
        if ($ralRgbColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralRgbColumn) . ' AS ral_rgb';
        }
        if ($ralRColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralRColumn) . ' AS ral_rgb_r';
        }
        if ($ralGColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralGColumn) . ' AS ral_rgb_g';
        }
        if ($ralBColumn) {
            $selectParts[] = "$ralAlias." . sql_quote_ident($ralBColumn) . ' AS ral_rgb_b';
        }
    }

    $selectSql = 'SELECT ' . implode(', ', $selectParts)
        . " FROM $table AS v $join $whereSql ORDER BY $orderSql LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($selectSql);
    foreach ($params as $k => $v) {
        $type = $paramTypes[$k] ?? (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->bindValue($k, $v, $type);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row = nh_vyr_normalize_header_row($pdo, $row);
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
