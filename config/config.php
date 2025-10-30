<?php
// Unified BALP v2 config — compatible with both old ($CONFIG + flat DSN) and new (return array with 'db' section)
//
// This file DEFINES $CONFIG and also RETURNS the same array, so include/require
// gives you the config array directly (used by helpers.php: cfg()) AND legacy
// endpoints that expect $CONFIG[...] keep working.

$db_dsn  = getenv('BALP_DB_DSN')  ?: 'mysql:host=127.0.0.1;dbname=balp_new;charset=utf8mb4';
$db_user = getenv('BALP_DB_USER') ?: 'root';
$db_pass = getenv('BALP_DB_PASS') ?: 'R@d_iT,NAS24';

$CONFIG = [
  // Flat DSN form (used by existing API endpoints)
  'db_dsn'  => $db_dsn,
  'db_user' => $db_user,
  'db_pass' => $db_pass,

  // Structured form (used by helpers.php)
  'db' => [
    'driver'   => 'mysql',
    'host'     => preg_match('~host=([^;]+)~', $db_dsn, $m1) ? $m1[1] : '127.0.0.1',
    'port'     => (preg_match('~port=(\d+)~', $db_dsn, $m2) ? (int)$m2[1] : 3306),
    'database' => preg_match('~dbname=([^;]+)~', $db_dsn, $m3) ? $m3[1] : 'balp_new',
    'username' => $db_user,
    'password' => $db_pass,
    'charset'  => (preg_match('~charset=([^;]+)~', $db_dsn, $m4) ? $m4[1] : 'utf8mb4'),
    'collation'=> 'utf8mb4_czech_ci',
  ],

  'auth' => [
    'enabled' => true,
    'user_table'     => 'balp_usr',
    'username_field' => 'usr',      // VARBINARY(16) or VARCHAR depending on legacy DB
    'password_field' => 'psw',      // VARBINARY(16) (MySQL OLD_PASSWORD raw16) or CHAR(16)
    // bcrypt | md5 | md5_raw16 | old_password | plaintext
    'password_algo'  => 'old_password',
    // usr_is_plain | usr_is_md5_username | usr_is_md5_lower | usr_is_md5_upper
    'login_scheme'   => 'usr_is_plain',
    'jwt_secret'     => getenv('BALP_JWT_SECRET') ?: '6adb6bea2f350ed900b5d48791f56799',
    'jwt_ttl_minutes'=> 120,
  ],

  'tables' => [
    'nh' => 'balp_nh',
  ],
];

return $CONFIG;
