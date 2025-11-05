<?php
require_once __DIR__ . '/api/api_patch.php';  // přidá action=auth_login, action=auth_me
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/modules/bootstrap.php';
$c = cfg(); $pdo = db();
$action = $_GET['action'] ?? '';

function balp_table_get_metadata(PDO $pdo, string $table, bool $forceReload = false): array
{
    static $cache = [];
    global $c;
    $schema = $c['db']['database'] ?? '';
    $key = strtolower($schema . '.' . $table);
    if ($forceReload) {
        unset($cache[$key]);
    }
    if (!isset($cache[$key])) {
        $columns = [];
        $primaryKey = [];
        $textColumns = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, '
                . 'CHARACTER_MAXIMUM_LENGTH, COLUMN_COMMENT, ORDINAL_POSITION '
                . 'FROM INFORMATION_SCHEMA.COLUMNS '
                . 'WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table '
                . 'ORDER BY ORDINAL_POSITION'
            );
            $stmt->execute([':db' => $schema, ':table' => $table]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columnName = $row['COLUMN_NAME'];
                $columns[$columnName] = $row;
                if (($row['COLUMN_KEY'] ?? '') === 'PRI') {
                    $primaryKey[] = $columnName;
                }
                $dataType = strtolower((string)($row['DATA_TYPE'] ?? ''));
                if (in_array($dataType, ['varchar', 'text', 'char', 'tinytext', 'mediumtext', 'longtext'], true)) {
                    $textColumns[] = $columnName;
                }
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $columns = [];
            $primaryKey = [];
            $textColumns = [];
        }

        $cache[$key] = [
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'text_columns' => $textColumns,
        ];
    }

    return $cache[$key];
}

function balp_table_get_columns(PDO $pdo, string $table, bool $forceReload = false): array
{
    return balp_table_get_metadata($pdo, $table, $forceReload)['columns'];
}

function balp_table_get_primary_key_columns(PDO $pdo, string $table, bool $forceReload = false): array
{
    return balp_table_get_metadata($pdo, $table, $forceReload)['primary_key'];
}

function balp_table_get_text_columns(PDO $pdo, string $table, bool $forceReload = false): array
{
    return balp_table_get_metadata($pdo, $table, $forceReload)['text_columns'];
}

function require_auth(){ if(!has_auth())return; if(!auth_user()) respond_json(['error'=>'Unauthorized'],401); }

