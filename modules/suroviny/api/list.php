<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
require_once __DIR__ . '/filters.php';
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');

$config = cfg();
$authConfig = $config['auth'] ?? [];

$JWT_SECRET = $authConfig['jwt_secret'] ?? ($config['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
$token = balp_get_bearer_token();
if (!$token) { http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'missing token']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
try { jwt_decode($token, $JWT_SECRET, true); } catch (Throwable $e) { error_log($e->getMessage()); http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
  $pdo = db();
} catch (Throwable $e) { error_log($e->getMessage()); http_response_code(500); echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

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
