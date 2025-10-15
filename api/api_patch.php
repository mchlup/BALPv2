<?php
// api/api_patch.php
// Include this file near the top of your api.php to wire auth actions.

require_once __DIR__ . '/auth_helpers.php';

$action = $_GET['action'] ?? '';

if ($action === 'auth_me') {
  require __DIR__ . '/auth_me.php';
  exit;
}

if ($action === 'auth_login') {
  require __DIR__ . '/auth_login_ref.php';
  exit;
}

// Example usage inside endpoints that require auth:
// $token = balp_get_bearer_token();
// if (!$token) balp_send_json(['error'=>'missing token'], 401);
// require_once __DIR__ . '/jwt_helper.php';
// try {
//   $payload = jwt_decode($token, $JWT_SECRET);
//   $current_user = $payload['sub'] ?? null;
// } catch (Exception $e) {
//   balp_send_json(['error'=>$e->getMessage()], 401);
// }
