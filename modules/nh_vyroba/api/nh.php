<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');

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
    balp_ensure_nh_table($pdo);

    $limit = (int)($_GET['limit'] ?? 15);
    $limit = max(1, min(50, $limit));
    $queryRaw = (string)($_GET['q'] ?? ($_GET['query'] ?? ''));
    $query = trim($queryRaw);
    if ($query === '') {
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    $table = sql_quote_ident(balp_nh_table_name());
    $alias = 'nh';

    $selectParts = [
        "$alias." . sql_quote_ident('id') . ' AS id',
        "$alias." . sql_quote_ident('cislo') . ' AS cislo',
        "$alias." . sql_quote_ident('nazev') . ' AS nazev',
    ];

    $searchExpressions = [
        "LOWER($alias." . sql_quote_ident('cislo') . ')',
        "LOWER($alias." . sql_quote_ident('nazev') . ')',
    ];

    if (balp_nh_has_column($pdo, 'cislo_vt')) {
        $selectParts[] = "$alias." . sql_quote_ident('cislo_vt') . ' AS cislo_vt';
        $searchExpressions[] = "LOWER($alias." . sql_quote_ident('cislo_vt') . ')';
    }
    if (balp_nh_has_column($pdo, 'cislo_vp')) {
        $selectParts[] = "$alias." . sql_quote_ident('cislo_vp') . ' AS cislo_vp';
        $searchExpressions[] = "LOWER($alias." . sql_quote_ident('cislo_vp') . ')';
    }

    $terms = preg_split('/\s+/', mb_strtolower($query, 'UTF-8')) ?: [];
    $terms = array_filter($terms, static fn($t) => $t !== '');

    if (!$searchExpressions) {
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    $where = ['1'];
    $params = [];
    $idx = 0;
    foreach ($terms as $term) {
        $param = ':t' . $idx++;
        $params[$param] = '%' . $term . '%';
        $where[] = '(' . implode(' OR ', array_map(static fn($expr) => "$expr LIKE $param", $searchExpressions)) . ')';
    }

    $orderParts = [];
    if (balp_nh_has_column($pdo, 'cislo')) {
        $orderParts[] = "$alias." . sql_quote_ident('cislo');
    }
    if (balp_nh_has_column($pdo, 'nazev')) {
        $orderParts[] = "$alias." . sql_quote_ident('nazev');
    }
    $orderSql = $orderParts ? implode(', ', $orderParts) : "$alias." . sql_quote_ident('id') . ' DESC';

    $sql = 'SELECT ' . implode(', ', $selectParts)
        . " FROM $table AS $alias"
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $orderSql
        . ' LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'cislo' => $row['cislo'] ?? null,
            'nazev' => $row['nazev'] ?? null,
            'cislo_vt' => $row['cislo_vt'] ?? null,
            'cislo_vp' => $row['cislo_vp'] ?? null,
        ];
    }

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
