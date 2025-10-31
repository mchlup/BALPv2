<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
header('Content-Type: application/json; charset=utf-8');

$config_file = balp_project_root() . '/config/config.php';
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
$meta = ['usage_total'=>0, 'usage_polotovary'=>0, 'last_used'=>null];
try {
  $stats = $pdo->prepare("SELECT COUNT(*) AS cnt, COUNT(DISTINCT idpolfin) AS polotovary, MAX(dtod) AS last_dtod FROM balp_pol_rec WHERE idsur=:id");
  $stats->execute([':id'=>$id]);
  $agg = $stats->fetch();
  if ($agg) {
    $meta['usage_total'] = (int)($agg['cnt'] ?? 0);
    $meta['usage_polotovary'] = (int)($agg['polotovary'] ?? 0);
    $last = $agg['last_dtod'] ?? null;
    if ($last) $meta['last_used'] = substr((string)$last, 0, 10);
  }
} catch (Throwable $e) {}
echo json_encode(['item'=>$row, 'meta'=>$meta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
