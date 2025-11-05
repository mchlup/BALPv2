<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Content-Language: cs');
function out($d,$c=200){ http_response_code($c); echo json_encode(balp_to_utf8($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
  $config = cfg();
  $JWT_SECRET = ($config['auth']['jwt_secret'] ?? $config['jwt_secret']) ?? (getenv('BALP_JWT_SECRET') ?: 'change_this_secret');
  $token = balp_get_bearer_token(); if (!$token) out(['error'=>'missing token'],401);
  try { jwt_decode($token,$JWT_SECRET,true); } catch(Throwable $e){ error_log($e->getMessage()); out(['error'=>'Nastala chyba.'],401); }

  $id = (int)($_GET['id'] ?? 0); if ($id<=0) out(['error'=>'missing id'],400);

  $pdo = db();

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
} catch (Throwable $e) { error_log($e->getMessage()); out(['error'=>'Nastala chyba.'],500); }

