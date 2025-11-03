<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../modules/bootstrap.php';

balp_require_authenticated_user();

$config = balp_normalize_config(cfg());
$err = null;
$pdo = db_try_connect($config, $err);
if (!$pdo) {
    respond_json(['error' => 'Nepodařilo se připojit k databázi.', 'detail' => $err], 500);
}

$database = $config['db']['database'] ?? '';

try {
    $tablesStmt = $pdo->query('SHOW TABLES');
    $tablesRaw = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_NUM) : [];
} catch (Throwable $e) {
    respond_json(['error' => 'Nepodařilo se načíst seznam tabulek.', 'detail' => $e->getMessage()], 500);
}

$tables = [];
$totalRows = 0;

foreach ($tablesRaw as $row) {
    $tableName = $row[0] ?? null;
    if (!$tableName) {
        continue;
    }
    $quoted = sql_quote_ident($tableName);
    try {
        $createStmt = $pdo->query("SHOW CREATE TABLE {$quoted}");
        $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : null;
        $createSql = $createRow['Create Table'] ?? '';

        $dataStmt = $pdo->query("SELECT * FROM {$quoted}");
        $rows = $dataStmt ? $dataStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $totalRows += is_array($rows) ? count($rows) : 0;
    } catch (Throwable $e) {
        respond_json(['error' => sprintf('Chyba při exportu tabulky %s', $tableName), 'detail' => $e->getMessage()], 500);
    }

    $tables[] = [
        'name' => $tableName,
        'create' => $createSql,
        'rows' => $rows,
    ];
}

$meta = [
    'database' => $database,
    'generated_at' => date('c'),
    'table_count' => count($tables),
    'row_count' => $totalRows,
];

$payload = [
    'meta' => $meta,
    'tables' => $tables,
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    respond_json(['error' => 'Nepodařilo se serializovat zálohu.'], 500);
}

$fileName = ($database ?: 'databaze') . '-backup-' . date('Ymd-His') . '.json';

respond_json([
    'ok' => true,
    'file' => [
        'name' => $fileName,
        'mime' => 'application/json',
        'content' => base64_encode($json),
    ],
    'meta' => $meta,
]);
