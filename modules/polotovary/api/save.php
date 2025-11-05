<?php
// /balp2/api/pol_save.php  (patched 2025-09-03 e)
// Změny v této verzi:
//  - Hlavička: null/"" hodnoty se sanitizují dle schématu (NOT NULL text -> "", NOT NULL číslo -> 0).
//  - Receptura: nepoužitý FK ukládáme jako 0 (NOT NULL schémata).
//  - Receptura se mění jen při rows/lines (>=1) nebo clear_recipe=true.
//  - techpor 1..N, gkg default 0, dtod/dtdo pokud sloupce existují.
//  - Detailní JSON při chybě (err, detail).

session_start();
//require __DIR__ . '/helpers.php';
require balp_project_root() . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

function col_or(PDO $pdo, string $table, array $cands, bool $required=true): ?string {
  foreach ($cands as $c) {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$c]);
    if ($st->fetch()) return $c;
  }
  if ($required) throw new Exception("Missing column in $table: ".implode('|',$cands));
  return null;
}

function get_table_columns(PDO $pdo, string $table): array {
  $st = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
  $st->execute([':t'=>$table]);
  $out = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $out[$r['COLUMN_NAME']] = ['type'=>strtolower($r['DATA_TYPE']), 'nullable'=>($r['IS_NULLABLE']==='YES')];
  }
  return $out;
}

function sanitize_value_by_schema($val, array $info) {
  $type = $info['type'] ?? 'varchar';
  $nullable = $info['nullable'] ?? true;
  // pokud hodnota je null nebo prázdný string
  if ($val === null || $val === '') {
    if ($nullable) return null;
    // NOT NULL -> nastav výchozí
    switch ($type) {
      case 'int': case 'bigint': case 'smallint': case 'tinyint': case 'mediumint':
      case 'decimal': case 'double': case 'float': case 'real': case 'numeric':
        return 0;
      default:
        return '';
    }
  }
  // pro čísla převést
  switch ($type) {
    case 'int': case 'bigint': case 'smallint': case 'tinyint': case 'mediumint':
      return (int)$val;
    case 'decimal': case 'double': case 'float': case 'real': case 'numeric':
      return is_numeric($val) ? (float)$val : 0;
    default:
      return (string)$val;
  }
}

