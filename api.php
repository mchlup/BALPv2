<?php
require_once __DIR__ . '/api/api_patch.php';  // přidá action=auth_login, action=auth_me
require __DIR__ . '/helpers.php';
$c = cfg(); $pdo = db();
$action = $_GET['action'] ?? '';

function require_auth(){ if(!has_auth())return; if(!auth_user()) respond_json(['error'=>'Unauthorized'],401); }

/** OLD_PASSWORD() → 16 hex znaků (ASCII). Když DB funkce není, použij PHP fallback. */
function mysql_old_password_hex_php(string $pwd): string {
    $nr = 1345345333; $add = 7; $nr2 = 0x12345671;
    $pwd = str_replace(["\r","\n"], '', $pwd);
    $len = strlen($pwd);
    for ($i=0; $i<$len; $i++) {
        $c = $pwd[$i];
        if ($c === ' ' || $c === "\t") continue;
        $tmp = ord($c);
        $nr ^= ((($nr & 63) + $add) * $tmp) + ($nr << 8);
        $nr &= 0xFFFFFFFF;
        $nr2 += ($nr2 << 8) ^ $nr;
        $nr2 &= 0xFFFFFFFF;
        $add += $tmp; $add &= 0xFFFFFFFF;
    }
    $res1 = $nr & 0x7FFFFFFF; $res2 = $nr2 & 0x7FFFFFFF;
    return strtoupper(sprintf("%08x%08x", $res1, $res2)); // 16 HEX
}
function old_password_ascii_hex(string $pwd, PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT OLD_PASSWORD(".$pdo->quote($pwd).")");
        $h = $stmt ? $stmt->fetchColumn() : null;
        if ($h && preg_match('/^[0-9A-Fa-f]{16}$/', $h)) return strtoupper($h);
    } catch (Throwable $e) { /* ignore */ }
    return mysql_old_password_hex_php($pwd);
}

if ($action==='_meta_tables'){
  $db=$c['db']['database'];
  $st=$pdo->prepare("SELECT TABLE_NAME name, TABLE_COMMENT comment FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db ORDER BY 1");
  $st->execute([':db'=>$db]); respond_json(['tables'=>$st->fetchAll()]);
}
if ($action==='_meta_columns'){
  $db=$c['db']['database']; $t=$_GET['table'] ?? '';
  $cs=$pdo->prepare("SELECT COLUMN_NAME name, DATA_TYPE type, COLUMN_TYPE column_type, COLUMN_KEY column_key, IS_NULLABLE is_nullable, COLUMN_DEFAULT default_value, EXTRA extra, CHARACTER_MAXIMUM_LENGTH maxlen, COLUMN_COMMENT comment FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION");
  $cs->execute([':db'=>$db, ':t'=>$t]); $cols=$cs->fetchAll();
  $pk=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
  $pk->execute([':db'=>$db, ':t'=>$t]); $pks=array_map(fn($r)=>$r['COLUMN_NAME'],$pk->fetchAll());
  respond_json(['columns'=>$cols,'primaryKey'=>$pks]);
}

