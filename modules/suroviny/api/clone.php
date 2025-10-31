<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');

$config_file = balp_project_root() . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) require $config_file;
$A = $CONFIG['auth'] ?? [];
$JWT_SECRET = $A['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
$token = balp_get_bearer_token();
if (!$token) { http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'missing token']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
try { jwt_decode($token, $JWT_SECRET, true); } catch (Exception $e) { http_response_code(401); echo json_encode(balp_to_utf8(['error'=>$e->getMessage()]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
  $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
  $db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
  $db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
  if (!$db_dsn) throw new Exception('DB DSN missing');
  $pdo = new PDO($db_dsn, $db_user, $db_pass, balp_utf8_pdo_options());
} catch (Exception $e) { http_response_code(500); echo json_encode(balp_to_utf8(['error'=>$e->getMessage()]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(balp_to_utf8(['error'=>'missing id']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$stmt = $pdo->prepare("SELECT id, cislo, nazev, sh, sus_sh, sus_hmot, okp, olej, pozn, dtod, dtdo FROM balp_sur WHERE id=:id");
$stmt->execute([':id'=>$id]);
$src = $stmt->fetch();
if (!$src) { http_response_code(404); echo json_encode(balp_to_utf8(['error'=>'not found']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$cislo = null;
$nazev = ($src['nazev'] ?? '') . ' (kopie)';
$sql = "INSERT INTO balp_sur (cislo, nazev, sh, sus_sh, sus_hmot, okp, olej, pozn, dtod, dtdo)
        VALUES (:cislo, :nazev, :sh, :sus_sh, :sus_hmot, :okp, :olej, :pozn, :dtod, :dtdo)";
$stmt = $pdo->prepare($sql);
$ok = $stmt->execute([
  ':cislo'=>$cislo, ':nazev'=>$nazev, ':sh'=>$src['sh'], ':sus_sh'=>$src['sus_sh'],
  ':sus_hmot'=>$src['sus_hmot'], ':okp'=>$src['okp'],
  ':olej'=>$src['olej'], ':pozn'=>$src['pozn'], ':dtod'=>$src['dtod'], ':dtdo'=>$src['dtdo']
]);
$newId = $pdo->lastInsertId();
$response = balp_to_utf8(['ok'=>$ok, 'id'=>$newId]);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
