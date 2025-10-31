<?php
// /balp2/api/pol_delete2.php
// Soft-delete: nastaví dtdo na NOW()-1 pro pol i aktivní řádky.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');

$config_file = balp_project_root() . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) { require $config_file; }

try {
  $JWT_SECRET = $CONFIG['auth']['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token();
  if (!$token) { http_response_code(401); echo json_encode(['error'=>'missing token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
  jwt_decode($token, $JWT_SECRET, true);

  $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
$db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
$db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
if (!$db_dsn) throw new Exception('DB DSN missing');
$pdo = new PDO($db_dsn, $db_user, $db_pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
]);


  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'missing id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  $pdo->beginTransaction();
  $pdo->prepare("UPDATE balp_pol SET dtdo=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id=:id AND dtod<=NOW() AND dtdo>=NOW()")->execute([':id'=>$id]);
  $pdo->prepare("UPDATE balp_pol_rec SET dtdo=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idpolfin=:id AND dtod<=NOW() AND dtdo>=NOW()")->execute([':id'=>$id]);
  $pdo->commit();

  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