if ($action==='auth_login'){
  if(empty($c['auth']['enabled'])) respond_json(['error'=>'Auth disabled'],400);
  $b=request_json(); $login=$b['username']??''; $pwd=$b['password']??'';
  $tab=$c['auth']['user_table']; $uf=$c['auth']['username_field']; $pf=$c['auth']['password_field']; $role=$c['auth']['role_field'] ?? '';

  switch($c['auth']['login_scheme'] ?? 'usr_is_plain'){
    case 'usr_is_md5_username': $val=pack('H*',md5($login)); break;
    case 'usr_is_md5_lower':    $val=pack('H*',md5(strtolower($login))); break;
    case 'usr_is_md5_upper':    $val=pack('H*',md5(strtoupper($login))); break;
    default: $val=$login; // usr_is_plain
  }

  $st=$pdo->prepare("SELECT * FROM `$tab` WHERE `$uf`=:u LIMIT 1");
  $st->bindValue(':u',$val,PDO::PARAM_LOB);
  $st->execute();
  $user=$st->fetch();
  if(!$user) respond_json(['error'=>'Invalid credentials (user)'],401);

  $algo=$c['auth']['password_algo'] ?? 'bcrypt'; $ok=false;
  if($algo==='bcrypt'){
      $ok = password_verify($pwd,$user[$pf]);
  } elseif($algo==='md5'){
      $ok = (strtolower(md5($pwd))===strtolower($user[$pf]));
  } elseif($algo==='md5_raw16'){
      $ok = (strtoupper(bin2hex($user[$pf]))===strtoupper(md5($pwd)));
  } elseif($algo==='old_password'){
      $h16 = old_password_ascii_hex($pwd, $pdo); // 16 HEX
      $stored = $user[$pf];
      if ($stored!==null) {
          if (is_string($stored) && strlen($stored)===16 && ctype_xdigit($stored)) {
              // ASCII 16-hex v poli
              $ok = (strtoupper($stored) === $h16);
          } else {
              // RAW 8 bajtů (UNHEX(OLD_PASSWORD()))
              $ok = (strtoupper(bin2hex($stored)) === $h16);
          }
      }
  } elseif($algo==='plaintext'){
      $ok = ($pwd===$user[$pf]);
  }

  if(!$ok) respond_json(['error'=>'Invalid credentials (password)'],401);

  $payload=['sub'=>$login,'role'=>($role && isset($user[$role]))?$user[$role]:null,'iat'=>time(),'exp'=>time()+max(60,(int)($c['auth']['jwt_ttl_minutes']??120)*60)];
  $token=jwt_encode($payload,$c['auth']['jwt_secret'] ?? 'change');
  // Nastav i cookie fallback (pro servery které zahazují Authorization header)
  $path = rtrim($c['app_url'] ?? '/', '/') ?: '/';
  @setcookie('balp_token', $token, [
      'expires' => $payload['exp'],
      'path' => $path,
      'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
      'httponly' => false,
      'samesite' => 'Lax',
  ]);
  respond_json(['token'=>$token,'user'=>['username'=>$login,'role'=>$payload['role']]]);


if ($action==='auth_me'){
  $u = auth_user();
  if(!$u) respond_json(['error'=>'Unauthorized'],401);
  respond_json(['ok'=>true,'user'=>$u]);
}
}

