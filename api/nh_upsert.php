<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';

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
    $pdo->beginTransaction();

    $dupStmt = $pdo->prepare('SELECT id FROM balp_nh WHERE cislo = :cislo' . ($id > 0 ? ' AND id <> :id' : '') . ' LIMIT 1');
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
        $checkStmt = $pdo->prepare('SELECT id FROM balp_nh WHERE id = :id');
        $checkStmt->execute([':id' => $id]);
        if (!$checkStmt->fetchColumn()) {
            respond_json(['error' => 'not found'], 404);
        }

        $sql = 'UPDATE balp_nh SET cislo = :cislo, nazev = :nazev, pozn = :pozn, dtod = :dtod, dtdo = :dtdo WHERE id = :id';
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
        $stmt->execute();
    } else {
        $sql = 'INSERT INTO balp_nh (cislo, nazev, pozn, dtod, dtdo) VALUES (:cislo, :nazev, :pozn, :dtod, :dtdo)';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cislo', $cislo);
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