try {
  $c   = cfg();
  $err = null;
  $pdo = db_try_connect($c, $err);
  if (!$pdo) {
    if ($err) error_log('[pol_save] ' . $err);
    throw new RuntimeException('DB connect failed');
  }

  // ---------- vstup ----------
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true);
  if (!is_array($in)) $in = $_POST;

  $polId = (int)($in['id'] ?? $in['pol_id'] ?? 0);
  if ($polId <= 0) throw new Exception("Missing pol_id/id");

  $header = is_array($in['header'] ?? null) ? $in['header'] : [];

  $hasRowsKey = array_key_exists('rows',$in) || array_key_exists('lines',$in);
  $rows = $in['rows'] ?? ($in['lines'] ?? []);
  if (!is_array($rows)) $rows = [];

  $clearRecipe = !empty($in['clear_recipe']);

  $pdo->beginTransaction();

  // ---------- uložit hlavičku ----------
  if (!empty($header)) {
    $tblPol = 'balp_pol';
    $colInfo = get_table_columns($pdo, $tblPol);
    $set = []; $bind = [':id'=>$polId];

    foreach ($header as $k=>$v) {
      if (!array_key_exists($k, $colInfo)) continue;
      $sv = sanitize_value_by_schema($v, $colInfo[$k]);
      $set[] = "`$k` = :h_$k";
      $bind[":h_$k"] = $sv;
    }
    if ($set) {
      $sql = "UPDATE `$tblPol` SET ".implode(',', $set)." WHERE `id` = :id";
      $stmt = $pdo->prepare($sql);
      foreach ($bind as $p=>$v) {
        if ($p === ':id') { $stmt->bindValue($p, (int)$v, PDO::PARAM_INT); continue; }
        // bind type: čísla jako STR/FLT (PDO automatika si poradí), NULL explicitně
        if ($v === null) $stmt->bindValue($p, null, PDO::PARAM_NULL);
        else $stmt->bindValue($p, $v);
      }
      $stmt->execute();
    }
  }

  // ---------- receptura ----------
  if ($clearRecipe || ($hasRowsKey && count($rows) > 0)) {
    $tblRec = 'balp_pol_rec';

    // mapování sloupců podle skutečné DB
    $colPolFK = col_or($pdo, $tblRec, ['idpolfin','pol_id','pol','id_pol','polotovar_id']);
    $colSurFK = col_or($pdo, $tblRec, ['idsur','sur_id','sur','id_sur'], false);
    $colPolRef= col_or($pdo, $tblRec, ['idpol','polref','pol_ref','id_pol_ref'], false);
    $colTPor  = col_or($pdo, $tblRec, ['techpor','tpor','poradi','por','tp']);
    $colGkg   = col_or($pdo, $tblRec, ['gkg','mnozstvi','mnoz'], false);
    $colDtOd  = col_or($pdo, $tblRec, ['dtod','platnost_od','valid_from'], false);
    $colDtDo  = col_or($pdo, $tblRec, ['dtdo','platnost_do','valid_to'], false);

    // smazat staré
    $pdo->prepare("DELETE FROM `$tblRec` WHERE `$colPolFK` = :id")->execute([':id'=>$polId]);

    if (count($rows) > 0) {
      $now = date('Y-m-d H:i:s');
      $i = 0;

      foreach ($rows as $r) {
        $i++;
        $typ = strtolower(trim((string)($r['typ'] ?? 'sur')));
        $ref = (int)($r['ref_id'] ?? 0);
        if ($ref <= 0) { continue; }

        // místo NULL ukládáme 0 pro „druhý“ FK (NOT NULL schéma)
        $surId = 0; $polRef = 0;
        if ($typ === 'pol') { $polRef = $ref; } else { $surId = $ref; }

        $tp = isset($r['techpor']) && (int)$r['techpor'] > 0 ? (int)$r['techpor'] : $i;
        $gk = isset($r['gkg']) && is_numeric($r['gkg']) ? (float)$r['gkg'] : 0.0;
        if ($gk < 0) $gk = 0.0;

        $cols = ["`$colPolFK`"]; $vals = [":pol"];
        $bind = [":pol"=>$polId];

        if ($colSurFK) { $cols[]="`$colSurFK`"; $vals[]=":sur";  $bind[":sur"]  = $surId; }
        if ($colPolRef){ $cols[]="`$colPolRef`";$vals[]=":pref"; $bind[":pref"] = $polRef; }
        $cols[]="`$colTPor`"; $vals[]=":tpor"; $bind[":tpor"]=$tp;
        if ($colGkg)  { $cols[]="`$colGkg`";  $vals[]=":gkg";  $bind[":gkg"] = $gk; }
        if ($colDtOd) { $cols[]="`$colDtOd`"; $vals[]=":dtod"; $bind[":dtod"]= $now; }
        if ($colDtDo) { $cols[]="`$colDtDo`"; $vals[]=":dtdo"; $bind[":dtdo"]= '2099-12-31 23:59:59'; }

        $sql = "INSERT INTO `$tblRec` (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
        $st = $pdo->prepare($sql);

        $st->bindValue(':pol',  (int)$bind[':pol'], PDO::PARAM_INT);
        if (array_key_exists(':sur',$bind))  $st->bindValue(':sur',  (int)$bind[':sur'], PDO::PARAM_INT);
        if (array_key_exists(':pref',$bind)) $st->bindValue(':pref', (int)$bind[':pref'], PDO::PARAM_INT);
        $st->bindValue(':tpor', (int)$bind[':tpor'], PDO::PARAM_INT);
        if (array_key_exists(':gkg',$bind))  $st->bindValue(':gkg',  (float)$bind[':gkg']);
        if (array_key_exists(':dtod',$bind)) $st->bindValue(':dtod', $bind[':dtod']);
        if (array_key_exists(':dtdo',$bind)) $st->bindValue(':dtdo', $bind[':dtdo']);

        $st->execute();
      }
    }
  }

  $pdo->commit();
  echo json_encode(['ok'=>true,'id'=>$polId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  $detail = ($e instanceof PDOException && isset($e->errorInfo[2])) ? $e->errorInfo[2] : null;
  $log = '[pol_save] ' . $e->getMessage();
  if ($detail) { $log .= ' | ' . $detail; }
  error_log($log);
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'Nastala chyba.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
