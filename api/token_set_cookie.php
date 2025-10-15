<?php
$token = $_GET['token'] ?? '';
if (!$token) { http_response_code(400); echo "Missing token"; exit; }
// httpOnly cookie (client JS neuvidí), fallback pro reverse proxy, SameSite=Lax
setcookie('balp_token', $token, [
  'expires' => time() + 3600*8,
  'path' => '/',
  'secure' => isset($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Lax',
]);
header('Content-Type: text/plain; charset=utf-8');
echo "OK";
