<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
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

    $limit = max(1, min(500, (int)($_GET['limit'] ?? 250)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = trim((string)($_GET['q'] ?? ($_GET['query'] ?? '')));
    $sortCol = strtolower((string)($_GET['sort'] ?? ($_GET['sort_col'] ?? 'cislo')));
    $sortDir = strtoupper((string)($_GET['dir'] ?? ($_GET['sort_dir'] ?? 'ASC')));
    $sortDir = $sortDir === 'DESC' ? 'DESC' : 'ASC';

    $table = sql_quote_ident(balp_ral_table_name());
    $alias = 'ral';

    $idColumn = balp_ral_id_column($pdo);
    $codeColumn = balp_ral_code_column($pdo);
    $nameColumn = balp_ral_name_column($pdo);
    $hexColumn = balp_ral_hex_column($pdo);
    $rgbColumn = balp_ral_rgb_column($pdo);

    $selectParts = [
        "$alias.*",
        "$alias." . sql_quote_ident($idColumn) . ' AS id',
    ];

    $where = [];
    $params = [];
    if ($search !== '') {
        $searchLower = mb_strtolower($search, 'UTF-8');
        $params[':search'] = '%' . $searchLower . '%';
        $searchParts = [];
        if ($codeColumn) {
            $searchParts[] = 'LOWER(' . $alias . '.' . sql_quote_ident($codeColumn) . ') LIKE :search';
        }
        if ($nameColumn) {
            $searchParts[] = 'LOWER(' . $alias . '.' . sql_quote_ident($nameColumn) . ') LIKE :search';
        }
        if ($hexColumn) {
            $searchParts[] = 'LOWER(' . $alias . '.' . sql_quote_ident($hexColumn) . ') LIKE :search';
        }
        if ($rgbColumn) {
            $searchParts[] = 'LOWER(' . $alias . '.' . sql_quote_ident($rgbColumn) . ') LIKE :search';
        }
        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = 'SELECT COUNT(*) FROM ' . $table . ' AS ' . $alias . ' ' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $orderColumns = [
        'id' => $alias . '.' . sql_quote_ident($idColumn),
    ];
    if ($codeColumn) {
        $orderColumns['cislo'] = $alias . '.' . sql_quote_ident($codeColumn);
    }
    if ($nameColumn) {
        $orderColumns['nazev'] = $alias . '.' . sql_quote_ident($nameColumn);
    }

    $orderExpr = $orderColumns[$sortCol] ?? reset($orderColumns);
    if (!$orderExpr) {
        $orderExpr = $alias . '.' . sql_quote_ident($idColumn);
    }

    $sql = 'SELECT ' . implode(', ', $selectParts)
        . ' FROM ' . $table . ' AS ' . $alias
        . ' ' . $whereSql
        . ' ORDER BY ' . $orderExpr . ' ' . $sortDir
        . ' LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $normalized = balp_ral_normalize_row($pdo, $row);
        $labelParts = [];
        if (!empty($normalized['cislo'])) {
            $labelParts[] = $normalized['cislo'];
        }
        if (!empty($normalized['nazev'])) {
            $labelParts[] = $normalized['nazev'];
        }
        $normalized['label'] = $labelParts ? implode(' – ', $labelParts) : null;
        $items[] = $normalized;
    }

    echo json_encode([
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