if ($action==='list'){
  require_auth(); $t=$_GET['table'] ?? '';
  $limit=max(1,min(1000,(int)($_GET['limit']??100))); $offset=max(0,(int)($_GET['offset']??0)); $q=$_GET['q'] ?? '';
  $dbn=$c['db']['database'];
  $tc=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND DATA_TYPE IN ('varchar','text','char','mediumtext','longtext')");
  $tc->execute([':d'=>$dbn, ':t'=>$t]); $textCols=array_map(fn($r)=>$r['COLUMN_NAME'],$tc->fetchAll());
  $qt=str_replace('`','``',$t);
  $sql="SELECT * FROM `{$qt}`"; $params=[];
  if($q && $textCols){ $likes=array_map(fn($c)=>"`".str_replace('`','``',$c)."` LIKE :q",$textCols); $sql.=" WHERE ".implode(' OR ',$likes); $params[':q']="%$q%"; }
  $sql.=" LIMIT :limit OFFSET :offset"; $st=$pdo->prepare($sql); foreach($params as $k=>$v) $st->bindValue($k,$v);
  $st->bindValue(':limit',(int)$limit,PDO::PARAM_INT); $st->bindValue(':offset',(int)$offset,PDO::PARAM_INT); $st->execute();
  $rows=$st->fetchAll(); $count=(int)$pdo->query("SELECT COUNT(*) FROM `{$qt}`")->fetchColumn();
  respond_json(['items'=>$rows,'limit'=>$limit,'offset'=>$offset,'total'=>$count]);
}
if ($action==='detail'){
  require_auth(); $t=$_GET['table'] ?? ''; $id=$_GET['id'] ?? '';
  $pkq=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
  $pkq->execute([':db'=>$c['db']['database'], ':t'=>$t]); $pks=$pkq->fetchAll(PDO::FETCH_COLUMN); if(count($pks)!==1) respond_json(['error'=>'Unsupported PK'],400);
  $pk=$pks[0]; $qt=str_replace('`','``',$t); $qpk=str_replace('`','``',$pk);
  $st=$pdo->prepare("SELECT * FROM `{$qt}` WHERE `{$qpk}`=:id"); $st->execute([':id'=>$id]);
  $row=$st->fetch(); if(!$row) respond_json(['error'=>'Not found'],404); respond_json($row);
}
if ($action==='create'){
  require_auth(); $t=$_GET['table'] ?? ''; $b=request_json();
  $cs=$pdo->prepare("SELECT COLUMN_NAME, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t"); $cs->execute([':db'=>$c['db']['database'], ':t'=>$t]);
  $all=$cs->fetchAll(); $ins=[]; foreach($all as $col){ if(strpos($col['EXTRA'],'auto_increment')===false) $ins[]=$col['COLUMN_NAME']; }
  $fields=array_values(array_intersect(array_keys($b), $ins)); if(!$fields) respond_json(['error'=>'No insertable fields'],400);
  $place = implode(',', array_map(fn($f)=>":$f",$fields)); $cols='`'.implode('`,`', array_map(fn($f)=>str_replace('`','``',$f), $fields)).'`';
  $qt=str_replace('`','``',$t);
  $sql="INSERT INTO `{$qt}` ($cols) VALUES ($place)"; $st=$pdo->prepare($sql);
  foreach($fields as $f) $st->bindValue(":$f",$b[$f]); $st->execute(); respond_json(['inserted_id'=>$pdo->lastInsertId()],201);
}
if ($action==='update'){
  require_auth(); $t=$_GET['table'] ?? ''; $id=$_GET['id'] ?? ''; $b=request_json();
  $pkq=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
  $pkq->execute([':db'=>$c['db']['database'], ':t'=>$t]); $pks=$pkq->fetchAll(PDO::FETCH_COLUMN); if(count($pks)!==1) respond_json(['error'=>'Unsupported PK'],400);
  $pk=$pks[0]; $cs=$pdo->prepare("SELECT COLUMN_NAME, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t"); $cs->execute([':db'=>$c['db']['database'], ':t'=>$t]);
  $all=$cs->fetchAll(); $ups=[]; foreach($all as $col){ if(strpos($col['EXTRA'],'auto_increment')===false) $ups[]=$col['COLUMN_NAME']; }
  $fields=array_values(array_intersect(array_keys($b), $ups)); if(!$fields) respond_json(['error'=>'No updatable fields'],400);
  $assign = implode(',', array_map(fn($f)=>'`'.str_replace('`','``',$f)."`=:$f",$fields));
  $qt=str_replace('`','``',$t); $qpk=str_replace('`','``',$pk);
  $sql="UPDATE `{$qt}` SET $assign WHERE `{$qpk}`=:id"; $st=$pdo->prepare($sql);
  foreach($fields as $f) $st->bindValue(":$f",$b[$f]); $st->bindValue(':id',$id); $st->execute(); respond_json(['updated'=>true]);
}
if ($action==='delete'){
  require_auth(); $t=$_GET['table'] ?? ''; $id=$_GET['id'] ?? '';
  $pkq=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
  $pkq->execute([':db'=>$c['db']['database'], ':t'=>$t]); $pks=$pkq->fetchAll(PDO::FETCH_COLUMN); if(count($pks)!==1) respond_json(['error'=>'Unsupported PK'],400);
  $pk=$pks[0]; $qt=str_replace('`','``',$t); $qpk=str_replace('`','``',$pk);
  $st=$pdo->prepare("DELETE FROM `{$qt}` WHERE `{$qpk}`=:id"); $st->execute([':id'=>$id]); respond_json(['deleted'=>$st->rowCount()>0]);
}

respond_json(['error'=>'Unknown action'],400);

