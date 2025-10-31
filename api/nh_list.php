<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';

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
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $nhAlias = 'nh';
    $vtExpr = balp_nh_vp_expression($pdo, $nhAlias);
    $hasCisloVt = strtoupper($vtExpr) !== 'NULL';

    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = trim((string)($_GET['q'] ?? ''));
    $sortCol = strtolower((string)($_GET['sort_col'] ?? 'cislo'));
    $sortDir = strtoupper((string)($_GET['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $idCol = "$nhAlias." . sql_quote_ident('id');
    $cisloCol = "$nhAlias." . sql_quote_ident('cislo');
    $nazevCol = "$nhAlias." . sql_quote_ident('nazev');
    $poznCol = "$nhAlias." . sql_quote_ident('pozn');
    $dtodCol = "$nhAlias." . sql_quote_ident('dtod');
    $dtdoCol = "$nhAlias." . sql_quote_ident('dtdo');
    $katCol = "$nhAlias." . sql_quote_ident('kategorie_id');

    $sortColumns = [
        'id' => $idCol,
        'cislo' => $cisloCol,
        'nazev' => $nazevCol,
        'pozn' => $poznCol,
        'dtod' => $dtodCol,
        'dtdo' => $dtdoCol,
    ];
    if (balp_nh_has_column($pdo, 'kategorie_id')) {
        $sortColumns['kategorie_id'] = $katCol;
    }
    if ($hasCisloVt) {
        $sortColumns['cislo_vt'] = sql_quote_ident('cislo_vt');
    }
    if (!isset($sortColumns[$sortCol])) {
        $sortCol = 'cislo';
    }
    $orderBy = $sortColumns[$sortCol];

    $codeFrom = $_GET['cislo_od'] ?? $_GET['od'] ?? null;
    $codeTo   = $_GET['cislo_do'] ?? $_GET['do'] ?? null;
    $active   = $_GET['active'] ?? $_GET['platne'] ?? '1';

    $normalizeCode = static function ($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $upper = mb_strtoupper($value, 'UTF-8');
        if (preg_match('/^-?\d+$/', $upper)) {
            $isNegative = strpos($upper, '-') === 0;
            $digits = ltrim($upper, '-');
            $padded = str_pad($digits, 12, '0', STR_PAD_LEFT);
            return $isNegative ? '-' . $padded : $padded;
        }
        return $upper;
    };

    $codeFrom = $normalizeCode($codeFrom);
    $codeTo   = $normalizeCode($codeTo);

    $where = [];
    $params = [];

    if ($search !== '') {
        $params[':search'] = '%' . $search . '%';
        $searchParts = [
            "$cisloCol LIKE :search",
            "$nazevCol LIKE :search",
            "$poznCol LIKE :search",
        ];
        if ($hasCisloVt) {
            $searchParts[] = '(' . $vtExpr . ') LIKE :search';
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    if ($codeFrom !== null) {
        $params[':cislo_od'] = $codeFrom;
        $where[] = "$cisloCol >= :cislo_od";
    }

    if ($codeTo !== null) {
        $params[':cislo_do'] = $codeTo;
        $where[] = "$cisloCol <= :cislo_do";
    }

    if ($active !== null && $active !== '') {
        $activeNorm = strtolower(trim((string)$active));
        $activeCond = "(($dtodCol IS NULL OR $dtodCol <= NOW()) AND ($dtdoCol IS NULL OR $dtdoCol > NOW()))";
        if (in_array($activeNorm, ['1', 'true', 'yes', 'ano'], true)) {
            $where[] = $activeCond;
        } elseif (in_array($activeNorm, ['0', 'false', 'no', 'ne'], true)) {
            $where[] = 'NOT ' . $activeCond;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $nhTable AS $nhAlias $whereSql");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $vtSelect = $hasCisloVt
        ? '(' . $vtExpr . ') AS ' . sql_quote_ident('cislo_vt')
        : 'NULL AS ' . sql_quote_ident('cislo_vt');
    $sql = 'SELECT '
        . "$idCol AS id, "
        . "$cisloCol AS cislo, "
        . "$vtSelect, "
        . "$nazevCol AS nazev, "
        . "$poznCol AS pozn, "
        . "$dtodCol AS dtod, "
        . "$dtdoCol AS dtdo, "
        . "NULL AS kategorie_id FROM $nhTable AS $nhAlias $whereSql ORDER BY $orderBy $sortDir LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['kod'] = $row['cislo'];
        $row['name'] = $row['nazev'];
        if (!array_key_exists('cislo_vt', $row)) {
            $row['cislo_vt'] = null;
        }
        if (!array_key_exists('cislo_vp', $row)) {
            $row['cislo_vp'] = $row['cislo_vt'];
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
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
