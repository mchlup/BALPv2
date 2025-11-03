<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../modules/bootstrap.php';

balp_require_authenticated_user();

$input = request_json();
if (!is_array($input)) {
    respond_json(['error' => 'Neplatný formát požadavku.'], 400);
}

$payload = $input['config'] ?? $input;
if (!is_array($payload)) {
    respond_json(['error' => 'Chybí data konfigurace.'], 400);
}

$current = balp_normalize_config(cfg());
$newConfig = $current;

if (array_key_exists('app_url', $payload)) {
    $newConfig['app_url'] = trim((string)$payload['app_url']);
}

if (isset($payload['auth']) && is_array($payload['auth'])) {
    $auth = $newConfig['auth'] ?? [];
    $allowed = ['enabled', 'user_table', 'username_field', 'password_field', 'role_field', 'password_algo', 'login_scheme', 'jwt_secret', 'jwt_ttl_minutes'];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $payload['auth'])) {
            $value = $payload['auth'][$key];
            if ($key === 'enabled') {
                $auth[$key] = (bool)$value;
            } elseif ($key === 'jwt_ttl_minutes') {
                $auth[$key] = (int)$value;
            } elseif ($key === 'role_field') {
                $auth[$key] = ($value === null || $value === '') ? null : (string)$value;
            } else {
                $auth[$key] = (string)$value;
            }
        }
    }
    $newConfig['auth'] = $auth;
}

if (isset($payload['tables']) && is_array($payload['tables'])) {
    $tables = [];
    foreach ($payload['tables'] as $key => $value) {
        $normalizedKey = trim((string)$key);
        if ($normalizedKey === '') {
            continue;
        }
        $tables[$normalizedKey] = trim((string)$value);
    }
    $newConfig['tables'] = $tables;
}

if (isset($payload['db']) && is_array($payload['db'])) {
    $db = $newConfig['db'] ?? [];
    $allowedDbKeys = ['driver', 'host', 'port', 'database', 'username', 'password', 'charset', 'collation'];
    foreach ($allowedDbKeys as $key) {
        if (array_key_exists($key, $payload['db'])) {
            $value = $payload['db'][$key];
            if ($key === 'port') {
                $db[$key] = (int)$value;
            } else {
                $db[$key] = (string)$value;
            }
        }
    }
    $newConfig['db'] = $db;
}

if (array_key_exists('db_dsn', $payload)) {
    $newConfig['db_dsn'] = (string)$payload['db_dsn'];
}
if (array_key_exists('db_user', $payload)) {
    $newConfig['db_user'] = (string)$payload['db_user'];
}
if (array_key_exists('db_pass', $payload)) {
    $newConfig['db_pass'] = (string)$payload['db_pass'];
}

try {
    $saved = balp_write_config($newConfig);
} catch (Throwable $e) {
    respond_json(['error' => 'Uložení konfigurace selhalo.', 'detail' => $e->getMessage()], 500);
}

respond_json([
    'ok' => true,
    'config' => $saved,
]);
