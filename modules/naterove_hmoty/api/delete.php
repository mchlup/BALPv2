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

    $nhodsTableName = 'balp_nhods';
    $nhodsTableQuoted = sql_quote_ident($nhodsTableName);
    $tables = [
        [
            'name' => $nhTableName,
            'condition' => 'id = :id',
        ],
        [
            'name' => $nhodsTableName,
            'condition' => 'idnh = :id',
        ],
        [
            'name' => 'balp_nhods_ceny',
            'condition' => "idnhods IN (SELECT id FROM $nhodsTableQuoted WHERE idnh = :id)",
        ],
        [
            'name' => 'balp_nhods_rec',
            'condition' => "idnhods IN (SELECT id FROM $nhodsTableQuoted WHERE idnh = :id)",
        ],
        [
            'name' => 'balp_nhods_vyr',
            'condition' => "idnhods IN (SELECT id FROM $nhodsTableQuoted WHERE idnh = :id)",
        ],
        [
            'name' => 'balp_nhods_vyr_rec',
            'condition' => "idnhods IN (SELECT id FROM $nhodsTableQuoted WHERE idnh = :id)",
        ],
        [
            'name' => 'balp_nhods_vyr_zk',
            'condition' => "idnhods IN (SELECT id FROM $nhodsTableQuoted WHERE idnh = :id)",
        ],
    ];

    foreach ($tables as $tableInfo) {
        $tableName = $tableInfo['name'];
        $condition = $tableInfo['condition'];
        if (!$tableName || !$condition) {
            continue;
        }

        $tableQuoted = sql_quote_ident($tableName);
        $columns = balp_table_get_columns($pdo, $tableName);
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

        if (strpos($condition, ':id') !== false) {
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
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
