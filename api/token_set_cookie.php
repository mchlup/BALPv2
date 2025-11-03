<?php
require_once __DIR__ . '/../helpers.php';

$token = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  if (is_string($raw) && $raw !== '') {
    $data = json_decode($raw, true);
    if (is_array($data) && !empty($data['token'])) {
      $token = (string)$data['token'];
    }
  }
  if (!$token && isset($_POST['token'])) {
    $token = (string)$_POST['token'];
  }
}

if (!$token) {
  $token = get_bearer_token();
}

if (!$token) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'Missing token'], JSON_UNESCAPED_UNICODE);
  exit;
}

setcookie('balp_token', $token, [
  'expires' => time() + 3600 * 8,
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Lax',
]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
