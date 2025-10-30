<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

$config = cfg();
$authConf = $config['auth'] ?? [];

if (!($authConf['enabled'] ?? false)) {
    http_response_code(403);
    echo json_encode(['error' => 'Auth disabled'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$token = balp_get_bearer_token();
if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'missing token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$pdo = db();

$tableParam = $_GET['table'] ?? 'balp_nh';
$allowedTables = [
    'balp_nh',
    'balp_nhods',
    'balp_nhods_ceny',
    'balp_nhods_rec',
    'balp_nhods_vyr',
    'balp_nhods_vyr_rec',
    'balp_nhods_vyr_zk',
];

$table = null;
foreach ($allowedTables as $candidate) {
    if (strcasecmp($candidate, $tableParam) === 0) {
        $table = $candidate;
        break;
    }
}
if (!$table) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported table'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$schema = $config['db']['database'] ?? null;
if (!$schema) {
    http_response_code(500);
    echo json_encode(['error' => 'Database schema missing in configuration'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$columnsStmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table");
$columnsStmt->execute([':schema' => $schema, ':table' => $table]);
$columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$columns) {
    http_response_code(404);
    echo json_encode(['error' => 'Table not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$columnNames = array_column($columns, 'COLUMN_NAME');

$detect = function(array $candidates) use ($columnNames) {
    foreach ($candidates as $cand) {
        foreach ($columnNames as $col) {
            if (strcasecmp($col, $cand) === 0) {
                return $col;
            }
        }
    }
    foreach ($columnNames as $col) {
        foreach ($candidates as $cand) {
            if (stripos($col, $cand) !== false) {
                return $col;
            }
        }
    }
    return null;
};

$idColumn = $detect(['id', 'id_nh', 'nh_id', 'idnh', 'idpol']);
$codeColumn = $detect(['kod', 'code', 'cislo', 'oznaceni', 'cis']);
$nameColumn = $detect(['nazev', 'name', 'popis', 'description', 'oznaceni']);
$categoryColumn = $detect(['kategorie_id', 'kategorie', 'category', 'kat']);

$textColumns = [];
foreach ($columns as $col) {
    $type = strtolower($col['DATA_TYPE'] ?? '');
    if (in_array($type, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'])) {
        $textColumns[] = $col['COLUMN_NAME'];
    }
}

$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$q = trim((string)($_GET['q'] ?? ''));
$categoryFilter = $_GET['category'] ?? $_GET['kategorie'] ?? null;

$whereParts = [];
$params = [];
if ($q !== '' && $textColumns) {
    $searchColumns = [];
    if ($codeColumn && in_array($codeColumn, $textColumns, true)) $searchColumns[] = $codeColumn;
    if ($nameColumn && in_array($nameColumn, $textColumns, true)) $searchColumns[] = $nameColumn;
    if (!$searchColumns) $searchColumns = $textColumns;
    $likeParts = [];
    foreach ($searchColumns as $idx => $col) {
        $param = ':q' . $idx;
        $likeParts[] = '`' . str_replace('`', '``', $col) . "` LIKE $param";
        $params[$param] = '%' . $q . '%';
    }
    if ($likeParts) {
        $whereParts[] = '(' . implode(' OR ', $likeParts) . ')';
    }
}

if ($categoryFilter !== null && $categoryColumn) {
    $whereParts[] = '`' . str_replace('`', '``', $categoryColumn) . '` = :category';
    $params[':category'] = $categoryFilter;
}

$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
$orderColumn = $nameColumn ?? $codeColumn ?? $idColumn ?? $columnNames[0];
$orderSql = '`' . str_replace('`', '``', $orderColumn) . '`';

$countSql = 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '` ' . $whereSql;
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$sql = 'SELECT * FROM `' . str_replace('`', '``', $table) . '` ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    if ($idColumn && !array_key_exists('id', $row) && array_key_exists($idColumn, $row)) {
        $row['id'] = $row[$idColumn];
    }
    if ($codeColumn && !array_key_exists('kod', $row) && array_key_exists($codeColumn, $row)) {
        $row['kod'] = $row[$codeColumn];
    }
    if ($codeColumn && !array_key_exists('code', $row) && array_key_exists($codeColumn, $row)) {
        $row['code'] = $row[$codeColumn];
    }
    if ($nameColumn && !array_key_exists('nazev', $row) && array_key_exists($nameColumn, $row)) {
        $row['nazev'] = $row[$nameColumn];
    }
    if ($nameColumn && !array_key_exists('name', $row) && array_key_exists($nameColumn, $row)) {
        $row['name'] = $row[$nameColumn];
    }
    if ($categoryColumn && !array_key_exists('kategorie_id', $row) && array_key_exists($categoryColumn, $row)) {
        $row['kategorie_id'] = $row[$categoryColumn];
    }
    if ($categoryColumn && !array_key_exists('kategorie', $row) && array_key_exists($categoryColumn, $row)) {
        $row['kategorie'] = $row[$categoryColumn];
    }
}
unset($row);

echo json_encode([
    'table' => $table,
    'limit' => $limit,
    'offset' => $offset,
    'total' => $total,
    'items' => $rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
