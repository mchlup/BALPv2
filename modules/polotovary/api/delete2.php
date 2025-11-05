<?php
// /balp2/api/pol_delete2.php
// Soft-delete: nastaví dtdo na NOW()-1 pro pol i aktivní řádky.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';

$config = cfg();
$authConfig = $config['auth'] ?? [];
$JWT_SECRET = $authConfig['jwt_secret'] ?? ($config['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
$token = balp_get_bearer_token();
if (!$token) { http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'missing token']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
try { jwt_decode($token, $JWT_SECRET, true); } catch (Throwable $e) { error_log($e->getMessage()); http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
  $pdo = db();


  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(balp_to_utf8(['error'=>'missing id']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  $pdo->beginTransaction();
  $pdo->prepare("UPDATE balp_pol SET dtdo=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id=:id AND dtod<=NOW() AND dtdo>=NOW()")->execute([':id'=>$id]);
  $pdo->prepare("UPDATE balp_pol_rec SET dtdo=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE idpolfin=:id AND dtod<=NOW() AND dtdo>=NOW()")->execute([':id'=>$id]);
  $pdo->commit();

  echo json_encode(balp_to_utf8(['ok'=>true]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log($e->getMessage());
  http_response_code(500);
  echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
