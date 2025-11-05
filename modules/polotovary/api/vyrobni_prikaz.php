<?php
// /balp2/api/pol_vyrobni_prikaz.php
// Vrátí seznam položek receptury pro vybraný polotovar:
//  - suroviny (balp_sur) + jejich SH/sušiny + platnost (dtod/dtdo)
//  - polotovary (balp_pol) + SH + platnost
// Vstup: GET id (id polotovaru), mnozstvi_kg (float, default 1.0)
// Auth: JWT (Authorization: Bearer <token>) nebo cookie `balp_token`

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');

require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';

try {
  $config = cfg();
  $authConfig = $config['auth'] ?? [];
  $JWT_SECRET = $authConfig['jwt_secret']
    ?? ($config['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token();
  if (!$token) { http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'missing token']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
  try { jwt_decode($token, $JWT_SECRET, true); } catch (Throwable $e) { error_log($e->getMessage()); http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  // DB
  $pdo = db();

  $id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  $mno = isset($_GET['mnozstvi_kg']) ? (float)$_GET['mnozstvi_kg'] : 1.0;
  if ($id <= 0) throw new Exception('missing id');

  // Hlava polotovaru
  $q = $pdo->prepare("SELECT id, cislo, nazev, sh, sus_sh, sus_hmot, okp, pozn
                      FROM balp_pol WHERE id=:id LIMIT 1");
  $q->execute([':id'=>$id]);
  $head = $q->fetch();
  if (!$head) throw new Exception('polotovar not found');

  // SUROVINY (včetně SH/sušin + platností)
  $qSur = $pdo->prepare("
    SELECT
      rec.techpor,
      s.cislo, s.nazev,
      s.sh, s.sus_hmot,
      rec.gkg,
      ROUND(rec.gkg * :kg, 2) AS navazit_g,
      s.dtod AS platnost_od,
      s.dtdo AS platnost_do
    FROM balp_pol_rec AS rec
    JOIN balp_sur AS s ON s.id = rec.idsur
    WHERE rec.idpolfin = :id AND rec.idsur > 0
      AND rec.dtod <= NOW() AND (rec.dtdo IS NULL OR rec.dtdo >= NOW())
    ORDER BY rec.techpor, s.cislo
  ");
  $qSur->execute([':id'=>$id, ':kg'=>$mno]);
  $sur = $qSur->fetchAll();

  // POLOTOVARY (SH + platnosti; sušiny pro polotovar nejsou evidované – ponecháno NULL)
  $qPol = $pdo->prepare("
    SELECT
      rec.techpor,
      p.cislo, p.nazev,
      p.sh,
      NULL AS sus_hmot,
      rec.gkg,
      ROUND(rec.gkg * :kg, 2) AS navazit_g,
      p.dtod AS platnost_od,
      p.dtdo AS platnost_do
    FROM balp_pol_rec AS rec
    JOIN balp_pol AS p ON p.id = rec.idpol
    WHERE rec.idpolfin = :id AND rec.idpol > 0
      AND rec.dtod <= NOW() AND (rec.dtdo IS NULL OR rec.dtdo >= NOW())
    ORDER BY rec.techpor, p.cislo
  ");
  $qPol->execute([':id'=>$id, ':kg'=>$mno]);
  $pol = $qPol->fetchAll();

  // Normalize date to ISO (YYYY-MM-DD) – frontend si to dál jen vypíše
  $normalizeDates = function(array &$rows){
    foreach ($rows as &$r) {
      $r['platnost_od'] = isset($r['platnost_od']) && $r['platnost_od'] ? substr((string)$r['platnost_od'],0,10) : null;
      $r['platnost_do'] = isset($r['platnost_do']) && $r['platnost_do'] ? substr((string)$r['platnost_do'],0,10) : null;
    }
  };
  $normalizeDates($sur);
  $normalizeDates($pol);

  echo json_encode(balp_to_utf8([
    'pol'         => $head,
    'mnozstvi_kg' => $mno,
    'suroviny'    => $sur,
    'polotovary'  => $pol,
  ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  error_log($e->getMessage());
  http_response_code(500);
  echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

