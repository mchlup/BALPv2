<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../modules/bootstrap.php';

balp_require_authenticated_user();

$config = balp_normalize_config(cfg());

respond_json([
    'config' => $config,
]);
