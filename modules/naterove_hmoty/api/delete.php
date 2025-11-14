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
    $nhTableName = balp_nh_table_name();
    $nhTable = sql_quote_ident($nhTableName);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        respond_json(['error' => 'missing id'], 400);
    }

    $check = $pdo->prepare("SELECT id FROM $nhTable WHERE id = :id");
    $check->execute([':id' => $id]);
    if (!$check->fetchColumn()) {
        respond_json(['error' => 'not found'], 404);
    }

    $now = (new DateTimeImmutable('now'))->format('Y-m-d');

    $pdo->beginTransaction();

    $tableExists = static function (PDO $pdo, string $table): bool {
        try {
            if (function_exists('balp_nh_table_exists')) {
                return balp_nh_table_exists($pdo, $table);
            }
            $pdo->query('SELECT 1 FROM ' . sql_quote_ident($table) . ' LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    };

    $resolveColumn = static function (array $columns, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (!isset($columns[$key])) {
                continue;
            }
            $definition = $columns[$key];
            $field = $definition['Field'] ?? null;
            if (is_string($field) && $field !== '') {
                return $field;
            }
            return $candidate;
        }
        return null;
    };

    $nhodsTableName = 'balp_nhods';
    $tables = [];

    $nhColumns = $tableExists($pdo, $nhTableName) ? balp_table_get_columns($pdo, $nhTableName) : [];
    $tables[] = [
        'name' => $nhTableName,
        'columns' => $nhColumns,
        'condition' => 'id = :id',
        'params' => [':id' => $id],
        'types' => [':id' => PDO::PARAM_INT],
    ];

    if ($tableExists($pdo, $nhodsTableName)) {
        $nhodsColumns = balp_table_get_columns($pdo, $nhodsTableName);
        $nhodsFkToNh = $resolveColumn($nhodsColumns, ['idnh', 'id_nh', 'idnhmaster', 'idmaster', 'id_nhmaster']);
        $nhodsIdColumn = $resolveColumn($nhodsColumns, ['id', 'idnhods', 'id_nhods', 'idnhod']);

        if ($nhodsColumns !== [] && $nhodsFkToNh) {
            $tables[] = [
                'name' => $nhodsTableName,
                'columns' => $nhodsColumns,
                'condition' => sql_quote_ident($nhodsFkToNh) . ' = :id',
                'params' => [':id' => $id],
                'types' => [':id' => PDO::PARAM_INT],
            ];
        }

        $shadeIds = [];
        if ($nhodsColumns !== [] && $nhodsFkToNh && $nhodsIdColumn) {
            $nhodsTableQuoted = sql_quote_ident($nhodsTableName);
            $selectSql = 'SELECT ' . sql_quote_ident($nhodsIdColumn) . ' FROM ' . $nhodsTableQuoted
                . ' WHERE ' . sql_quote_ident($nhodsFkToNh) . ' = :id';
            $selectStmt = $pdo->prepare($selectSql);
            $selectStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $selectStmt->execute();
            $rawIds = $selectStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($rawIds as $rawId) {
                $value = is_int($rawId) ? $rawId : (int)$rawId;
                if ($value > 0) {
                    $shadeIds[] = $value;
                }
            }
        }

        $shadeRelatedTables = [
            'balp_nhods_ceny' => ['idnhods', 'id_nhods', 'idnhod'],
            'balp_nhods_rec' => ['idnhods', 'id_nhods', 'idnhod'],
            'balp_nhods_vyr' => ['idnhods', 'id_nhods', 'idnhod'],
            'balp_nhods_vyr_rec' => ['idnhods', 'id_nhods', 'idnhod'],
            'balp_nhods_vyr_zk' => ['idnhods', 'id_nhods', 'idnhod'],
        ];

        foreach ($shadeRelatedTables as $tableName => $candidateColumns) {
            if (!$shadeIds) {
                break;
            }
            if (!$tableExists($pdo, $tableName)) {
                continue;
            }
            $columns = balp_table_get_columns($pdo, $tableName);
            if ($columns === []) {
                continue;
            }
            $fkColumn = $resolveColumn($columns, $candidateColumns);
            if (!$fkColumn) {
                continue;
            }
            $params = [];
            $types = [];
            $placeholders = [];
            $sanitized = preg_replace('/[^A-Za-z0-9_]+/', '_', $tableName);
            foreach (array_values(array_unique($shadeIds)) as $idx => $shadeId) {
                $placeholder = ':' . $sanitized . '_shade_' . $idx;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $shadeId;
                $types[$placeholder] = PDO::PARAM_INT;
            }
            if (!$placeholders) {
                continue;
            }
            $tables[] = [
                'name' => $tableName,
                'columns' => $columns,
                'condition' => sql_quote_ident($fkColumn) . ' IN (' . implode(', ', $placeholders) . ')',
                'params' => $params,
                'types' => $types,
            ];
        }
    }

    foreach ($tables as $tableInfo) {
        $tableName = $tableInfo['name'];
        $condition = $tableInfo['condition'];
        $columns = $tableInfo['columns'] ?? null;
        if (!$tableName || !$condition) {
            continue;
        }

        $tableQuoted = sql_quote_ident($tableName);
        if ($columns === null || $columns === []) {
            $columns = balp_table_get_columns($pdo, $tableName);
        }
        $hasDtod = isset($columns['dtod']);
        $hasDtdo = isset($columns['dtdo']);

        if (!$hasDtod && !$hasDtdo) {
            $sql = "DELETE FROM $tableQuoted WHERE $condition";
            $stmt = $pdo->prepare($sql);
        } else {
            $setParts = [];
            if ($hasDtod) {
                $setParts[] = "dtod = CASE WHEN dtod IS NULL OR dtod > :now THEN :now ELSE dtod END";
            }
            if ($hasDtdo) {
                $setParts[] = 'dtdo = :now';
            }

            if (!$setParts) {
                continue;
            }

            $whereParts = [$condition];
            if ($hasDtod) {
                $whereParts[] = '(dtod IS NULL OR dtod <= :now)';
            }
            if ($hasDtdo) {
                $whereParts[] = '(dtdo IS NULL OR dtdo >= :now)';
            }

            $sql = "UPDATE $tableQuoted
                SET " . implode(",\n                ", $setParts) . "
                WHERE " . implode("\n                AND ", $whereParts);
            $stmt = $pdo->prepare($sql);
            if ($hasDtod || $hasDtdo) {
                $stmt->bindValue(':now', $now);
            }
        }

        $params = $tableInfo['params'] ?? [];
        $types = $tableInfo['types'] ?? [];
        foreach ($params as $param => $value) {
            $type = $types[$param] ?? (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $stmt->bindValue($param, $value, $type);
        }

        $stmt->execute();
    }

    $pdo->commit();

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
