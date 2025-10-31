<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');
function out($d,$c=200){ http_response_code($c); echo json_encode(balp_to_utf8($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
  $config_file = dirname(__DIR__).'/config/config.php'; $CONFIG=[]; if (file_exists($config_file)) require $config_file;
  $JWT_SECRET = $CONFIG['auth']['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token(); if (!$token) out(['error'=>'missing token'],401);
  try { jwt_decode($token,$JWT_SECRET,true); } catch(Exception $e){ out(['error'=>$e->getMessage()],401); }

  $id = (int)($_GET['id'] ?? 0); if ($id<=0) out(['error'=>'missing id'],400);

  $dsn = $CONFIG['db_dsn'] ?? getenv('BALP_DB_DSN');
  $user= $CONFIG['db_user']?? getenv('BALP_DB_USER');
  $pass= $CONFIG['db_pass']?? getenv('BALP_DB_PASS');
  if (!$dsn) out(['error'=>'DB DSN missing'],500);
  $pdo = new PDO($dsn,$user,$pass, balp_utf8_pdo_options());

  $st = $pdo->prepare("DELETE FROM balp_pol WHERE id = :id"); $st->execute([':id'=>$id]);
  out(['ok'=>true,'id'=>$id]);
} catch (Throwable $e) { out(['error'=>$e->getMessage()],500); }

