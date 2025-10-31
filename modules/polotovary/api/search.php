<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');

$config_file = balp_project_root() . '/config/config.php';
$CONFIG = []; if (file_exists($config_file)) require $config_file;

try {
  $JWT_SECRET = $CONFIG['auth']['jwt_secret']
    ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
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

  $q = trim((string)($_GET['q'] ?? ''));
  $limit = max(5, min(50, (int)($_GET['limit'] ?? 15)));
  if ($q === '') { echo json_encode(['items'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

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

  echo json_encode(['items'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

