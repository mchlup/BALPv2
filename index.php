<?php
$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    header('Location: installer.php');
    exit;
}

require __DIR__ . '/helpers.php';
$cfg = cfg();

$target = 'public/app.html';
if (!empty($cfg['app_url'])) {
    $base = rtrim((string)$cfg['app_url'], '/');
    if ($base !== '' && stripos($base, 'http') === 0) {
        $target = $base . '/public/app.html';
    }
}

header('Location: ' . $target);
exit;
