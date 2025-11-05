<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
require_once __DIR__ . '/filters.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Language: cs');
$filename = 'balp_sur_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$config = cfg();
$authConfig = $config['auth'] ?? [];
$JWT_SECRET = $authConfig['jwt_secret'] ?? ($config['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
$token = balp_get_bearer_token();
if (!$token) { http_response_code(401); echo "error: missing token"; exit; }
try { jwt_decode($token, $JWT_SECRET, true); } catch (Throwable $e) { error_log($e->getMessage()); http_response_code(401); echo 'error: Nastala chyba.'; exit; }

try {
  $pdo = db();
} catch (Throwable $e) { error_log($e->getMessage()); http_response_code(500); echo 'error: Nastala chyba.'; exit; }

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
