<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(balp_to_utf8(['error'=>'missing id']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$sql = "SELECT id, cislo, nazev, sh, sus_sh, sus_hmot, okp, olej, pozn, dtod, dtdo FROM balp_sur WHERE id=:id LIMIT 1";
$stmt = $pdo->prepare($sql); $stmt->execute([':id'=>$id]); $row = $stmt->fetch();
if (!$row) { http_response_code(404); echo json_encode(balp_to_utf8(['error'=>'not found']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
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
$response = balp_to_utf8(['item'=>$row, 'meta'=>$meta]);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
