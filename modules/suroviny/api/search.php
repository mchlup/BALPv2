<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');

require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';

try {
  // --- Auth ---
  $config = cfg();
  $authConfig = $config['auth'] ?? [];
  $JWT_SECRET = $authConfig['jwt_secret']
    ?? ($config['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token();
  if (!$token) { http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'missing token']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
  try { jwt_decode($token, $JWT_SECRET, true); } catch (Throwable $e) { error_log($e->getMessage()); http_response_code(401); echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  // --- DB connect ---
  $pdo = db();
  try { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); } catch (Throwable $e) {}

  // Prefer UTF-8, ale neřeš kolaci – kolize vyřešíme v dotazu přes CONVERT/LOWER
  try { $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci'); } catch (Throwable $e) {}

  // --- Input ---
  $q = trim((string)($_GET['q'] ?? ''));
  $limit = (int)($_GET['limit'] ?? 20);
  if ($limit < 1 || $limit > 100) $limit = 20;
  if ($q === '') { echo json_encode(balp_to_utf8(['items'=>[]]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

  // Připrav case-insensitive pattern
  $qLower = '%'.mb_strtolower($q, 'UTF-8').'%';

  // --- Query (robustní, na kolacích nezávislá) ---
  // 1) Obě strany převedeme do utf8mb4, pak na lowercase a použijeme LIKE.
  //    To obejde *_bin kolace, latin2/utf8 smíchané tabulky apod.
  $sql1 = "
    SELECT id, cislo, nazev
    FROM balp_sur
    WHERE (
      LOWER(CONVERT(cislo  USING utf8mb4)) LIKE :q
      OR LOWER(CONVERT(nazev USING utf8mb4)) LIKE :q
    )
    ORDER BY cislo
    LIMIT :limit
  ";
  $stmt = $pdo->prepare($sql1);
  $stmt->bindValue(':q', $qLower, PDO::PARAM_STR);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $items = $stmt->fetchAll();

  // --- Fallback ---
  // Kdyby i přes to nic – vrať alespoň něco přes původní (case-sensitive) variantu
  if (!$items) {
    $sql2 = "
      SELECT id, cislo, nazev
      FROM balp_sur
      WHERE (cislo LIKE :q2 OR nazev LIKE :q2)
      ORDER BY cislo
      LIMIT :limit
    ";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->bindValue(':q2', '%'.$q.'%', PDO::PARAM_STR);
    $stmt2->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt2->execute();
    $items = $stmt2->fetchAll();
  }

  echo json_encode(balp_to_utf8(['items'=>$items]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
  error_log($e->getMessage());
  http_response_code(500);
  echo json_encode(balp_to_utf8(['error'=>'Nastala chyba.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

