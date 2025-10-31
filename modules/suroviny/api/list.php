<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once __DIR__ . '/filters.php';
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

$limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$sort_col = sur_normalize_sort_column($_GET['sort_col'] ?? 'nazev');
$sort_dir = strtoupper($_GET['sort_dir'] ?? 'ASC');
$sort_dir = ($sort_dir === 'DESC') ? 'DESC' : 'ASC';
$params = [];
$where = sur_build_where($_GET, $params);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM balp_sur WHERE $where");
sur_bind_params($stmt, $params);
$stmt->execute();
$total = (int)$stmt->fetchColumn();

$sql = "SELECT id, cislo, nazev, sh, sus_sh, sus_hmot, okp, olej, pozn, dtod, dtdo FROM balp_sur WHERE $where ORDER BY $sort_col $sort_dir LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
sur_bind_params($stmt, $params);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
$response = balp_to_utf8(['total'=>$total, 'items'=>$rows]);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
