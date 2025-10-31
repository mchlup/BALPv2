<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
header('Content-Type: application/json; charset=utf-8');
function out($d,$c=200){ http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

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
  $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND=>"SET NAMES utf8mb4"]);

  // načíst původní
  $st = $pdo->prepare("SELECT * FROM balp_pol WHERE id = :id"); $st->execute([':id'=>$id]); $row = $st->fetch();
  if (!$row) out(['error'=>'not found'],404);

  // připravit data pro vložení – 'cislo' vynecháme (kolize), 'nazev' doplníme "(kopie)"
  unset($row['id']);
  if (isset($row['cislo'])) $row['cislo'] = null;
  if (isset($row['nazev'])) $row['nazev'] = trim((string)$row['nazev']) . ' (kopie)';

  $cols = '`' . implode('`,`', array_keys($row)) . '`';
  $vals = ':' . implode(',:', array_keys($row));
  $sql = "INSERT INTO balp_pol ($cols) VALUES ($vals)";
  $ins = $pdo->prepare($sql);
  foreach ($row as $k=>$v) $ins->bindValue(':'.$k, $v);
  $ins->execute();
  $newId = (int)$pdo->lastInsertId();
  out(['ok'=>true,'id'=>$newId]);
} catch (Throwable $e) { out(['error'=>$e->getMessage()],500); }

