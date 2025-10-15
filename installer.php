<?php
require __DIR__ . '/helpers.php';
$extensions = [
  'pdo_mysql'=>extension_loaded('pdo_mysql'),
  'json'=>extension_loaded('json'),
  'mbstring'=>extension_loaded('mbstring'),
  'hash'=>extension_loaded('hash'),
];
$writable = is_writable(__DIR__.'/config');
$errors=[]; $ok=null; $show_config=null;

if ($_SERVER['REQUEST_METHOD']==='POST'){
  $cfg = [
    'app_url' => rtrim($_POST['app_url'] ?? '/balp2','/'),
    'db' => [
      'driver'=>'mysql',
      'host'=>trim($_POST['db_host'] ?? '127.0.0.1'),
      'port'=>(int)($_POST['db_port'] ?? 3306),
      'database'=>trim($_POST['db_name'] ?? ''),
      'username'=>trim($_POST['db_user'] ?? ''),
      'password'=>$_POST['db_pass'] ?? '',
      'charset'=>'utf8mb4',
      'collation'=>'utf8mb4_czech_ci',
    ],
    'auth' => [
      'enabled'=> isset($_POST['auth_enabled']),
      'user_table'=> trim($_POST['auth_user_table'] ?? 'balp_usr'),
      'username_field'=> trim($_POST['auth_username_field'] ?? 'usr'),
      'password_field'=> trim($_POST['auth_password_field'] ?? 'psw'),
      'role_field'=> trim($_POST['auth_role_field'] ?? ''),
      'password_algo'=> $_POST['auth_password_algo'] ?? 'md5_raw16',
      'login_scheme'=> $_POST['auth_login_scheme'] ?? 'usr_is_md5_username',
      'jwt_secret'=> $_POST['jwt_secret'] ?: bin2hex(random_bytes(16)),
      'jwt_ttl_minutes'=> (int)($_POST['jwt_ttl'] ?? 120),
    ],
  ];
  $err=null; $pdo=db_try_connect($cfg,$err);
  if(!$pdo) $errors[]="DB připojení selhalo: $err";
  $tables=[];
  if($pdo){
    try{ $tables=$pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=".$pdo->quote($cfg['db']['database'])." ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    }catch(Throwable $e){ $errors[]="Nelze načíst tabulky: ".$e->getMessage(); }
  }
  if(!$errors){
    $content = "<?php
return " . var_export($cfg, true) . ";
";
    $path = __DIR__ . '/config/config.php';
    if ($writable && @file_put_contents($path, $content)!==false) {
      $ok = "Konfigurace uložena. Nalezeno tabulek: ".count($tables);
    } else {
      $errors[] = "Nelze zapsat config.php — vytvořte jej ručně dle níže uvedeného obsahu.";
      $show_config = $content;
    }
  }
}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>BALP v2 — Instalátor</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:system-ui;margin:2rem;color:#222} fieldset{border:1px solid #ddd;padding:1rem;border-radius:.5rem;margin:1rem 0}
label{display:block;margin:.4rem 0 .2rem} input,select{padding:.5rem;border:1px solid #ccc;border-radius:.4rem;width:100%;max-width:520px}
button{padding:.6rem 1rem;border:0;border-radius:.5rem;background:#0d6efd;color:#fff;cursor:pointer}
.alert{padding:.75rem 1rem;border-radius:.5rem;margin:.5rem 0}.ok{background:#e7f6e7;border:1px solid #9ad29a}.err{background:#fde7e7;border:1px solid #f2a2a}
</style></head><body>
<h1>BALP v2 — Instalátor</h1>
<h2>Diagnostika</h2>
<ul>
  <li>PHP <?=phpversion()?> (>=7.4)</li>
  <li>Rozšíření: pdo_mysql <b><?=$extensions['pdo_mysql']?'OK':'CHYBÍ'?></b>, json <b><?=$extensions['json']?'OK':'CHYBÍ'?></b>, mbstring <b><?=$extensions['mbstring']?'OK':'CHYBÍ'?></b>, hash <b><?=$extensions['hash']?'OK':'CHYBÍ'?></b></li>
  <li>Write do <code>/config</code>: <b><?=$writable?'ANO':'NE'?></b></li>
</ul>
<?php if(!empty($errors)): ?><div class="alert err"><ul><?php foreach($errors as $e){ echo "<li>".htmlspecialchars($e)."</li>"; } ?></ul></div><?php endif; ?>
<?php if($ok): ?><div class="alert ok"><?=htmlspecialchars($ok)?></div>
<p><a href="health.php">Diagnostika</a> • <a href="db_probe.php">DB sonda</a> • <a href="public/app.html">Spustit aplikaci</a> • <a href="admin_users.php">Správa uživatelů</a></p>
<?php endif; ?>

<form method="post">
  <fieldset><legend>Obecné</legend>
    <label>App URL (base)</label><input name="app_url" value="<?=htmlspecialchars($_POST['app_url'] ?? '/balp2')?>">
  </fieldset>
  <fieldset><legend>Databáze</legend>
    <label>Host</label><input name="db_host" value="<?=htmlspecialchars($_POST['db_host'] ?? '127.0.0.1')?>">
    <label>Port</label><input name="db_port" value="<?=htmlspecialchars($_POST['db_port'] ?? '3306')?>">
    <label>Název DB</label><input name="db_name" value="<?=htmlspecialchars($_POST['db_name'] ?? 'balp_new')?>">
    <label>Uživatel</label><input name="db_user" value="<?=htmlspecialchars($_POST['db_user'] ?? '')?>">
    <label>Heslo</label><input name="db_pass" type="password" value="<?=htmlspecialchars($_POST['db_pass'] ?? '')?>">
  </fieldset>
  <fieldset><legend>Přihlášení (volitelné)</legend>
    <label><input type="checkbox" name="auth_enabled" <?=(isset($_POST['auth_enabled'])?'checked':'')?>> Zapnout přihlášení (JWT)</label>
    <label>Tabulka uživatelů</label><input name="auth_user_table" value="<?=htmlspecialchars($_POST['auth_user_table'] ?? 'balp_usr')?>">
    <label>Sloupec loginu</label><input name="auth_username_field" value="<?=htmlspecialchars($_POST['auth_username_field'] ?? 'usr')?>">
    <label>Sloupec hesla</label><input name="auth_password_field" value="<?=htmlspecialchars($_POST['auth_password_field'] ?? 'psw')?>">
    <label>Sloupec role (volitelné)</label><input name="auth_role_field" value="<?=htmlspecialchars($_POST['auth_role_field'] ?? '')?>">
    <label>Algoritmus hesla</label>
    <select name="auth_password_algo">
      <?php $algos=['bcrypt','md5','md5_raw16','old_password','plaintext']; $sel=$_POST['auth_password_algo'] ?? 'md5_raw16';
      foreach($algos as $a){ echo "<option value='$a' ".($a===$sel?'selected':'').">$a</option>"; } ?>
    </select>
    <label>Login schéma</label>
    <select name="auth_login_scheme">
      <?php $schemes=['usr_is_plain','usr_is_md5_username','usr_is_md5_lower','usr_is_md5_upper']; $ssel=$_POST['auth_login_scheme'] ?? 'usr_is_md5_username';
      foreach($schemes as $s){ echo "<option value='$s' ".($s===$ssel?'selected':'').">$s</option>"; } ?>
    </select>
    <label>JWT secret</label><input name="jwt_secret" value="<?=htmlspecialchars($_POST['jwt_secret'] ?? '')?>">
    <label>JWT TTL (min)</label><input name="jwt_ttl" value="<?=htmlspecialchars($_POST['jwt_ttl'] ?? '120')?>">
    <p><small>VARBINARY(16) vyžaduje např. <b>md5_raw16</b> nebo <b>old_password</b>.</small></p>
  </fieldset>
  <button>Uložit konfiguraci a otestovat DB</button>
</form>

<?php if($show_config): ?>
<h2>Vytvořte ručně <code>/config/config.php</code> s tímto obsahem:</h2>
<pre><?=htmlspecialchars($show_config)?></pre>
<?php endif; ?>

<p style="margin-top:1rem"><a href="health.php">Diagnostika</a> • <a href="db_probe.php">DB sonda</a> • <a href="admin_users.php">Správa uživatelů</a></p>
</body></html>
