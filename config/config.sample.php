<?php
return [
    'app_url' => '/balp2',
    'db_dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=balp_new;charset=utf8mb4',
    'db_user' => 'root',
    'db_pass' => '',
    'db' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'balp_new',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_czech_ci',
    ],
    'auth' => [
        'enabled' => false,
        'user_table' => 'balp_usr',
        'username_field' => 'usr',
        'password_field' => 'psw',
        'role_field' => null,
        'password_algo' => 'old_password',
        'login_scheme' => 'usr_is_plain',
        'jwt_secret' => 'change_me',
        'jwt_ttl_minutes' => 120,
    ],
    'tables' => [
        'nh' => 'balp_nhods',
    ],
];
