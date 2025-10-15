<?php
// /balp2/api/auth_me.php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';

// Načti config
$config_file = dirname(__DIR__) . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) {
    require $config_file;
}
$A = $CONFIG['auth'] ?? [];

// JWT secret a TTL bereme ze stejného místa jako login
$JWT_SECRET = $A['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));

$token = balp_get_bearer_token();
if (!$token) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'missing token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $payload = jwt_decode($token, $JWT_SECRET, true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'user' => [
            'username' => $payload['sub'] ?? null,
            'role' => $payload['role'] ?? null,
            'iat' => $payload['iat'] ?? null,
            'exp' => $payload['exp'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Exception $e) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

