<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';

// Load config
$config_file = dirname(__DIR__) . '/config/config.php';
$CONFIG = []; if (file_exists($config_file)) $CONFIG = include $config_file;

try {
  // --- Auth ---
  $JWT_SECRET = $CONFIG['auth']['jwt_secret']
    ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token();
  if (!$token) { http_response_code(401); echo json_encode(['error'=>'missing token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
  jwt_decode($token, $JWT_SECRET, true);

  // --- DB connect ---
  $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
  $db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
  $db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
  if (!$db_dsn) throw new RuntimeException('Missing DB DSN');

  $pdo = new PDO($db_dsn, $db_user, $db_pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    // Důležité pro "LIMIT :limit" na některých verzích MySQL/MariaDB
    PDO::ATTR_EMULATE_PREPARES   => true,
  ]);

  // Prefer UTF-8, ale neřeš kolaci – kolize vyřešíme v dotazu přes CONVERT/LOWER
  try { $pdo->exec("SET NAMES utf8mb4"); } catch (Throwable $e) {}

  // --- Input ---
  $q = trim((string)($_GET['q'] ?? ''));
  $limit = (int)($_GET['limit'] ?? 20);
  if ($limit < 1 || $limit > 100) $limit = 20;
  if ($q === '') { echo json_encode(['items'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

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

  echo json_encode(['items'=>$items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

