<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/helpers.php';

$isAjax = (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
  || (isset($_GET['embed']) && $_GET['embed'] == '1')
);

$c   = cfg();
$err = null;
$pdo = db_try_connect($c, $err);
if (!$pdo) { die($isAjax ? '<div class="alert alert-danger">Chyba DB: '.htmlspecialchars($err).'</div>' :
  "<!doctype html><html><head><meta charset='utf-8'><title>Chyba DB</title></head><body><h1>Chyba DB</h1><pre>".
  htmlspecialchars($err)."</pre></body></html>"); }

$schema = $c['db']['database'];
$tab    = $c['auth']['user_table'] ?? 'balp_usr';
$uf     = $c['auth']['username_field'] ?? 'usr';
$pf     = $c['auth']['password_field'] ?? 'psw';

/** Zjištění PK (i složeného) */
$pk = null; $composite_pk = null;
try {
  $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                       WHERE TABLE_SCHEMA=:s AND TABLE_NAME=:t AND CONSTRAINT_NAME='PRIMARY'
                       ORDER BY ORDINAL_POSITION");
  $st->execute([':s'=>$schema, ':t'=>$tab]);
  $pks = $st->fetchAll(PDO::FETCH_COLUMN);
  if (count($pks) === 1)      $pk = $pks[0];
  elseif (count($pks) > 1)    $composite_pk = implode(', ', $pks);
  else {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA=:s AND TABLE_NAME=:t AND EXTRA LIKE '%auto_increment%' LIMIT 1");
    $st->execute([':s'=>$schema, ':t'=>$tab]);
    $pk = $st->fetchColumn() ?: null;
  }
} catch (Throwable $e) { /* ignore */ }

/** Datové sloupce */
$has_dtod = $has_dtdo = false;
$columns_meta = [];
$password_column_meta = null;
try {
  $ci = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:s AND TABLE_NAME=:t");
  $ci->execute([':s'=>$schema, ':t'=>$tab]);
  $columns_meta = $ci->fetchAll(PDO::FETCH_ASSOC);
  foreach ($columns_meta as $col) {
    $name = $col['COLUMN_NAME'];
    if ($name === 'dtod') $has_dtod = true;
    if ($name === 'dtdo') $has_dtdo = true;
    if ($name === $pf) $password_column_meta = $col;
  }
} catch (Throwable $e) {}

$password_store_raw8 = null;
try {
  $probe = $pdo->query("SELECT HEX(`$pf`) FROM `$tab` WHERE `$pf` IS NOT NULL AND `$pf`<>'' LIMIT 1");
  $existing = $probe ? $probe->fetchColumn() : null;
  if (is_string($existing) && $existing !== '') {
    $password_store_raw8 = (strlen($existing) === 16);
  }
} catch (Throwable $e) {}
if ($password_store_raw8 === null && $password_column_meta) {
  $type = strtolower((string)($password_column_meta['DATA_TYPE'] ?? ''));
  if ($type !== '') {
    $password_store_raw8 = (strpos($type, 'binary') !== false || strpos($type, 'blob') !== false);
  }
}
if ($password_store_raw8 === null) $password_store_raw8 = false;

$default_login_scheme = $c['auth']['login_scheme'] ?? 'usr_is_plain';
$default_pass_algo    = $c['auth']['password_algo'] ?? 'old_password';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_ok(){ return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }

function hash_login($login,$scheme){
  switch($scheme){
    case 'usr_is_md5_username': return pack('H*', md5($login));
    case 'usr_is_md5_lower':    return pack('H*', md5(strtolower($login)));
    case 'usr_is_md5_upper':    return pack('H*', md5(strtoupper($login)));
    default:                    return $login; // usr_is_plain
  }
}
function hash_password_algo($pwd,$algo,$pdo,$storeOldPasswordRaw){
  switch($algo){
    case 'md5_raw16':   return pack('H*', md5($pwd));
    case 'old_password': return $storeOldPasswordRaw ? balp_old_password_raw($pwd,$pdo) : balp_old_password_hex($pwd,$pdo);
    case 'plaintext':   return $pwd;
    case 'bcrypt':      return password_hash($pwd, PASSWORD_BCRYPT); // nevejde se do VARBINARY(16)
    default:            return pack('H*', md5($pwd));
  }
}

/** Pomocná: zkus převést binární login na tisknutelný text, jinak HEX */
function printable_or_hex(string $bin): array {
  $len = strlen($bin);
  $printable = true;
  for ($i=0; $i<$len; $i++) {
    $o = ord($bin[$i]);
    if ($o < 32 || $o > 126) { $printable=false; break; }
  }
  if ($printable) return ['text' => $bin, 'hex' => strtoupper(bin2hex($bin)), 'is_text'=>true];
  return ['text' => '0x'.strtoupper(bin2hex($bin)), 'hex' => strtoupper(bin2hex($bin)), 'is_text'=>false];
}

