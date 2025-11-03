<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../modules/bootstrap.php';

balp_require_authenticated_user();

$input = request_json();
$content = $input['content'] ?? ($input['data'] ?? '');
if (!is_string($content) || $content === '') {
    respond_json(['error' => 'Chybí data zálohy.'], 400);
}

$decoded = base64_decode($content, true);
if ($decoded === false) {
    $decoded = $content;
}

$payload = json_decode($decoded, true);
if (!is_array($payload)) {
    respond_json(['error' => 'Záloha má neplatný formát.'], 400);
}

$tables = $payload['tables'] ?? [];
if (!is_array($tables)) {
    respond_json(['error' => 'Záloha neobsahuje tabulky.'], 400);
}

$pdo = db();
$restoredTables = 0;
$restoredRows = 0;

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        if (!is_array($table)) {
            continue;
        }
        $name = $table['name'] ?? null;
        $create = $table['create'] ?? '';
        $rows = $table['rows'] ?? [];
        if (!$name || !is_string($name)) {
            continue;
        }
        $quoted = sql_quote_ident($name);
        try {
            $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
            if (is_string($create) && $create !== '') {
                $pdo->exec($create);
            }
        } catch (Throwable $e) {
            respond_json(['error' => sprintf('Selhalo obnovení tabulky %s (definice).', $name), 'detail' => $e->getMessage()], 500);
        }

        if (!is_array($rows) || !$rows) {
            $restoredTables++;
            continue;
        }

        $first = $rows[0];
        if (!is_array($first)) {
            $restoredTables++;
            continue;
        }
        $columns = array_keys($first);
        $quotedColumns = array_map('sql_quote_ident', $columns);
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $quoted, implode(',', $quotedColumns), $placeholders);
        $stmt = $pdo->prepare($sql);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $stmt->execute($values);
            $restoredRows++;
        }
        $restoredTables++;
    }
} catch (Throwable $e) {
    respond_json(['error' => 'Obnova databáze selhala.', 'detail' => $e->getMessage()], 500);
} finally {
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $e) {
        // ignore
    }
}

respond_json([
    'ok' => true,
    'tables' => $restoredTables,
    'rows' => $restoredRows,
]);
