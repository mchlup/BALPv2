<?php
/**
 * Vytvoření nového výrobního příkazu (NH výroba)
 * OPRAVY:
 *  - doplněno ukládání pole `cislo_nhods` (NOT NULL) – získá se z tabulky balp_nhods (nebo balp_nhods_vyr pohledu)
 *  - sjednoceno kódování (utf8mb4)
 *  - validace vstupů a bezpečné prepared statements
 *  - volitelný odstín RAL: ukládá se do sloupce idral/ral_id (dle přítomnosti) a zároveň se korektně vrací do odpovědi
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/bootstrap.php'; // PDO $pdo, helper funkce

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    // Základní vstupy z formuláře
    $cisloVp      = trim($_POST['cislo_vp'] ?? '');
    $datumVyroby  = trim($_POST['datum_vyroby'] ?? '');
    $mnozstviG    = trim($_POST['vyrobit_g'] ?? '');
    $idNhOds      = isset($_POST['idnhods']) ? (int)$_POST['idnhods'] : null; // vybraný záznam NH+odstín (FK do balp_nhods)
    $idRal        = isset($_POST['idral']) ? (int)$_POST['idral'] : null;     // volitelně odstín RAL
    $poznamka     = trim($_POST['poznamka'] ?? '');

    // Rychlá validace
    if ($cisloVp === '') {
        throw new RuntimeException('Chybí Číslo VP.');
    }
    if ($datumVyroby === '') {
        throw new RuntimeException('Chybí Datum výroby.');
    }
    if ($mnozstviG === '' || !is_numeric(str_replace(',', '.', $mnozstviG))) {
        throw new RuntimeException('Neplatné množství (g).');
    }
    if (!$idNhOds) {
        throw new RuntimeException('Chybí vazba na položku nátěrové hmoty (idnhods).');
    }

    // Přepnout kódování
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci");

    // ---- 1) Získání hodnoty cislo_nhods (POVINNÉ) z tabulky balp_nhods -----------
    // V původním BALP je u tabulky balp_nhods sloupec `cislo` (textový kód). Může se také jmenovat `cislo_nhods`.
    $sqlCislo = "
        SELECT 
            COALESCE(NULLIF(TRIM(cislo_nhods), ''), NULLIF(TRIM(cislo), '')) AS code,
            idnh AS idnh,
            id AS idnhods
        FROM balp_nhods
        WHERE id = :idnhods
        LIMIT 1
    ";
    $st = $pdo->prepare($sqlCislo);
    $st->execute([':idnhods' => $idNhOds]);
    $nh = $st->fetch(PDO::FETCH_ASSOC);

    if (!$nh) {
        throw new RuntimeException('Zvolená položka NH + odstín (idnhods) neexistuje.');
    }

    $cisloNhods = $nh['code'] ?? null;
    if ($cisloNhods === null || $cisloNhods === '') {
        // Bezpečný fallback (zabrání chybě 1364, pokud je v DB NOT NULL)
        $cisloNhods = (string)$idNhOds;
    }

    // ---- 2) Detekce názvů cílových sloupců (kvůli kompatibilitě s historickým BALP) ----
    $table = 'balp_nhods_vyr'; // tabulka pro výrobní příkazy NH

    // Získáme seznam sloupců
    $colsStmt = $pdo->prepare("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ");
    $colsStmt->execute([':t' => $table]);
    $columns = array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');

    // Pomocná funkce pro výběr existujícího sloupce ze známých aliasů
    $pick = function(array $candidates, array $columns) {
        foreach ($candidates as $c) {
            if (in_array($c, $columns, true)) return $c;
        }
        return null;
    };

    $colIdNhOds   = $pick(['idnhods','id_nhods','nhods_id'], $columns);
    $colCisloN    = $pick(['cislo_nhods','cislo_nh','cislo'], $columns);
    $colVp        = $pick(['cislo_vp','vp','vp_cislo'], $columns);
    $colDatum     = $pick(['datum','datum_vyroby','datum_vyr','dt'], $columns);
    $colMnozstvi  = $pick(['mnozstvi_g','mnozstvi','vyrobit_g','qty_g'], $columns);
    $colPozn      = $pick(['poznamka','pozn','note'], $columns);
    $colIdRal     = $pick(['idral','id_ral','ral_id','idbarva','id_barva'], $columns);

    // Minimální povinné sloupce
    if (!$colIdNhOds) throw new RuntimeException("V tabulce $table chybí sloupec pro FK na balp_nhods.");
    if (!$colCisloN)  throw new RuntimeException("V tabulce $table chybí sloupec cislo_nhods/cislo.");
    if (!$colVp)      throw new RuntimeException("V tabulce $table chybí sloupec cislo_vp.");
    if (!$colDatum)   throw new RuntimeException("V tabulce $table chybí sloupec pro datum výroby.");
    if (!$colMnozstvi)throw new RuntimeException("V tabulce $table chybí sloupec pro množství (g).");

    // ---- 3) Sestavení INSERTu ----------------------------------------------------------
    $fields = [];
    $params = [];
    $ph     = [];

    $fields[] = $colVp;       $ph[] = ':vp';       $params[':vp'] = $cisloVp;
    $fields[] = $colDatum;    $ph[] = ':datum';    $params[':datum'] = $datumVyroby;
    $fields[] = $colMnozstvi; $ph[] = ':mnoz';     $params[':mnoz'] = str_replace(',', '.', $mnozstviG);

    $fields[] = $colIdNhOds;  $ph[] = ':idnhods';  $params[':idnhods'] = $idNhOds;

    // OPRAVA: vyplnit cislo_nhods (NOT NULL) – zabrání chybě 1364
    $fields[] = $colCisloN;   $ph[] = ':cislo_nhods'; $params[':cislo_nhods'] = $cisloNhods;

    if ($colPozn && $poznamka !== '') {
        $fields[] = $colPozn; $ph[] = ':pozn'; $params[':pozn'] = $poznamka;
    }
    if ($colIdRal && $idRal) {
        $fields[] = $colIdRal; $ph[] = ':idral'; $params[':idral'] = $idRal;
    }

    $sqlIns = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $ph) . ')';
    $ins = $pdo->prepare($sqlIns);
    $ins->execute($params);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok' => true,
        'id' => $newId,
        'cislo_vp' => $cisloVp,
        'datum_vyroby' => $datumVyroby,
        'vyrobit_g' => (float)str_replace(',', '.', $mnozstviG),
        'idnhods' => $idNhOds,
        'cislo_nhods' => $cisloNhods,
        'idral' => $idRal,
        'poznamka' => $poznamka
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
