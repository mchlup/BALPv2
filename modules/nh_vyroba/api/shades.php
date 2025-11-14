<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('nh_vyroba', 'helpers');

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

    $limit = (int)($_GET['limit'] ?? 15);
    $limit = max(1, min(50, $limit));
    $queryRaw = (string)($_GET['q'] ?? ($_GET['query'] ?? ''));
    $query = trim($queryRaw);
    if ($query === '') {
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    $table = sql_quote_ident(nh_vyr_shade_table_name());
    $alias = 'ods';
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $nhAlias = 'nh';
    $nhFk = nh_vyr_shade_nh_fk($pdo);
    $codeCol = nh_vyr_shade_code_column($pdo);
    $variantCol = nh_vyr_shade_variant_column($pdo);
    $nhCodeCol = nh_vyr_shade_nh_code_column($pdo);
    $nameCol = nh_vyr_shade_name_column($pdo);

    $selectParts = [
        "$alias." . sql_quote_ident('id') . ' AS id',
        $codeCol ? "$alias." . sql_quote_ident($codeCol) . ' AS shade_cislo' : 'NULL AS shade_cislo',
        $variantCol ? "$alias." . sql_quote_ident($variantCol) . ' AS shade_cislo_ods' : 'NULL AS shade_cislo_ods',
        $nhCodeCol ? "$alias." . sql_quote_ident($nhCodeCol) . ' AS shade_cislo_nh' : 'NULL AS shade_cislo_nh',
        $nameCol ? "$alias." . sql_quote_ident($nameCol) . ' AS shade_nazev' : 'NULL AS shade_nazev',
        "$nhAlias." . sql_quote_ident('cislo') . ' AS nh_cislo',
        "$nhAlias." . sql_quote_ident('nazev') . ' AS nh_nazev',
    ];

    $join = '';
    if ($nhFk !== null) {
        $join = "LEFT JOIN $nhTable AS $nhAlias ON $nhAlias.id = $alias." . sql_quote_ident($nhFk);
    } else {
        $join = "LEFT JOIN $nhTable AS $nhAlias ON 1=1";
    }

    $searchExpressions = [];
    if ($codeCol) {
        $searchExpressions[] = "LOWER($alias." . sql_quote_ident($codeCol) . ')';
    }
    if ($variantCol) {
        $searchExpressions[] = "LOWER($alias." . sql_quote_ident($variantCol) . ')';
    }
    if ($nhCodeCol) {
        $searchExpressions[] = "LOWER($alias." . sql_quote_ident($nhCodeCol) . ')';
    }
    $searchExpressions[] = "LOWER($nhAlias." . sql_quote_ident('cislo') . ')';
    if ($nameCol) {
        $searchExpressions[] = "LOWER($alias." . sql_quote_ident($nameCol) . ')';
    }
    $searchExpressions[] = "LOWER($nhAlias." . sql_quote_ident('nazev') . ')';

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
        if (!$searchExpressions) {
            break;
        }
        $param = ':t' . $idx++;
        $like = $term;
        $params[$param] = '%' . $like . '%';
        $where[] = '(' . implode(' OR ', array_map(static fn($expr) => "$expr LIKE $param", $searchExpressions)) . ')';
    }

    $sql = 'SELECT ' . implode(', ', $selectParts)
        . " FROM $table AS $alias $join"
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY '
        . ($nhCodeCol ? "$alias." . sql_quote_ident($nhCodeCol) : "$nhAlias." . sql_quote_ident('cislo'))
        . ', ' . ($variantCol ? "$alias." . sql_quote_ident($variantCol) : "$alias." . sql_quote_ident('id'))
        . ', ' . "$alias." . sql_quote_ident('id') . ' DESC'
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
            'cislo' => nh_vyr_first_value($row, ['shade_cislo']),
            'cislo_nh' => nh_vyr_first_value($row, ['shade_cislo_nh', 'nh_cislo']),
            'cislo_ods' => nh_vyr_first_value($row, ['shade_cislo_ods']),
            'nazev' => nh_vyr_first_value($row, ['shade_nazev', 'nh_nazev']),
        ];
    }

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[nh_vyroba][shades] ' . $e->getMessage());
    respond_json(
        ['error' => 'Chyba při načítání odstínů NH.'],
        500
    );
}