// --- Akce
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$msg = $errMsg = null;

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_ok()){
  if ($action==='create'){
    $login = trim($_POST['login'] ?? '');
    $pwd   = $_POST['pwd'] ?? '';
    $ls    = $_POST['login_scheme'] ?? $default_login_scheme;
    $pa    = $_POST['password_algo'] ?? $default_pass_algo;
    $dtod  = $_POST['dtod'] ?? date('Y-m-d');
    $dtdo  = $_POST['dtdo'] ?? '2040-02-02';

    if ($login===''){ $errMsg="Login je prázdný"; }
    else {
      try{
        $u = hash_login($login,$ls);
        $p = hash_password_algo($pwd,$pa,$pdo,$password_store_raw8);
        $cols = [$uf, $pf];
        $loginParam = ($ls === 'usr_is_plain') ? PDO::PARAM_STR : PDO::PARAM_LOB;
        $pwdParam = ($pa === 'md5_raw16' || ($pa === 'old_password' && $password_store_raw8)) ? PDO::PARAM_LOB : PDO::PARAM_STR;
        if ($pa === 'bcrypt') $pwdParam = PDO::PARAM_STR;
        $vals = [':u'=>[$u, $loginParam], ':p'=>[$p, $pwdParam]];
        if ($has_dtod){ $cols[]='dtod'; $vals[':d1']=[$dtod, PDO::PARAM_STR]; }
        if ($has_dtdo){ $cols[]='dtdo'; $vals[':d2']=[$dtdo, PDO::PARAM_STR]; }
        $sql = "INSERT INTO `$tab` (`".implode("`,`",$cols)."`) VALUES (".implode(",", array_keys($vals)).")";
        $st = $pdo->prepare($sql);
        foreach($vals as $k=>$arr){ [$v,$t] = $arr; $st->bindValue($k,$v,$t); }
        $st->execute();
        $msg = "Uživatel vytvořen" . ($pk ? " (ID: ".$pdo->lastInsertId().")" : "");
      }catch(Throwable $e){ $errMsg="Chyba INSERT: ".$e->getMessage(); }
    }
  }
  elseif ($action==='delete'){
    if (!$pk){ $errMsg="Složený nebo neznámý PK — mazání vypnuto."; }
    else {
      $id = $_POST['id'] ?? null;
      if ($id==='' || $id===null){ $errMsg="Chybí hodnota PK"; }
      else {
        try{
          $st = $pdo->prepare("DELETE FROM `$tab` WHERE `{$pk}`=:id");
          $st->bindValue(':id', $id);
          $st->execute();
          $msg = "Uživatel #".htmlspecialchars($id)." smazán";
        }catch(Throwable $e){ $errMsg="Chyba DELETE: ".$e->getMessage(); }
      }
    }
  }
}

// --- Načtení seznamu
$rows = []; $listErr = null;
try{
  $sel = [];
  if ($pk) $sel[] = "`$pk` AS pk";
  $sel[] = "`$uf` AS usr_raw";
  $sel[] = "HEX(`$uf`) AS usr_hex";
  $sel[] = "HEX(`$pf`) AS psw_hex";
  if ($has_dtod) $sel[] = "`dtod`";
  if ($has_dtdo) $sel[] = "`dtdo`";
  $sql = "SELECT ".implode(", ", $sel)." FROM `$tab`".($pk?" ORDER BY `$pk` DESC":"");
  $rows = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) { $listErr = $e->getMessage(); }

