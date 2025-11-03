<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../modules/bootstrap.php';

balp_require_authenticated_user();

$input = request_json();
$config = balp_normalize_config(cfg());

if (isset($input['db']) && is_array($input['db'])) {
    $config['db'] = array_merge($config['db'], $input['db']);
}

if (isset($input['db_dsn'])) {
    $config['db_dsn'] = (string)$input['db_dsn'];
}
if (isset($input['db_user'])) {
    $config['db_user'] = (string)$input['db_user'];
}
if (isset($input['db_pass'])) {
    $config['db_pass'] = (string)$input['db_pass'];
}

$config = balp_normalize_config($config);
$err = null;
$pdo = db_try_connect($config, $err);

if (!$pdo) {
    respond_json(['ok' => false, 'error' => $err ?: 'Neznámá chyba při připojení.']);
}

try {
    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable $e) {
    $serverVersion = null;
}

respond_json([
    'ok' => true,
    'message' => 'Spojení s databází bylo úspěšné.',
    'server_version' => $serverVersion,
]);
