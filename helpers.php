<?php
// Unified helper utilities for BALP v2

function cfg() {
    // Always return an array
    $p = __DIR__ . '/config/config.php';
    if (file_exists($p)) {
        $c = include $p; // our config.php returns the array and also defines $CONFIG
        if (is_array($c)) return $c;
        // Fallback: if include returned non-array but $CONFIG exists
        if (isset($GLOBALS['CONFIG']) && is_array($GLOBALS['CONFIG'])) return $GLOBALS['CONFIG'];
    }
    // Sample defaults
    return include __DIR__ . '/config/config.sample.php';
}

function respond_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function db_try_connect($conf, &$err = null) {
    try {
        if (isset($conf['db_dsn'])) {
            $dsn = $conf['db_dsn'];
            $user = $conf['db_user'] ?? '';
            $pass = $conf['db_pass'] ?? '';
        } else {
            $db = $conf['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'], $db['port'] ?? 3306, $db['database'], $db['charset'] ?? 'utf8mb4'
            );
            $user = $db['username'];
            $pass = $db['password'];
        }
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
        return $pdo;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        return null;
    }
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg();
    $err = null;
    $pdo = db_try_connect($c, $err);
    if (!$pdo) {
        respond_json(['error' => 'DB connect failed', 'detail' => $err], 500);
    }
    // Ensure utf8mb4
    try { $pdo->exec("SET NAMES utf8mb4"); } catch (Throwable $e) {}
    return $pdo;
}

if (!function_exists('balp_getallheaders')) {
    function balp_getallheaders() {
        if (function_exists('getallheaders')) return getallheaders();
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

function get_bearer_token() {
    $headers = balp_getallheaders();
    foreach (['Authorization', 'Authorization-Alt', 'X-Authorization'] as $h) {
        if (!empty($headers[$h]) && stripos($headers[$h], 'Bearer ') === 0) {
            return trim(substr($headers[$h], 7));
        }
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        return trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
    }
    if (!empty($_COOKIE['balp_token'])) return $_COOKIE['balp_token'];
    if (!empty($_GET['token'])) return $_GET['token'];
    if (!empty($_POST['token'])) return $_POST['token'];
    return '';
}

function request_json() {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function has_auth() { $c = cfg(); return !empty($c['auth']['enabled']); }

function auth_user() {
    if (!has_auth()) return ['sub' => 'anonymous', 'role' => null];
    $token = get_bearer_token();
    if (!$token) return null;
    require_once __DIR__ . '/api/jwt_helper.php';
    return jwt_decode($token, cfg()['auth']['jwt_secret'] ?? 'change', true);
}

function sql_quote_ident($n) { return '`' . str_replace('`', '``', $n) . '`'; }

function balp_old_password_hash_php(string $pwd): string {
    $nr = 1345345333;
    $add = 7;
    $nr2 = 0x12345671;
    $pwd = str_replace(["\r", "\n"], '', $pwd);
    $len = strlen($pwd);
    for ($i = 0; $i < $len; $i++) {
        $c = $pwd[$i];
        if ($c === ' ' || $c === "\t") continue;
        $tmp = ord($c);
        $nr ^= ((($nr & 63) + $add) * $tmp) + ($nr << 8);
        $nr &= 0xFFFFFFFF;
        $nr2 += ($nr2 << 8) ^ $nr;
        $nr2 &= 0xFFFFFFFF;
        $add += $tmp;
        $add &= 0xFFFFFFFF;
    }
    $res1 = $nr & 0x7FFFFFFF;
    $res2 = $nr2 & 0x7FFFFFFF;
    return strtoupper(sprintf('%08x%08x', $res1, $res2));
}

function balp_old_password_hex(string $pwd, ?PDO $pdo = null): string {
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT OLD_PASSWORD(" . $pdo->quote($pwd) . ")");
            if ($stmt) {
                $hash = $stmt->fetchColumn();
                if (is_string($hash)) {
                    if (preg_match('/^[0-9a-fA-F]{16}$/', $hash)) {
                        return strtoupper($hash);
                    }
                    if (strlen($hash) === 8) {
                        return strtoupper(bin2hex($hash));
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore DB OLD_PASSWORD() availability issues
        }
    }
    return balp_old_password_hash_php($pwd);
}

function balp_old_password_raw(string $pwd, ?PDO $pdo = null): string {
    $hex = balp_old_password_hex($pwd, $pdo);
    $raw = hex2bin($hex);
    return $raw === false ? '' : $raw;
}
?>
