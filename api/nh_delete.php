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

    $nhodsTable = sql_quote_ident('balp_nhods');
    $tables = [
        $nhTable => 'id = :id',
        $nhodsTable => 'idnh = :id',
        sql_quote_ident('balp_nhods_ceny') => "idnhods IN (SELECT id FROM $nhodsTable WHERE idnh = :id)",
        sql_quote_ident('balp_nhods_rec') => "idnhods IN (SELECT id FROM $nhodsTable WHERE idnh = :id)",
        sql_quote_ident('balp_nhods_vyr') => "idnhods IN (SELECT id FROM $nhodsTable WHERE idnh = :id)",
        sql_quote_ident('balp_nhods_vyr_rec') => "idnhods IN (SELECT id FROM $nhodsTable WHERE idnh = :id)",
        sql_quote_ident('balp_nhods_vyr_zk') => "idnhods IN (SELECT id FROM $nhodsTable WHERE idnh = :id)",
    ];

    foreach ($tables as $table => $condition) {
        $sql = "UPDATE $table SET dtdo = :now WHERE $condition AND dtod < :now AND dtdo > :now";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
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
