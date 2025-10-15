<?php
require __DIR__ . '/helpers.php';
$c = cfg(); $err=null; $pdo=db_try_connect($c,$err); if(!$pdo){ die("DB error: ".htmlspecialchars($err)); }

function mysql_old_password_hex(string $pwd): string {
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
    return strtoupper(sprintf("%08x%08x", $res1, $res2));
}
function old_password_hex(string $pwd, PDO $pdo): string {
    try { $h=$pdo->query("SELECT HEX(OLD_PASSWORD(".$pdo->quote($pwd)."))")->fetchColumn(); if($h) return strtoupper($h); } catch(Throwable $e){}
    return mysql_old_password_hex($pwd);
}

if ($_SERVER['REQUEST_METHOD']==='POST'){
  $login=$_POST['login']??''; $pwd=$_POST['pwd']??'';
  $tab=$_POST['tab']??($c['auth']['user_table'] ?? 'balp_usr');
  $uf =$_POST['uf'] ??($c['auth']['username_field'] ?? 'usr');
  $pf =$_POST['pf'] ??($c['auth']['password_field'] ?? 'psw');

  echo "<pre>tab=$tab uf=$uf pf=$pf\n";
  $cands=[ ['plain',$login], ['lower',strtolower($login)], ['upper',strtoupper($login)],
           ['md5_raw16',pack('H*',md5($login))], ['md5_lower',pack('H*',md5(strtolower($login)))], ['md5_upper',pack('H*',md5(strtoupper($login)))] ];
  $found=null; $how=null;
  foreach($cands as $cnd){
    $st=$pdo->prepare("SELECT `$uf` u_raw, HEX($uf) uhex, `$pf` p_raw, HEX($pf) phex FROM `$tab` WHERE $uf=:v LIMIT 1");
    $st->bindValue(':v',$cnd[1],PDO::PARAM_LOB); $st->execute();
    if($row=$st->fetch(PDO::FETCH_ASSOC)){ $found=$row; $how=$cnd[0]; break; }
  }
  if(!$found){ echo "Uživatel NENALEZEN.\n</pre><p><a href='db_probe.php'>Zpět</a></p>"; exit; }

  $old = old_password_hex($pwd,$pdo);
  $md5hex = md5($pwd);
  $raw = $found['p_raw']; // může být ASCII hex (16) nebo raw 8B

  $checks=[];
  $checks['usr_match_as'] = $how;
  $checks['md5_raw16']       = (strtoupper($found['phex'])===strtoupper($md5hex)); // když je v ASCII HEX
  $checks['md5_raw16_raw']   = (strtoupper(bin2hex($raw))===strtoupper($md5hex));  // když je v raw 16B
  $checks['old_password_hex_ascii'] = (strlen($raw)===16 && ctype_xdigit($raw) && strtoupper($raw)===strtoupper($old));
  $checks['old_password_raw8']      = (strtoupper(bin2hex($raw))===strtoupper($old));

  echo "Uživatel nalezen jako: $how\nusr_hex={$found['uhex']}\npsw_hex={$found['phex']}\nOLD_PASSWORD(pwd)=$old\n\n";
  foreach($checks as $k=>$v){ $v2=($v===true?'MATCH':($v===false?'no':$v)); echo str_pad($k,26)." : $v2\n"; }
  echo "</pre><p><a href='db_probe.php'>Zpět</a></p>"; exit;
}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>BALP v2 — DB sonda</title>
<style>body{font-family:system-ui;margin:2rem} label{display:block;margin:.4rem 0}.hint{color:#666}</style></head><body>
<h1>BALP v2 — DB sonda</h1>
<form method="post">
  <label>Login: <input name="login" autofocus></label>
  <label>Heslo: <input name="pwd" type="password"></label>
  <details><summary>Pokročilé</summary>
    <label>Tabulka: <input name="tab" value="<?=htmlspecialchars($c['auth']['user_table'] ?? 'balp_usr')?>"></label>
    <label>Sloupec loginu: <input name="uf" value="<?=htmlspecialchars($c['auth']['username_field'] ?? 'usr')?>"></label>
    <label>Sloupec hesla: <input name="pf" value="<?=htmlspecialchars($c['auth']['password_field'] ?? 'psw')?>"></label>
  </details>
  <button>Otestovat</button>
</form>
<p class="hint">Testuje jak <b>ASCII HEX</b> tak <b>raw</b> variantu OLD_PASSWORD.</p>
<p><a href="installer.php">Instalátor</a> • <a href="health.php">Diagnostika</a> • <a href="admin_users.php">Správa uživatelů</a></p>
</body></html>
