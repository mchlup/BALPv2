<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';
require_once __DIR__ . '/nh_vyr_helpers.php';

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

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        respond_json(['error' => 'missing id'], 400);
    }

    $pdo = db();

    $table = sql_quote_ident('balp_nhods_vyr');
    $nhTable = sql_quote_ident(balp_nh_table_name());

    $sql = "SELECT v.*, nh.cislo AS cislo_nh, nh.nazev AS nazev_nh
            FROM $table AS v
            LEFT JOIN $nhTable AS nh ON nh.id = v.idnh
            WHERE v.id = :id LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        respond_json(['error' => 'not found'], 404);
    }

    $row['cislo_vp'] = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? null);
    if (isset($row['datum_vyroby']) && $row['datum_vyroby'] !== null) {
        $row['datum_vyroby'] = substr((string)$row['datum_vyroby'], 0, 10);
    }

    echo json_encode([
        'item' => $row,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
