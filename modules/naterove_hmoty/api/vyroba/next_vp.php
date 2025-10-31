<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('naterove_hmoty', 'vyroba_helpers');

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

    $now = new DateTimeImmutable('now');
    $result = nh_vyr_next_vp_formatted($pdo, $now);
    if ($result === null) {
        respond_json([
            'cislo_vp_digits' => null,
            'cislo_vp' => null,
            'year_prefix' => $now->format('y'),
        ]);
    }

    respond_json([
        'cislo_vp_digits' => $result['digits'] ?? null,
        'cislo_vp' => $result['formatted'] ?? null,
        'year_prefix' => $now->format('y'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
