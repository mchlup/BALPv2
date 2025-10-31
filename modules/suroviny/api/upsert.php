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

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { http_response_code(400); echo json_encode(balp_to_utf8(['error'=>'invalid json']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$fields = ['cislo','nazev','sh','sus_sh','sus_hmot','okp','olej','pozn','dtod','dtdo'];
$data = []; foreach ($fields as $k) $data[$k] = array_key_exists($k,$body)? $body[$k] : null;
$id = isset($body['id']) && $body['id'] ? (int)$body['id'] : null;
if (isset($data['dtod']) && $data['dtod']==='') $data['dtod'] = null;
if (isset($data['dtdo']) && $data['dtdo']==='') $data['dtdo'] = null;

if ($id) {
  $sql = "UPDATE balp_sur SET cislo=:cislo, nazev=:nazev, sh=:sh, sus_sh=:sus_sh, sus_hmot=:sus_hmot,
          okp=:okp, olej=:olej, pozn=:pozn, dtod=:dtod, dtdo=:dtdo WHERE id=:id";
  $stmt = $pdo->prepare($sql);
  $ok = $stmt->execute([':cislo'=>$data['cislo'], ':nazev'=>$data['nazev'], ':sh'=>$data['sh'], ':sus_sh'=>$data['sus_sh'], ':sus_hmot'=>$data['sus_hmot'], ':okp'=>$data['okp'], ':olej'=>$data['olej'], ':pozn'=>$data['pozn'], ':dtod'=>$data['dtod'], ':dtdo'=>$data['dtdo'], ':id'=>$id ]);
  $response = balp_to_utf8(['ok'=>$ok, 'id'=>$id]);
  echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} else {
  $sql = "INSERT INTO balp_sur (cislo, nazev, sh, sus_sh, sus_hmot, okp, olej, pozn, dtod, dtdo)
          VALUES (:cislo, :nazev, :sh, :sus_sh, :sus_hmot, :okp, :olej, :pozn, :dtod, :dtdo)";
  $stmt = $pdo->prepare($sql);
  $ok = $stmt->execute([':cislo'=>$data['cislo'], ':nazev'=>$data['nazev'], ':sh'=>$data['sh'], ':sus_sh'=>$data['sus_sh'], ':sus_hmot'=>$data['sus_hmot'], ':okp'=>$data['okp'], ':olej'=>$data['olej'], ':pozn'=>$data['pozn'], ':dtod'=>$data['dtod'], ':dtdo'=>$data['dtdo'] ]);
  $newId = $pdo->lastInsertId();
  $response = balp_to_utf8(['ok'=>$ok, 'id'=>$newId]);
  echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