/** --- Vykreslení těla (bez <html>) --- */
ob_start();
?>
<div class="container-fluid py-3" data-users-root>
  <?php if($composite_pk): ?>
    <div class="alert alert-warning">
      Tabulka má složený primární klíč (<?=htmlspecialchars($composite_pk)?>).
      Mazání je z bezpečnostních důvodů vypnuto.
    </div>
  <?php endif; ?>

  <?php if($listErr): ?><div class="alert alert-danger">Chyba výpisu: <?=htmlspecialchars($listErr)?></div><?php endif; ?>
  <?php if($errMsg):  ?><div class="alert alert-danger"><?=htmlspecialchars($errMsg)?></div><?php endif; ?>
  <?php if($msg):     ?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Vytvořit uživatele</div>
    <div class="card-body">
      <form method="post" onsubmit="return (window.BALP_handleUsersSubmit ? BALP_handleUsersSubmit(this) : true);" data-users-form>
        <input type="hidden" name="csrf" value="<?=$_SESSION['csrf']?>">
        <input type="hidden" name="action" value="create">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Login</label>
            <input class="form-control" name="login" placeholder="např. martin">
          </div>
          <div class="col-md-4">
            <label class="form-label">Heslo</label>
            <input class="form-control" name="pwd" type="password" placeholder="••••••••">
          </div>
          <div class="col-md-4">
            <label class="form-label">Login schéma</label>
            <select class="form-select" name="login_scheme">
              <?php foreach(['usr_is_plain','usr_is_md5_username','usr_is_md5_lower','usr_is_md5_upper'] as $s){
                $sel=$s===$default_login_scheme?'selected':''; echo "<option $sel>$s</option>";
              } ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Algoritmus hesla</label>
            <select class="form-select" name="password_algo">
              <?php foreach(['old_password','md5_raw16','plaintext'] as $a){
                $sel=$a===$default_pass_algo?'selected':''; echo "<option $sel>$a</option>";
              } ?>
            </select>
          </div>

          <?php if($has_dtod): ?>
            <div class="col-md-4">
              <label class="form-label">Platí od (dtod)</label>
              <input class="form-control" name="dtod" type="date" value="<?=date('Y-m-d')?>">
            </div>
          <?php endif; ?>

          <?php if($has_dtdo): ?>
            <div class="col-md-4">
              <label class="form-label">Platí do (dtdo)</label>
              <input class="form-control" name="dtdo" type="date" value="2040-02-02">
            </div>
          <?php endif; ?>
        </div>

        <p class="mt-2 mb-0"><small class="text-muted">VARBINARY(16) ⇒ použijte <b>old_password</b> nebo <b>md5_raw16</b>. Bcrypt/MD5 hex(32) se nevejdou.</small></p>
        <p class="text-muted mb-0"><small>Režim ukládání OLD_PASSWORD: <b><?=$password_store_raw8 ? 'RAW 8&nbsp;bajtů (UNHEX)' : 'ASCII HEX (16 znaků)'?></b>.</small></p>
        <button class="btn btn-primary mt-3">Vytvořit</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Uživatelé</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <?php if($pk): ?><th style="width:1%">PK (<?=htmlspecialchars($pk)?>)</th><?php endif; ?>
              <th>Uživatel</th>
              <th>usr (HEX)</th>
              <th>psw (HEX)</th>
              <?php if($has_dtod): ?><th>od</th><?php endif; ?>
              <?php if($has_dtdo): ?><th>do</th><?php endif; ?>
              <th style="width:1%">Akce</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): $u = printable_or_hex($r['usr_raw']); ?>
            <tr>
              <?php if($pk): ?><td><?=htmlspecialchars($r['pk'])?></td><?php endif; ?>
              <td title="Uložené bajty: 0x<?=htmlspecialchars($u['hex'])?>"><code><?=htmlspecialchars($u['text'])?></code></td>
              <td><code><?=htmlspecialchars($r['usr_hex'])?></code></td>
              <td><code><?=htmlspecialchars($r['psw_hex'])?></code></td>
              <?php if($has_dtod): ?><td><?=htmlspecialchars($r['dtod']??'')?></td><?php endif; ?>
              <?php if($has_dtdo): ?><td><?=htmlspecialchars($r['dtdo']??'')?></td><?php endif; ?>
              <td>
                <?php if($pk): ?>
                  <form method="post" class="d-inline"
                        onsubmit="return (window.BALP_handleUsersSubmit ? BALP_handleUsersSubmit(this) : true);"
                        data-users-form>
                    <input type="hidden" name="csrf" value="<?=$_SESSION['csrf']?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?=$r['pk']?>">
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Smazat #<?=$r['pk']?>?')">Smazat</button>
                  </form>
                <?php else: ?>
                  <span class="badge bg-secondary">PK akce vypnuté</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
$body = ob_get_clean();

/** --- Pokud nejde o AJAX, přibalíme jednoduchý rámec (kvůli standalone zobrazení) --- */
if ($isAjax) {
  echo $body;
  exit;
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>BALP v2 — Správa uživatelů</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Volitelně můžete nahradit vlastní cestou k Bootstrap CSS v projektu -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-4">
    <h1 class="h3 mb-3">BALP v2 — Správa uživatelů</h1>
    <p class="mb-4">
      <a class="link-primary" href="installer.php">Instalátor</a> •
      <a class="link-primary" href="db_probe.php">DB sonda</a> •
      <a class="link-primary" href="public/app.html">Aplikace</a>
    </p>
    <?= $body ?>
  </div>
</body>
</html>

