<?php
// /balp2/api/auth_login_ref.php
// Přihlášení uživatele podle configu (OLD_PASSWORD podpora + JWT)

require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';

// --- Načti config ---
$config_file = dirname(__DIR__) . '/config/config.php';
$CONFIG = [];
if (file_exists($config_file)) {
    require $config_file;
}
$A = $CONFIG['auth'] ?? [];

$tbl   = $A['user_table']      ?? 'balp_usr';
$colU  = $A['username_field']  ?? 'usr';
$colP  = $A['password_field']  ?? 'psw';
$algo  = strtolower($A['password_algo'] ?? 'old_password');
$JWT_SECRET = $A['jwt_secret'] ?? 'change_this_secret';
$TTL  = max(60, (int)($A['jwt_ttl_minutes'] ?? 120)) * 60;

// --- OLD_PASSWORD() implementace (MySQL <4.1, 16 hex) ---
function mysql_old_password_hash($password) {
    $nr  = 1345345333;
    $add = 7;
    $nr2 = 0x12345671;
    for ($i=0, $len=strlen($password); $i<$len; $i++) {
        $c = ord($password[$i]);
        if ($c == 32 || $c == 9) continue;
        $nr  ^= (($nr & 63) + $add) * $c + ($nr << 8);
        $nr2 += ($nr2 << 8) ^ $nr;
        $add += $c;
    }
    $nr  &= 0x7FFFFFFF;
    $nr2 &= 0x7FFFFFFF;
    return sprintf("%08x%08x", $nr, $nr2);
}

// --- Načti vstup ---
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
if ($username === '' || $password === '') {
    balp_send_json(['error'=>'missing credentials'], 400);
}

// --- Připojení k DB ---
try {
    if (!isset($pdo)) {
        $db_dsn  = $CONFIG['db_dsn']  ?? getenv('BALP_DB_DSN');
        $db_user = $CONFIG['db_user'] ?? getenv('BALP_DB_USER');
        $db_pass = $CONFIG['db_pass'] ?? getenv('BALP_DB_PASS');
        if (!$db_dsn) throw new Exception('DB DSN missing');
        $pdo = new PDO($db_dsn, $db_user, $db_pass, balp_utf8_pdo_options());
    }

    // --- Načti uživatele ---
    $stmt = $pdo->prepare("SELECT * FROM `$tbl` WHERE `$colU` = :u LIMIT 1");
    $stmt->execute([':u'=>$username]);
    $row = $stmt->fetch();
    if (!$row) {
        balp_send_json(['error'=>'user not found'], 401);
    }

    $stored = (string)($row[$colP] ?? '');
    $ok = false;

    // --- Ověření hesla ---
    switch ($algo) {
        case 'old_password':
            $ok = (strlen($stored) === 16 && strcasecmp($stored, mysql_old_password_hash($password)) === 0);
            break;
        case 'mysql41_password': // * + 40 hex
            $ok = (strlen($stored) === 41 && $stored[0] === '*' &&
                   strcasecmp($stored, '*' . strtoupper(sha1(sha1($password, true)))) === 0);
            break;
        case 'md5':
            $ok = (strlen($stored) === 32 && strcasecmp($stored, md5($password)) === 0);
            break;
        case 'password_hash':
            $ok = password_verify($password, $stored);
            break;
        case 'plaintext':
            $ok = ($stored === $password);
            break;
    }

    if (!$ok) {
        balp_send_json(['error'=>'invalid password'], 401);
    }

    // --- Volitelná migrace na password_hash ---
    if ($ok && $algo !== 'password_hash') {
        $new = password_hash($password, PASSWORD_BCRYPT);
        try {
            $up = $pdo->prepare("UPDATE `$tbl` SET `$colP` = :p WHERE `$colU` = :u");
            $up->execute([':p'=>$new, ':u'=>$username]);
            // pak můžeš v configu přepnout na 'password_hash'
        } catch (Throwable $e) {
            // ignoruj chyby při updatu
        }
    }

    // --- JWT token ---
    $now = time();
    $payload = [
        'sub' => $username,
        'iat' => $now,
        'exp' => $now + $TTL,
    ];
    $token = jwt_encode($payload, $JWT_SECRET);

    // --- Nastav cookie ---
    setcookie('balp_token', $token, [
        'expires' => $now + $TTL,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    balp_send_json(['ok'=>true, 'token'=>$token, 'user'=>['username'=>$username]]);
}
catch (Exception $e) {
    balp_send_json(['error'=>$e->getMessage()], 500);
}

