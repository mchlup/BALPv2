<?php
// /balp2/api/pol_list.php (fixed, robust, UTF‑8 safe)
// Seznam polotovarů z tabulky balp_pol (vyhledávání, řazení, stránkování).
// Auth: JWT (Authorization/cookie/?token=)

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';

function j($d, int $code=200) {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// Safe token check
try {
  $config_file = dirname(__DIR__) . '/config/config.php';
  $CONFIG = [];
  if (file_exists($config_file)) require $config_file;
  $A = $CONFIG['auth'] ?? [];
  $JWT_SECRET = $A['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token();
  if (!$token) j(['error'=>'missing token'], 401);
  try { jwt_decode($token, $JWT_SECRET, true); } catch (Throwable $e) { j(['error'=>$e->getMessage()], 401); }

  $dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
  $usr  = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
  $pwd  = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
  if (!$dsn) j(['error'=>'DB DSN missing'], 500);

  $pdo = new PDO($dsn, $usr, $pwd, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
  ]);

  // Params
  $search   = trim((string)($_GET['search'] ?? ''));
  $limit    = max(1, min(200, (int)($_GET['limit']  ?? 50)));
  $offset   = max(0, (int)($_GET['offset'] ?? 0));
  $sort_col = (string)($_GET['sort_col'] ?? 'nazev');
  $sort_dir = strtoupper((string)($_GET['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

  // Whitelist columns
  $allowed_cols = ['id','cislo','nazev','sh','okp','olej','pozn'];
  if (!in_array($sort_col, $allowed_cols, true)) $sort_col = 'nazev';

  // WHERE
  $where = '1';
  $params = [];
  if ($search !== '') {
    $where .= ' AND (nazev LIKE :q OR cislo LIKE :q)';
    $params[':q'] = '%' . $search . '%';
  }

  // Total
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM balp_pol WHERE $where");
  $stmt->execute($params);
  $total = (int)$stmt->fetchColumn();

  // Items
  $sql = "SELECT id, cislo, nazev, sh, okp, olej, pozn
          FROM balp_pol
          WHERE $where
          ORDER BY $sort_col $sort_dir
          LIMIT :limit OFFSET :offset";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();

  j(['total'=>$total, 'items'=>$rows]);
} catch (Throwable $e) {
  j(['error'=>$e->getMessage()], 500);
}