if ($action==='_meta_tables'){
  $db=$c['db']['database'];
  $st=$pdo->prepare("SELECT TABLE_NAME name, TABLE_COMMENT comment FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db ORDER BY 1");
  $st->execute([':db'=>$db]); respond_json(['tables'=>$st->fetchAll()]);
}
if ($action==='_modules'){
  $modules = array_values(array_map(function(array $module){
    $uiTabs = [];
    foreach (($module['ui']['tabs'] ?? []) as $tab) {
        $uiTabs[] = [
            'slug' => $tab['slug'] ?? ($module['slug'] . '-tab'),
            'label' => $tab['label'] ?? ($tab['slug'] ?? $module['name'] ?? $module['slug']),
            'order' => $tab['order'] ?? 100,
            'view' => $tab['view'] ?? null,
            'tab_id' => $tab['tab_id'] ?? null,
            'pane_id' => $tab['pane_id'] ?? null,
        ];
    }
    $uiAssets = [
        'css' => array_values($module['ui']['assets']['css'] ?? []),
        'js' => array_values($module['ui']['assets']['js'] ?? []),
    ];
    $order = $module['order'] ?? $module['ui']['order'] ?? 100;
    return [
      'slug' => $module['slug'],
      'name' => $module['name'] ?? $module['slug'],
      'description' => $module['description'] ?? '',
      'order' => $order,
      'assets' => $module['assets'] ?? [],
      'ui' => [
        'tabs' => $uiTabs,
        'assets' => $uiAssets,
        'icon' => $module['ui']['icon'] ?? null,
      ],
    ];
  }, balp_modules_registry()));
  respond_json(['modules' => $modules]);
}
if ($action==='_meta_columns'){
  $t=$_GET['table'] ?? '';
  $metadata = balp_table_get_metadata($pdo, $t);
  $cols = array_map(static function (array $column) {
    return [
      'name' => $column['COLUMN_NAME'],
      'type' => $column['DATA_TYPE'],
      'column_type' => $column['COLUMN_TYPE'],
      'column_key' => $column['COLUMN_KEY'],
      'is_nullable' => $column['IS_NULLABLE'],
      'default_value' => $column['COLUMN_DEFAULT'],
      'extra' => $column['EXTRA'],
      'maxlen' => $column['CHARACTER_MAXIMUM_LENGTH'],
      'comment' => $column['COLUMN_COMMENT'],
    ];
  }, array_values($metadata['columns']));
  respond_json(['columns'=>$cols,'primaryKey'=>$metadata['primary_key']]);
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
      $h16 = balp_old_password_hex($pwd, $pdo); // 16 HEX
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
  $columns = balp_table_get_columns($pdo, $t);
  if (!$columns) respond_json(['error'=>'Unknown table'],400);
  $textCols=balp_table_get_text_columns($pdo,$t);
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
  $pkColumns=balp_table_get_primary_key_columns($pdo,$t);
  if(count($pkColumns)!==1) respond_json(['error'=>'Unsupported PK'],400);
  $pk=$pkColumns[0]; $qt=str_replace('`','``',$t); $qpk=str_replace('`','``',$pk);
  $st=$pdo->prepare("SELECT * FROM `{$qt}` WHERE `{$qpk}`=:id"); $st->execute([':id'=>$id]);
  $row=$st->fetch(); if(!$row) respond_json(['error'=>'Not found'],404); respond_json($row);
}
if ($action==='create'){
  require_auth(); $t=$_GET['table'] ?? ''; $b=request_json();
  $columns = balp_table_get_columns($pdo, $t);
  if(!$columns) respond_json(['error'=>'Unknown table'],400);
  $ins=[]; foreach($columns as $col){ if(stripos((string)($col['EXTRA'] ?? ''),'auto_increment')===false) $ins[]=$col['COLUMN_NAME']; }
  $fields=array_values(array_intersect(array_keys($b), $ins)); if(!$fields) respond_json(['error'=>'No insertable fields'],400);
  $place = implode(',', array_map(fn($f)=>":$f",$fields)); $cols='`'.implode('`,`', array_map(fn($f)=>str_replace('`','``',$f), $fields)).'`';
  $qt=str_replace('`','``',$t);
  $sql="INSERT INTO `{$qt}` ($cols) VALUES ($place)"; $st=$pdo->prepare($sql);
  foreach($fields as $f) $st->bindValue(":$f",$b[$f]); $st->execute(); respond_json(['inserted_id'=>$pdo->lastInsertId()],201);
}
if ($action==='update'){
  require_auth(); $t=$_GET['table'] ?? ''; $id=$_GET['id'] ?? ''; $b=request_json();
  $pkColumns=balp_table_get_primary_key_columns($pdo,$t);
  if(count($pkColumns)!==1) respond_json(['error'=>'Unsupported PK'],400);
  $pk=$pkColumns[0];
  $columns = balp_table_get_columns($pdo,$t);
  if(!$columns) respond_json(['error'=>'Unknown table'],400);
  $ups=[]; foreach($columns as $col){ if(stripos((string)($col['EXTRA'] ?? ''),'auto_increment')===false) $ups[]=$col['COLUMN_NAME']; }
  $fields=array_values(array_intersect(array_keys($b), $ups)); if(!$fields) respond_json(['error'=>'No updatable fields'],400);
  $assign = implode(',', array_map(fn($f)=>'`'.str_replace('`','``',$f)."`=:$f",$fields));
  $qt=str_replace('`','``',$t); $qpk=str_replace('`','``',$pk);
  $sql="UPDATE `{$qt}` SET $assign WHERE `{$qpk}`=:id"; $st=$pdo->prepare($sql);
  foreach($fields as $f) $st->bindValue(":$f",$b[$f]); $st->bindValue(':id',$id); $st->execute(); respond_json(['updated'=>true]);
}
if ($action==='delete'){
  require_auth(); $t=$_GET['table'] ?? ''; $id=$_GET['id'] ?? '';
  $pkColumns=balp_table_get_primary_key_columns($pdo,$t);
  if(count($pkColumns)!==1) respond_json(['error'=>'Unsupported PK'],400);
  $pk=$pkColumns[0]; $qt=str_replace('`','``',$t); $qpk=str_replace('`','``',$pk);
  $st=$pdo->prepare("DELETE FROM `{$qt}` WHERE `{$qpk}`=:id"); $st->execute([':id'=>$id]); respond_json(['deleted'=>$st->rowCount()>0]);
}

respond_json(['error'=>'Unknown action'],400);

