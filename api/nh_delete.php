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
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'missing id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $check = $pdo->prepare('SELECT id FROM balp_nh WHERE id = :id AND dtod <= NOW() AND dtdo >= NOW()');
    $check->execute([':id' => $id]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $pdo->beginTransaction();
    $params = [':id' => $id];
    $queries = [
        'UPDATE balp_nh SET dtdo = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = :id AND dtod <= NOW() AND dtdo >= NOW()',
        'UPDATE balp_nhods SET dtdo = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idnh = :id AND dtod <= NOW() AND dtdo >= NOW()',
        'UPDATE balp_nhods_ceny SET dtdo = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idnhods IN (SELECT id FROM balp_nhods WHERE idnh = :id) AND dtod <= NOW() AND dtdo >= NOW()',
        'UPDATE balp_nhods_rec SET dtdo = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idnhods IN (SELECT id FROM balp_nhods WHERE idnh = :id) AND dtod <= NOW() AND dtdo >= NOW()',
        'UPDATE balp_nhods_vyr SET dtdo = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idnhods IN (SELECT id FROM balp_nhods WHERE idnh = :id) AND dtod <= NOW() AND dtdo >= NOW()',
        'UPDATE balp_nhods_vyr_rec SET dtdo = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idnhods IN (SELECT id FROM balp_nhods WHERE idnh = :id) AND dtod <= NOW() AND dtdo >= NOW()',
        'UPDATE balp_nhods_vyr_zk SET dtdo = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idnhods IN (SELECT id FROM balp_nhods WHERE idnh = :id) AND dtod <= NOW() AND dtdo >= NOW()',
    ];
    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
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
