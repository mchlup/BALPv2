<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../modules/bootstrap.php';

balp_require_authenticated_user();

$pdo = db();
$config = balp_normalize_config(cfg());
$database = $config['db']['database'] ?? '';

try {
    $tablesStmt = $pdo->query('SHOW TABLES');
    $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_NUM) : [];
} catch (Throwable $e) {
    respond_json(['error' => 'Nepodařilo se načíst tabulky.', 'detail' => $e->getMessage()], 500);
}

$results = [];

foreach ($tables as $row) {
    $tableName = $row[0] ?? null;
    if (!$tableName) {
        continue;
    }
    $quoted = sql_quote_ident($tableName);
    try {
        $optStmt = $pdo->query("OPTIMIZE TABLE {$quoted}");
        $optData = $optStmt ? $optStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $messages = [];
        foreach ($optData as $optRow) {
            $messages[] = $optRow['Msg_text'] ?? '';
        }
        $results[] = [
            'table' => $tableName,
            'status' => 'ok',
            'message' => implode('; ', array_filter($messages)),
        ];
    } catch (Throwable $e) {
        $results[] = [
            'table' => $tableName,
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

respond_json([
    'ok' => true,
    'database' => $database,
    'results' => $results,
]);
