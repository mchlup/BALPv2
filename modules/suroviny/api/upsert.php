<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');

header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');

$respond = static function ($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode(
        balp_to_utf8($data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
};

$configFile = balp_project_root() . '/config/config.php';
$CONFIG = [];
if (file_exists($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $CONFIG = $loaded;
    } elseif (!is_array($CONFIG) && isset($GLOBALS['CONFIG']) && is_array($GLOBALS['CONFIG'])) {
        $CONFIG = $GLOBALS['CONFIG'];
    }
}
if (!is_array($CONFIG)) {
    $CONFIG = [];
}

$authConf = $CONFIG['auth'] ?? [];
$jwtSecret = $authConf['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));

$token = balp_get_bearer_token();
if (!$token) {
    $respond(['error' => 'missing token'], 401);
}

try {
    jwt_decode($token, $jwtSecret, true);
} catch (Throwable $e) {
    $respond(['error' => $e->getMessage()], 401);
}

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody ?: 'null', true);
if (!is_array($body)) {
    $respond(['error' => 'invalid json'], 400);
}

$fields = ['cislo', 'nazev', 'sh', 'sus_sh', 'sus_hmot', 'okp', 'olej', 'pozn', 'dtod', 'dtdo'];
$data = [];
foreach ($fields as $field) {
    $data[$field] = array_key_exists($field, $body) ? $body[$field] : null;
}

$id = isset($body['id']) && $body['id'] ? (int)$body['id'] : null;

// Normalizace textových polí
foreach (['cislo', 'nazev', 'pozn'] as $key) {
    if ($data[$key] !== null) {
        $data[$key] = trim((string)$data[$key]);
        if ($data[$key] === '') {
            $data[$key] = null;
        }
    }
}

// Povinné hodnoty
if ($data['cislo'] === null) {
    $respond(['error' => 'Pole "Číslo" je povinné.'], 422);
}
if ($data['nazev'] === null) {
    $respond(['error' => 'Pole "Název" je povinné.'], 422);
}

$numericFields = ['sh', 'sus_sh', 'sus_hmot', 'okp', 'olej'];
foreach ($numericFields as $numericField) {
    if ($data[$numericField] === '' || $data[$numericField] === false) {
        $data[$numericField] = null;
    }
    if ($data[$numericField] !== null) {
        if (!is_numeric($data[$numericField])) {
            $respond(['error' => sprintf('Pole "%s" musí být číslo.', $numericField)], 422);
        }
        $data[$numericField] = (float)$data[$numericField];
    }
}

foreach (['dtod', 'dtdo'] as $dateField) {
    if (!isset($data[$dateField])) {
        continue;
    }
    if ($data[$dateField] === null || $data[$dateField] === '') {
        $data[$dateField] = null;
    } else {
        $value = trim((string)$data[$dateField]);
        if ($value === '') {
            $data[$dateField] = null;
        } else {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d|', $value) ?: DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if (!$dt) {
                $respond(['error' => sprintf('Pole "%s" musí být ve formátu RRRR-MM-DD.', $dateField)], 422);
            }
            $data[$dateField] = $dt->format('Y-m-d');
        }
    }
}

if ($id === null && $data['dtod'] === null) {
    $data['dtod'] = (new DateTimeImmutable('now'))->format('Y-m-d');
}

if ($data['dtod'] !== null && $data['dtdo'] !== null && $data['dtod'] > $data['dtdo']) {
    $respond(['error' => 'Platnost do musí být později než platnost od.'], 422);
}

try {
    $dbDsn = $CONFIG['db_dsn'] ?? getenv('BALP_DB_DSN');
    $dbUser = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
    $dbPass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
    if (!$dbDsn) {
        throw new RuntimeException('DB DSN missing');
    }
    $pdo = new PDO($dbDsn, $dbUser, $dbPass, balp_utf8_pdo_options());

    if ($id) {
        $sql = 'UPDATE balp_sur SET cislo = :cislo, nazev = :nazev, sh = :sh, sus_sh = :sus_sh, '
            . 'sus_hmot = :sus_hmot, okp = :okp, olej = :olej, pozn = :pozn, dtod = :dtod, dtdo = :dtdo '
            . 'WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $sql = 'INSERT INTO balp_sur (cislo, nazev, sh, sus_sh, sus_hmot, okp, olej, pozn, dtod, dtdo) '
            . 'VALUES (:cislo, :nazev, :sh, :sus_sh, :sus_hmot, :okp, :olej, :pozn, :dtod, :dtdo)';
        $stmt = $pdo->prepare($sql);
    }

    $bind = static function (PDOStatement $stmt, string $param, $value) {
        if ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, $value);
        }
    };

    $bind($stmt, ':cislo', $data['cislo']);
    $bind($stmt, ':nazev', $data['nazev']);
    $bind($stmt, ':sh', $data['sh']);
    $bind($stmt, ':sus_sh', $data['sus_sh']);
    $bind($stmt, ':sus_hmot', $data['sus_hmot']);
    $bind($stmt, ':okp', $data['okp']);
    $bind($stmt, ':olej', $data['olej']);
    $bind($stmt, ':pozn', $data['pozn']);
    $bind($stmt, ':dtod', $data['dtod']);
    $bind($stmt, ':dtdo', $data['dtdo']);

    $stmt->execute();

    $newId = $id ?? (int)$pdo->lastInsertId();
    $respond(['ok' => true, 'id' => $newId]);
} catch (Throwable $e) {
    $respond(['error' => $e->getMessage()], 500);
}
