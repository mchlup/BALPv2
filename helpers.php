<?php
// Unified helper utilities for BALP v2

if (!defined('BALP_UTF8_BOOTSTRAPPED')) {
    if (function_exists('ini_set')) {
        @ini_set('default_charset', 'UTF-8');
    }
    if (function_exists('mb_internal_encoding')) {
        @mb_internal_encoding('UTF-8');
    }
    if (function_exists('mb_http_output')) {
        @mb_http_output('UTF-8');
    }
    if (function_exists('mb_regex_encoding')) {
        @mb_regex_encoding('UTF-8');
    }
    if (function_exists('mb_language')) {
        @mb_language('uni');
    }
    if (function_exists('setlocale')) {
        foreach (['cs_CZ.UTF-8', 'cs_CZ.utf8', 'cs_CZ', 'Czech_Czechia.1250', 'en_US.UTF-8'] as $localeOption) {
            if (@setlocale(LC_ALL, $localeOption)) {
                break;
            }
        }
    }
    define('BALP_UTF8_BOOTSTRAPPED', true);
}

if (!function_exists('balp_to_utf8')) {
    function balp_to_utf8($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalizedKey = is_string($key) ? balp_to_utf8($key) : $key;
                $normalized[$normalizedKey] = balp_to_utf8($item);
            }
            return $normalized;
        }
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', ['UTF-8', 'Windows-1250', 'ISO-8859-2', 'Windows-1252']);
                if ($converted !== false) {
                    return $converted;
                }
                return mb_convert_encoding($value, 'UTF-8', 'Windows-1250');
            }
            return $value;
        }
        return $value;
    }
}

if (!function_exists('balp_utf8_pdo_options')) {
    function balp_utf8_pdo_options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci',
        ];
    }
}

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
    header('Content-Language: cs');
    $payload = balp_to_utf8($data);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
        $pdo = new PDO($dsn, $user, $pass, balp_utf8_pdo_options());
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
    try { $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci'); } catch (Throwable $e) {}
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
    if (!empty($_COOKIE['balp_token'])) return (string)$_COOKIE['balp_token'];
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

function balp_config_path(): string
{
    return __DIR__ . '/config/config.php';
}

function balp_sample_config(): array
{
    static $sample = null;
    if ($sample === null) {
        $sampleFile = __DIR__ . '/config/config.sample.php';
        $loaded = file_exists($sampleFile) ? include $sampleFile : [];
        $sample = is_array($loaded) ? $loaded : [];
    }
    return $sample;
}

function balp_normalize_config(array $config): array
{
    $defaults = balp_sample_config();
    $normalized = array_replace_recursive($defaults, $config);

    $normalized['app_url'] = isset($normalized['app_url'])
        ? trim((string)$normalized['app_url'])
        : '';

    $dbDefaults = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'balp_new',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_czech_ci',
    ];
    $db = $normalized['db'] ?? [];
    if (!is_array($db)) {
        $db = [];
    }
    $db = array_replace($dbDefaults, $db);
    $db['port'] = (int)($db['port'] ?? 3306);
    if ($db['port'] <= 0) {
        $db['port'] = 3306;
    }
    $dsn = '';
    if (array_key_exists('db_dsn', $config)) {
        $dsn = trim((string)$config['db_dsn']);
    } elseif (array_key_exists('db_dsn', $normalized)) {
        $dsn = trim((string)$normalized['db_dsn']);
    }
    if ($dsn === '') {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $db['driver'] ?? 'mysql',
            $db['host'] ?? '127.0.0.1',
            $db['port'],
            $db['database'] ?? 'balp_new',
            $db['charset'] ?? 'utf8mb4'
        );
    }

    $dbUser = array_key_exists('db_user', $config) ? (string)$config['db_user'] : (string)($db['username'] ?? '');
    if ($dbUser === '' && isset($db['username'])) {
        $dbUser = (string)$db['username'];
    }
    $dbPass = array_key_exists('db_pass', $config) ? (string)$config['db_pass'] : (string)($db['password'] ?? '');
    if ($dbPass === '' && isset($db['password'])) {
        $dbPass = (string)$db['password'];
    }

    $db['username'] = $dbUser;
    $db['password'] = $dbPass;
    $normalized['db'] = $db;
    $normalized['db_dsn'] = $dsn;
    $normalized['db_user'] = $dbUser;
    $normalized['db_pass'] = $dbPass;

    $authDefaults = [
        'enabled' => false,
        'user_table' => 'balp_usr',
        'username_field' => 'usr',
        'password_field' => 'psw',
        'role_field' => null,
        'password_algo' => 'old_password',
        'login_scheme' => 'usr_is_plain',
        'jwt_secret' => 'change_me',
        'jwt_ttl_minutes' => 120,
    ];
    $auth = $normalized['auth'] ?? [];
    if (!is_array($auth)) {
        $auth = [];
    }
    $auth = array_replace($authDefaults, $auth);
    $auth['enabled'] = !empty($auth['enabled']);
    $auth['jwt_ttl_minutes'] = (int)($auth['jwt_ttl_minutes'] ?? 120);
    if ($auth['jwt_ttl_minutes'] <= 0) {
        $auth['jwt_ttl_minutes'] = 120;
    }
    $normalized['auth'] = $auth;

    $tables = $normalized['tables'] ?? [];
    if (!is_array($tables)) {
        $tables = [];
    }
    ksort($tables);
    $normalized['tables'] = $tables;

    return $normalized;
}

function balp_write_config(array $config): array
{
    $normalized = balp_normalize_config($config);
    $path = balp_config_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Nepodařilo se vytvořit složku pro konfiguraci: %s', $dir));
        }
    }

    $export = var_export($normalized, true);
    $content = <<<PHP
<?php
// Tento soubor byl automaticky vygenerován modulem Nastavení dne %s.
\$config = %s;
\$CONFIG = \$config;
return \$config;

PHP;
    $payload = sprintf($content, date('c'), $export);
    if (@file_put_contents($path, $payload) === false) {
        throw new RuntimeException(sprintf('Nepodařilo se zapsat konfiguraci do %s', $path));
    }
    return $normalized;
}

function balp_require_authenticated_user(): array
{
    if (!has_auth()) {
        return ['sub' => 'anonymous', 'role' => null];
    }
    $user = auth_user();
    if (!$user) {
        respond_json(['error' => 'Vyžadováno přihlášení.'], 401);
    }
    return $user;
}
?>
