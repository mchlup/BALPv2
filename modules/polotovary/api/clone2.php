<?php
// /balp2/api/pol_clone2.php
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

  // DB
  $pdo = db();


  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(balp_to_utf8(['error'=>'missing id']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
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
  echo json_encode(balp_to_utf8(['ok'=>true, 'id'=>$nid]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log($e->getMessage());
  http_response_code(500);
  echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
