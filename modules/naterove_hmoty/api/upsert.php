<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');

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

    $user = jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);
    if (!$user) {
        respond_json(['error' => 'invalid token'], 401);
    }

    $payload = request_json();
    if (!$payload) {
        $payload = $_POST ?? [];
    }

    $id = isset($payload['id']) ? (int)$payload['id'] : 0;

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

    $cislo = $normalizeCode($payload['kod'] ?? $payload['cislo'] ?? '');
    $cisloVtRaw = trim((string)($payload['cislo_vt'] ?? $payload['cislo_vp'] ?? $payload['vp'] ?? $payload['vp_cislo'] ?? ''));
    $cisloVt = null;
    if ($cisloVtRaw !== '') {
        $digits = preg_replace('/[^0-9]/', '', $cisloVtRaw);
        if ($digits === '' || strlen($digits) > 6) {
            respond_json(['error' => 'Neplatný formát čísla VT'], 400);
        }
        $digits = str_pad($digits, 6, '0', STR_PAD_LEFT);
        $partA = (int)substr($digits, 0, 2);
        $partB = (int)substr($digits, 2, 4);
        $cisloVt = sprintf('%02d-%04d', $partA, $partB);
    }
    $nazev = trim((string)($payload['nazev'] ?? $payload['name'] ?? ''));
    $pozn  = trim((string)($payload['pozn'] ?? ''));

    $dtod = trim((string)($payload['dtod'] ?? ''));
    $dtdo = trim((string)($payload['dtdo'] ?? ''));

    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    if ($dtod === '') {
        $dtod = $today;
    }
    if ($dtdo === '') {
        $dtdo = '9999-12-31';
    }

    if ($cislo === null || $cislo === '') {
        respond_json(['error' => 'cislo (kod) is required'], 400);
    }
    if ($nazev === '') {
        respond_json(['error' => 'nazev is required'], 400);
    }

    if ($dtod >= $dtdo) {
        respond_json(['error' => 'Platnost do musí být větší než platnost od'], 400);
    }

    $pdo = db();
    balp_ensure_nh_table($pdo);
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $vtColumn = null;
    if (balp_nh_has_column($pdo, 'cislo_vt')) {
        $vtColumn = 'cislo_vt';
    } elseif (balp_nh_has_column($pdo, 'cislo_vp')) {
        $vtColumn = 'cislo_vp';
    }
    $vtColumnSql = $vtColumn ? sql_quote_ident($vtColumn) : null;
    $pdo->beginTransaction();

    $dupStmt = $pdo->prepare("SELECT id FROM $nhTable WHERE cislo = :cislo" . ($id > 0 ? ' AND id <> :id' : '') . ' LIMIT 1');
    $dupStmt->bindValue(':cislo', $cislo);
    if ($id > 0) {
        $dupStmt->bindValue(':id', $id, PDO::PARAM_INT);
    }
    $dupStmt->execute();
    if ($dupStmt->fetchColumn()) {
        respond_json(['error' => 'Zadané číslo NH už existuje'], 409);
    }

    $poznValue = ($pozn === '') ? null : $pozn;

    if ($id > 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM $nhTable WHERE id = :id");
        $checkStmt->execute([':id' => $id]);
        if (!$checkStmt->fetchColumn()) {
            respond_json(['error' => 'not found'], 404);
        }

        $sql = "UPDATE $nhTable SET cislo = :cislo, nazev = :nazev, pozn = :pozn, dtod = :dtod, dtdo = :dtdo";
        if ($vtColumnSql) {
            $sql .= ', ' . $vtColumnSql . ' = :cislo_vt';
        }
        $sql .= " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':cislo', $cislo);
        $stmt->bindValue(':nazev', $nazev);
        if ($poznValue === null) {
            $stmt->bindValue(':pozn', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':pozn', $poznValue);
        }
        $stmt->bindValue(':dtod', $dtod);
        $stmt->bindValue(':dtdo', $dtdo);
        if ($vtColumnSql) {
            if ($cisloVt === null) {
                $stmt->bindValue(':cislo_vt', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':cislo_vt', $cisloVt);
            }
        }
        $stmt->execute();
    } else {
        if ($vtColumnSql) {
            $sql = "INSERT INTO $nhTable (cislo, $vtColumnSql, nazev, pozn, dtod, dtdo) VALUES (:cislo, :cislo_vt, :nazev, :pozn, :dtod, :dtdo)";
        } else {
            $sql = "INSERT INTO $nhTable (cislo, nazev, pozn, dtod, dtdo) VALUES (:cislo, :nazev, :pozn, :dtod, :dtdo)";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cislo', $cislo);
        if ($vtColumnSql) {
            if ($cisloVt === null) {
                $stmt->bindValue(':cislo_vt', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':cislo_vt', $cisloVt);
            }
        }
        $stmt->bindValue(':nazev', $nazev);
        if ($poznValue === null) {
            $stmt->bindValue(':pozn', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':pozn', $poznValue);
        }
        $stmt->bindValue(':dtod', $dtod);
        $stmt->bindValue(':dtdo', $dtdo);
        $stmt->execute();
        $id = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
