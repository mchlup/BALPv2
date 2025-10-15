<?php
// /balp2/api/pol_clone2.php
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
  $now = date('Y-m-d H:i:s');

  $pdo->beginTransaction();

  $h = $pdo->prepare("SELECT cislo, nazev, sh, okp, olej, pozn FROM balp_pol WHERE id=:id AND dtod<=NOW() AND dtdo>=NOW()");
  $h->execute([':id'=>$id]);
  $head = $h->fetch();
  if (!$head) { throw new Exception('polotovar not found'); }
  $head['cislo'] = null;
  if (!empty($head['nazev'])) $head['nazev'] .= ' (kopie)';

  $ins = $pdo->prepare("INSERT INTO balp_pol (cislo, nazev, sh, okp, olej, pozn, dt_akt_sloz, dtod, dtdo) VALUES (:cislo,:nazev,:sh,:okp,:olej,:pozn,:dt_akt_sloz,:dtod,:dtdo)");
  $ins->execute([
    ':cislo'=>$head['cislo'],
    ':nazev'=>$head['nazev'],
    ':sh'=>$head['sh'],
    ':okp'=>$head['okp'],
    ':olej'=>$head['olej'],
    ':pozn'=>$head['pozn'],
    ':dt_akt_sloz'=>$now,
    ':dtod'=>$now,
    ':dtdo'=>'2099-12-31 23:59:59',
  ]);
  $nid = (int)$pdo->lastInsertId();

  $q = $pdo->prepare("
    SELECT techpor, gkg, idsur, idpol 
    FROM balp_pol_rec 
    WHERE idpolfin=:id AND dtod<=NOW() AND dtdo>=NOW()
    ORDER BY techpor
  ");
  $q->execute([':id'=>$id]);
  $rows = $q->fetchAll();
  $insr = $pdo->prepare("INSERT INTO balp_pol_rec (idpolfin, idsur, idpol, techpor, gkg, dtod, dtdo) VALUES (:idpolfin, :idsur, :idpol, :techpor, :gkg, :dtod, :dtdo)");
  foreach ($rows as $r) {
    $insr->execute([
      ':idpolfin'=>$nid,
      ':idsur'=>$r['idsur'],
      ':idpol'=>$r['idpol'],
      ':techpor'=>$r['techpor'],
      ':gkg'=>$r['gkg'],
      ':dtod'=>$now,
      ':dtdo'=>'2099-12-31 23:59:59',
    ]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true, 'id'=>$nid], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
