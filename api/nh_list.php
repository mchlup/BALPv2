<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        respond_json(['error' => 'Auth disabled'], 403);
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        respond_json(['error' => 'missing token'], 401);
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $pdo = db();
    balp_ensure_nh_table($pdo);
    $nhTable = sql_quote_ident(balp_nh_table_name());

    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = trim((string)($_GET['q'] ?? ''));

    $codeFrom = $_GET['cislo_od'] ?? $_GET['od'] ?? null;
    $codeTo   = $_GET['cislo_do'] ?? $_GET['do'] ?? null;
    $active   = $_GET['active'] ?? $_GET['platne'] ?? '1';

    $normalizeCode = static function ($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $upper = mb_strtoupper($value, 'UTF-8');
        if (preg_match('/^-?\d+$/', $upper)) {
            $isNegative = strpos($upper, '-') === 0;
            $digits = ltrim($upper, '-');
            $padded = str_pad($digits, 12, '0', STR_PAD_LEFT);
            return $isNegative ? '-' . $padded : $padded;
        }
        return $upper;
    };

    $codeFrom = $normalizeCode($codeFrom);
    $codeTo   = $normalizeCode($codeTo);

    $where = [];
    $params = [];

    if ($search !== '') {
        $params[':search'] = '%' . $search . '%';
        $where[] = '(cislo LIKE :search OR nazev LIKE :search OR pozn LIKE :search)';
    }

    if ($codeFrom !== null) {
        $params[':cislo_od'] = $codeFrom;
        $where[] = 'cislo >= :cislo_od';
    }

    if ($codeTo !== null) {
        $params[':cislo_do'] = $codeTo;
        $where[] = 'cislo <= :cislo_do';
    }

    if ($active !== null && $active !== '') {
        $flag = in_array(strtolower((string)$active), ['1', 'true', 'yes', 'ano'], true);
        if ($flag) {
            $where[] = '(dtod <= NOW() AND dtdo > NOW())';
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $nhTable $whereSql");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT id, cislo, nazev, pozn, dtod, dtdo, NULL AS kategorie_id FROM $nhTable $whereSql ORDER BY cislo LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['kod'] = $row['cislo'];
        $row['name'] = $row['nazev'];
    }
    unset($row);

    echo json_encode([
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'items' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
