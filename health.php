<?php
require __DIR__ . '/helpers.php';
$c = cfg();
echo "<h1>BALP v2 — Diagnostika</h1>";
echo "<h2>PHP</h2><pre>";
printf("PHP_VERSION: %s
", phpversion());
foreach (['pdo_mysql','json','mbstring','hash'] as $e) printf("%-12s: %s
", $e, extension_loaded($e)?'OK':'CHYBÍ');
echo "</pre>";
$w = is_writable(__DIR__.'/config'); echo "<p>Write do <code>/config</code>: <b>".($w?'ANO':'NE')."</b></p>";
echo "<h2>Konfigurace</h2><pre>"; print_r($c); echo "</pre>";
$err=null; $pdo=db_try_connect($c,$err);
if(!$pdo){ echo "<div style='color:#a00'>DB chyba: ".htmlspecialchars($err)."</div>"; exit; }
echo "<div style='color:green'>DB připojeno.</div>";
$schema=$c['db']['database'];
$tables=$pdo->query("SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=".$pdo->quote($schema)." ORDER BY 1")->fetchAll();
echo "<pre>"; foreach($tables as $t){ printf("%-40s rows≈%s
", $t['TABLE_NAME'], $t['TABLE_ROWS']); } echo "</pre>";
if (!empty($c['auth']['user_table'])){
  $ut=$c['auth']['user_table']; echo "<h2>Uživatelská tabulka: ".htmlspecialchars($ut)."</h2>";
  try{
    $cols=$pdo->query("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=".$pdo->quote($schema)." AND TABLE_NAME=".$pdo->quote($ut)." ORDER BY ORDINAL_POSITION")->fetchAll();
    echo "<pre>"; foreach($cols as $col){ printf("%-20s %-10s %s
",$col['COLUMN_NAME'],$col['DATA_TYPE'],$col['COLUMN_TYPE']); } echo "</pre>";
    $row=$pdo->query("SELECT HEX(".$c['auth']['username_field']."), HEX(".$c['auth']['password_field'].") FROM ".$ut." LIMIT 1")->fetch(PDO::FETCH_NUM);
    if ($row) echo "<p>Ukázka HEX(usr)/HEX(psw): <code>{$row[0]}</code> / <code>{$row[1]}</code></p>";
  }catch(Throwable $e){ echo "<div style='color:#a00'>Chyba čtení tabulky: ".htmlspecialchars($e->getMessage())."</div>"; }
}
echo '<p><a href="installer.php">Instalátor</a> • <a href="db_probe.php">DB sonda</a> • <a href="admin_users.php">Správa uživatelů</a></p>';
