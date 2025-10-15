<?php
// api/auth_helpers.php
// Robustly extract Bearer token from Authorization header, HTTP_AUTHORIZATION (FPM/nginx),
// cookie 'balp_token', or GET param 'token'.

if (!function_exists('balp_getallheaders')) {
  function balp_getallheaders() {
    if (function_exists('getallheaders')) return getallheaders();
    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (substr($name, 0, 5) == 'HTTP_') {
        $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
        $headers[$key] = $value;
      }
    }
    return $headers;
  }
}

function balp_get_bearer_token() {
  $headers = balp_getallheaders();
  if (!empty($headers['Authorization'])) {
    $auth = $headers['Authorization'];
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
  }
  if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
  }
  if (!empty($_COOKIE['balp_token'])) return $_COOKIE['balp_token'];
  if (!empty($_GET['token'])) return $_GET['token'];
  return '';
}

function balp_send_json($data, $status=200) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
