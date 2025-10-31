<?php
// /balp2/api/pol_recurse.php
// Rekurzivní rozpad polotovaru na suroviny. Vrací 'tree' (strom) i 'flat' (agregované suroviny).
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');

$config_file = balp_project_root() . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) { require $config_file; }

function db_connect($CONFIG){
  $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
$db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
$db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
if (!$db_dsn) throw new Exception('DB DSN missing');
$pdo = new PDO($db_dsn, $db_user, $db_pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
]);

}

try {
  $JWT_SECRET = $CONFIG['auth']['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
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


  $id = (int)($_GET['id'] ?? 0);
  $mno = isset($_GET['mnozstvi_kg']) ? (float)str_replace(',', '.', (string)$_GET['mnozstvi_kg']) : 1.0;
  if ($id<=0) { http_response_code(400); echo json_encode(['error'=>'missing id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
  if (!is_finite($mno) || $mno<=0) $mno = 1.0;

  // načti hlavičku
  $h = $pdo->prepare("SELECT id, cislo, nazev FROM balp_pol WHERE id=:id AND dtod<=NOW() AND dtdo>=NOW()");
  $h->execute([':id'=>$id]); $head = $h->fetch();
  if (!$head) { http_response_code(404); echo json_encode(['error'=>'polotovar not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  // připravené dotazy
  $q_rec = $pdo->prepare("
    SELECT rec.techpor, rec.gkg, rec.idsur, rec.idpol,
           CASE WHEN rec.idsur>0 THEN 'sur' ELSE 'pol' END AS typ,
           COALESCE(sur.cislo, pol.cislo) AS cislo,
           COALESCE(sur.nazev, pol.nazev) AS nazev
    FROM balp_pol_rec AS rec
      LEFT JOIN balp_sur AS sur ON rec.idsur=sur.id AND sur.dtod<=NOW() AND sur.dtdo>=NOW()
      LEFT JOIN balp_pol AS pol ON rec.idpol=pol.id AND pol.dtod<=NOW() AND pol.dtdo>=NOW()
    WHERE rec.idpolfin=:id AND rec.dtod<=NOW() AND rec.dtdo>=NOW()
    ORDER BY rec.techpor, cislo
  ");

  // rekurze s ochranou proti cyklům
  $stack = [];
  $flat = []; // key by cislo (suroviny)
  $tree = null;

  $expand = function($pol_id, $kg, $label) use (&$expand, &$q_rec, &$flat, &$stack) {
    if (in_array($pol_id, $stack, true)) {
      return ['loop'=>true, 'id'=>$pol_id, 'kg'=>$kg, 'label'=>$label, 'children'=>[]];
    }
    $stack[] = $pol_id;
    $q_rec->execute([':id'=>$pol_id]);
    $rows = $q_rec->fetchAll();
    $children = [];
    foreach ($rows as $r) {
      $need_g = (float)$r['gkg'] * $kg; // g/kg * kg = g
      if ($r['typ'] === 'sur') {
        $key = (string)$r['cislo'];
        if (!isset($flat[$key])) $flat[$key] = ['cislo'=>$r['cislo'], 'nazev'=>$r['nazev'], 'total_g'=>0.0];
        $flat[$key]['total_g'] += $need_g;
        $children[] = ['type'=>'sur','cislo'=>$r['cislo'],'nazev'=>$r['nazev'],'gkg'=>(float)$r['gkg'],'need_g'=>$need_g];
      } else {
        $child = $expand((int)$r['idpol'], $kg * ((float)$r['gkg'] / 1000.0), (string)$r['cislo'].' '.$r['nazev']);
        $children[] = ['type'=>'pol','cislo'=>$r['cislo'],'nazev'=>$r['nazev'],'gkg'=>(float)$r['gkg'],'child'=>$child];
      }
    }
    array_pop($stack);
    return ['loop'=>false, 'id'=>$pol_id, 'kg'=>$kg, 'label'=>$label, 'children'=>$children];
  };

  $tree = $expand($id, $mno, (string)$head['cislo'].' '.$head['nazev']);

  // seřadit flat podle cisla
  uasort($flat, function($a,$b){ return strcmp($a['cislo'], $b['cislo']); });

  echo json_encode(['pol'=>$head, 'mnozstvi_kg'=>$mno, 'tree'=>$tree, 'flat'=>array_values($flat)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
