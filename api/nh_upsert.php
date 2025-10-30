<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
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
    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $pdo = db();
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        $in = $_POST;
    }
    if (!is_array($in)) {
        $in = [];
    }
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $table = 'balp_nh';

    $colsStmt = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $colsStmt->execute([':t' => $table]);
    $columns = [];
    while ($r = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$r['COLUMN_NAME']] = [
            'type' => strtolower((string)$r['DATA_TYPE']),
            'nullable' => ($r['IS_NULLABLE'] === 'YES')
        ];
    }

    $sanitize = function ($val, $info) {
        $type = $info['type'] ?? 'varchar';
        $nullable = $info['nullable'] ?? true;
        if ($val === null || $val === '') {
            if ($nullable) return null;
            switch ($type) {
                case 'int':
                case 'bigint':
                case 'smallint':
                case 'tinyint':
                case 'mediumint':
                case 'decimal':
                case 'double':
                case 'float':
                case 'real':
                case 'numeric':
                    return 0;
                default:
                    return '';
            }
        }
        switch ($type) {
            case 'int':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
            case 'mediumint':
                return (int)$val;
            case 'decimal':
            case 'double':
            case 'float':
            case 'real':
            case 'numeric':
                return is_numeric($val) ? (float)$val : 0;
            default:
                return (string)$val;
        }
    };

    $pdo->beginTransaction();
    if ($id > 0) {
        $setParts = [];
        $params = [':id' => $id];
        foreach ($in as $key => $value) {
            if ($key === 'id' || !isset($columns[$key])) continue;
            $sanitized = $sanitize($value, $columns[$key]);
            $placeholder = ':' . $key;
            $setParts[] = '`' . str_replace('`', '``', $key) . "` = $placeholder";
            $params[$placeholder] = $sanitized;
        }
        if ($setParts) {
            $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $setParts) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            foreach ($params as $p => $v) {
                if ($p === ':id') {
                    $stmt->bindValue($p, (int)$v, PDO::PARAM_INT);
                } elseif ($v === null) {
                    $stmt->bindValue($p, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue($p, $v);
                }
            }
            $stmt->execute();
        }
    } else {
        $cols = [];
        $placeholders = [];
        $params = [];
        foreach ($in as $key => $value) {
            if ($key === 'id' || !isset($columns[$key])) continue;
            $sanitized = $sanitize($value, $columns[$key]);
            $cols[] = '`' . str_replace('`', '``', $key) . '`';
            $placeholder = ':' . $key;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $sanitized;
        }
        if ($cols) {
            $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            foreach ($params as $p => $v) {
                if ($v === null) {
                    $stmt->bindValue($p, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue($p, $v);
                }
            }
            $stmt->execute();
        } else {
            throw new Exception('No data to insert');
        }
    }
    $pdo->commit();
    echo json_encode(new stdClass());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
