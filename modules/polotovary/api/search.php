<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';

try {
  $config = cfg();
  $authConfig = $config['auth'] ?? [];
  $JWT_SECRET = $authConfig['jwt_secret'] ?? ($config['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token();
  if (!$token) { http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'missing token']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
  try { jwt_decode($token, $JWT_SECRET, true); } catch (Throwable $e) { error_log($e->getMessage()); http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  $pdo = db();

  $q = trim((string)($_GET['q'] ?? ''));
  $limit = max(5, min(50, (int)($_GET['limit'] ?? 15)));
  if ($q === '') { echo json_encode(balp_to_utf8(['items'=>[]]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  $stmt = $pdo->prepare("
    SELECT id, cislo, nazev
    FROM balp_pol
    WHERE dtod<=NOW() AND dtdo>=NOW()
      AND (cislo LIKE :q OR nazev LIKE :q)
    ORDER BY cislo
    LIMIT :limit
  ");
  $stmt->bindValue(':q', '%'.$q.'%');
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  echo json_encode(balp_to_utf8(['items'=>$stmt->fetchAll()]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
  error_log($e->getMessage());
  http_response_code(500);
  echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

