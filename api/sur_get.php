<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
header('Content-Type: application/json; charset=utf-8');

$config_file = dirname(__DIR__) . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) require $config_file;
$A = $CONFIG['auth'] ?? [];
$JWT_SECRET = $A['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
$token = balp_get_bearer_token();
if (!$token) { http_response_code(401); echo json_encode(['error'=>'missing token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
try { jwt_decode($token, $JWT_SECRET, true); } catch (Exception $e) { http_response_code(401); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
  $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
  $db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
  $db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
  if (!$db_dsn) throw new Exception('DB DSN missing');
  $pdo = new PDO($db_dsn, $db_user, $db_pass, [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'missing id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$sql = "SELECT id, cislo, nazev, sh, sus_sh, sus_hmot, sus_obj, okp, olej, pozn, dtod, dtdo FROM balp_sur WHERE id=:id LIMIT 1";
$stmt = $pdo->prepare($sql); $stmt->execute([':id'=>$id]); $row = $stmt->fetch();
if (!$row) { http_response_code(404); echo json_encode(['error'=>'not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
echo json_encode(['item'=>$row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
