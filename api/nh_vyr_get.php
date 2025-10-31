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

    $detail = nh_vyr_fetch_detail($pdo, $id);
    $row = $detail['item'] ?? null;
    if (!$row) {
        respond_json(['error' => 'not found'], 404);
    }

    $row['cislo_vp'] = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? null);
    if (isset($row['datum_vyroby']) && $row['datum_vyroby'] !== null) {
        $row['datum_vyroby'] = substr((string)$row['datum_vyroby'], 0, 10);
    }

    $lines = $detail['rows'] ?? [];
    foreach ($lines as &$line) {
        if (isset($line['mnozstvi']) && $line['mnozstvi'] !== null) {
            $line['mnozstvi'] = is_numeric($line['mnozstvi']) ? (float)$line['mnozstvi'] : $line['mnozstvi'];
        }
    }
    unset($line);

    $tests = $detail['zkousky'] ?? [];

    echo json_encode([
        'item' => $row,
        'rows' => $lines,
        'zkousky' => $tests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
