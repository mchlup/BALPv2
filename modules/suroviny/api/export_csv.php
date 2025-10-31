<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once __DIR__ . '/filters.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Language: cs');
$filename = 'balp_sur_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$config_file = balp_project_root() . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) require $config_file;
$A = $CONFIG['auth'] ?? [];
$JWT_SECRET = $A['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
$token = balp_get_bearer_token();
if (!$token) { http_response_code(401); echo "error: missing token"; exit; }
try { jwt_decode($token, $JWT_SECRET, true); } catch (Exception $e) { http_response_code(401); echo 'error: ' . $e->getMessage(); exit; }

try {
  $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
  $db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
  $db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
  if (!$db_dsn) throw new Exception('DB DSN missing');
  $pdo = new PDO($db_dsn, $db_user, $db_pass, balp_utf8_pdo_options());
} catch (Exception $e) { http_response_code(500); echo 'error: ' . $e->getMessage(); exit; }

$params = [];
$where = sur_build_where($_GET, $params);
$sort_col = sur_normalize_sort_column($_GET['sort_col'] ?? 'nazev');
$sort_dir = strtoupper($_GET['sort_dir'] ?? 'ASC');
$sort_dir = ($sort_dir === 'DESC') ? 'DESC' : 'ASC';
$limit_sql = '';
if (empty($_GET['all'])) {
  $limit = max(1, min(1000, (int)($_GET['limit'] ?? 500)));
  $offset = max(0, (int)($_GET['offset'] ?? 0));
  $limit_sql = ' LIMIT :limit OFFSET :offset';
}

$sql = "SELECT id, cislo, nazev, sh, sus_sh, sus_hmot, okp, olej, pozn, dtod, dtdo FROM balp_sur WHERE $where ORDER BY $sort_col $sort_dir$limit_sql";
$stmt = $pdo->prepare($sql);
sur_bind_params($stmt, $params);
if ($limit_sql) {
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$stmt->execute();
$rows = balp_to_utf8($stmt->fetchAll());

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Číslo', 'Název', 'SH', 'Sušina (sh)', 'Sušina (hmot)', 'OKP', 'Olej', 'Poznámka', 'Platnost od', 'Platnost do'], ';');
foreach ($rows as $row) {
  $dtod = $row['dtod'] ? substr((string)$row['dtod'], 0, 10) : '';
  $dtdo = $row['dtdo'] ? substr((string)$row['dtdo'], 0, 10) : '';
  fputcsv($out, [
    $row['id'],
    $row['cislo'],
    $row['nazev'],
    $row['sh'],
    $row['sus_sh'],
    $row['sus_hmot'],
    $row['okp'],
    $row['olej'],
    $row['pozn'],
    $dtod,
    $dtdo,
  ], ';');
}

fclose($out);
exit;
