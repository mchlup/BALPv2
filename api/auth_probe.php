<?php
require_once __DIR__ . '/auth_helpers.php';
header('Content-Type: application/json; charset=utf-8');

$headers = [];
foreach (balp_getallheaders() as $k => $v) { $headers[$k] = $v; }

echo json_encode([
  'php_sapi' => PHP_SAPI,
  'server' => [
    'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
  ],
  'headers' => $headers,
  'cookies' => $_COOKIE,
  'get' => $_GET,
  'detected_token' => balp_get_bearer_token(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
