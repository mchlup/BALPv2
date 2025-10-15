<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
header('Content-Type: application/json; charset=utf-8');
function out($d,$c=200){ http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
  $config_file = dirname(__DIR__).'/config/config.php'; $CONFIG=[]; if (file_exists($config_file)) require $config_file;
  $JWT_SECRET = $CONFIG['auth']['jwt_secret'] ?? ($CONFIG['jwt_secret'] ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret'));
  $token = balp_get_bearer_token(); if (!$token) out(['error'=>'missing token'],401);
  try { jwt_decode($token, $JWT_SECRET, true); } catch (Exception $e) { out(['error'=>$e->getMessage()],401); }

  $raw = file_get_contents('php://input'); $b = json_decode($raw,true) ?: [];
  $id = isset($b['id']) ? (int)$b['id'] : 0;
  $fields = ['cislo','nazev','sh','sh_skut','sus_sh','sus_hmot','sus_obj','okp','kvn','olej','pozn','dt_akt_sloz','dtod','dtdo'];
  $data = [];
  foreach ($fields as $f) { $data[$f] = array_key_exists($f,$b) ? ($b[$f]!==''?$b[$f]:null) : null; }
  if (!($data['nazev'] ?? '')) out(['error'=>'nazev is required'],400);

  $dsn = $CONFIG['db_dsn'] ?? getenv('BALP_DB_DSN');
  $user= $CONFIG['db_user']?? getenv('BALP_DB_USER');
  $pass= $CONFIG['db_pass']?? getenv('BALP_DB_PASS');
  if (!$dsn) out(['error'=>'DB DSN missing'],500);
  $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND=>"SET NAMES utf8mb4"]);

  if ($id>0) {
    $sets = implode(', ', array_map(fn($f)=>"`$f` = :$f",$fields));
    $sql = "UPDATE balp_pol SET $sets WHERE id = :id";
    $st = $pdo->prepare($sql);
    foreach ($fields as $f) $st->bindValue(":$f", $data[$f]);
    $st->bindValue(':id',$id,PDO::PARAM_INT);
    $st->execute();
    out(['ok'=>true,'id'=>$id,'mode'=>'update']);
  } else {
    $cols = '`'.implode('`,`',$fields).'`';
    $vals = ':'.implode(',:',$fields);
    $sql = "INSERT INTO balp_pol ($cols) VALUES ($vals)";
    $st = $pdo->prepare($sql);
    foreach ($fields as $f) $st->bindValue(":$f", $data[$f]);
    $st->execute();
    $newId = (int)$pdo->lastInsertId();
    out(['ok'=>true,'id'=>$newId,'mode'=>'insert']);
  }
} catch (Throwable $e) { out(['error'=>$e->getMessage()],500); }

