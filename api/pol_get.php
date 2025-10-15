<?php
// /balp2/api/pol_get.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';

$config_file = dirname(__DIR__) . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) { require $config_file; }

try {
  $JWT_SECRET = $CONFIG['auth']['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token();
  if (!$token) { http_response_code(401); echo json_encode(['error'=>'missing token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
  jwt_decode($token, $JWT_SECRET, true);

  // DB
  $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
$db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
$db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
if (!$db_dsn) throw new Exception('DB DSN missing');
$pdo = new PDO($db_dsn, $db_user, $db_pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
]);


  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'missing id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  $h = $pdo->prepare("SELECT id, cislo, nazev, sh, okp, olej, pozn, dt_akt_sloz, dtod, dtdo FROM balp_pol WHERE id=:id AND dtod<=NOW() AND dtdo>=NOW()");
  $h->execute([':id'=>$id]);
  $head = $h->fetch();
  if (!$head) { http_response_code(404); echo json_encode(['error'=>'not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  $q = $pdo->prepare("
    SELECT 
      rec.id,
      rec.techpor,
      rec.gkg,
      rec.idsur,
      rec.idpol,
      CASE WHEN rec.idsur>0 THEN 'sur' ELSE 'pol' END AS typ,
      COALESCE(sur.cislo, pol.cislo) AS cislo,
      COALESCE(sur.nazev, pol.nazev) AS nazev
    FROM balp_pol_rec AS rec
      LEFT JOIN balp_sur AS sur ON rec.idsur=sur.id AND sur.dtod<=NOW() AND sur.dtdo>=NOW()
      LEFT JOIN balp_pol AS pol ON rec.idpol=pol.id AND pol.dtod<=NOW() AND pol.dtdo>=NOW()
    WHERE rec.idpolfin=:id AND rec.dtod<=NOW() AND rec.dtdo>=NOW()
    ORDER BY rec.techpor, cislo
  ");
  $q->execute([':id'=>$id]);
  $rows = $q->fetchAll();

  echo json_encode(['pol'=>$head, 'lines'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
