<?php
// /balp2/api/pol_get.php
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

  $h = $pdo->prepare("SELECT id, cislo, nazev, sh, okp, olej, pozn, dt_akt_sloz, dtod, dtdo FROM balp_pol WHERE id=:id AND dtod<=NOW() AND dtdo>=NOW()");
  $h->execute([':id'=>$id]);
  $head = $h->fetch();
  if (!$head) { http_response_code(404); echo json_encode(balp_to_utf8(['error'=>'not found']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

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

  echo json_encode(balp_to_utf8(['pol'=>$head, 'lines'=>$rows]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  error_log($e->getMessage());
  http_response_code(500);
  echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
