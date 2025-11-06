<?php
/**
 * Vytvoření nového výrobního příkazu (NH výroba)
 * OPRAVY:
 *  - doplněno ukládání pole `cislo_nhods` (NOT NULL) – získá se z tabulky balp_nhods (nebo balp_nhods_vyr pohledu)
 *  - sjednoceno kódování (utf8mb4)
 *  - validace vstupů a bezpečné prepared statements
 *  - volitelný odstín RAL: ukládá se do sloupce idral/ral_id (dle přítomnosti) a zároveň se korektně vrací do odpovědi
 *  - možnost dopočítat idnhods podle nh_id + ral_id, pokud klient neposlal přímé idnhods
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/bootstrap.php'; // PDO $pdo, helper funkce

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = $_POST;
    if (!$input || stripos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        if (is_string($rawBody) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
    }

    $stringOrEmpty = static function ($value): string {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_numeric($value)) {
            return trim((string)$value);
        }
        return '';
    };

    $cisloVp     = $stringOrEmpty($input['cislo_vp'] ?? '');
    $datumVyroby = $stringOrEmpty($input['datum_vyroby'] ?? '');
    $mnozstviG   = $stringOrEmpty($input['vyrobit_g'] ?? '');
    $poznamka    = $stringOrEmpty($input['poznamka'] ?? '');

    $idNhOdsRaw = $input['idnhods'] ?? ($input['nhods_id'] ?? null);
    $idNhOds = is_numeric($idNhOdsRaw) ? (int)$idNhOdsRaw : null; // vybraný záznam NH+odstín (FK do balp_nhods)
    if ($idNhOds !== null && $idNhOds <= 0) {
        $idNhOds = null;
    }

    $idRalRaw = $input['idral'] ?? ($input['ral_id'] ?? null);     // volitelně odstín RAL
    $idRal = is_numeric($idRalRaw) ? (int)$idRalRaw : null;
    if ($idRal !== null && $idRal <= 0) {
        $idRal = null;
    }

    $nhIdRaw = $input['nh_id'] ?? ($input['idnh'] ?? null);
    $nhId = is_numeric($nhIdRaw) ? (int)$nhIdRaw : null;
    if ($nhId !== null && $nhId <= 0) {
        $nhId = null;
    }

    $cisloNhRaw = $input['cislo_nh'] ?? null;
    $cisloNh = is_string($cisloNhRaw) ? trim($cisloNhRaw) : null;

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

    $quoteIdent = static function (string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    };

    $lookupColumn = static function (array $columns, array $candidates) {
        foreach ($candidates as $candidate) {
            foreach ($columns as $column) {
                if (is_string($column) && strcasecmp($column, $candidate) === 0) {
                    return $column;
                }
            }
        }
        return null;
    };

    // Přepnout kódování hned na začátku práce s DB
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci");

    // Pokud klient neposlal idnhods, pokusíme se ho doplnit
    if ($idNhOds === null) {
        if ($nhId === null && $cisloNh !== null && $cisloNh !== '') {
            try {
                $findNhStmt = $pdo->prepare('SELECT id FROM balp_nh WHERE TRIM(cislo) = :cislo LIMIT 1');
                $findNhStmt->execute([':cislo' => $cisloNh]);
                $nhIdCandidate = $findNhStmt->fetchColumn();
                if ($nhIdCandidate) {
                    $nhId = (int)$nhIdCandidate;
                }
            } catch (Throwable $ignored) {
                // Pokud tabulka nebo sloupec neexistují, pokračujeme bez doplnění
            }
        }

        if ($nhId === null) {
            throw new RuntimeException('Chybí vazba na položku nátěrové hmoty (nh_id).');
        }

        $columnsStmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
        );
        $columnsStmt->execute([':table' => 'balp_nhods']);
        $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$columns) {
            throw new RuntimeException('Tabulka balp_nhods neobsahuje očekávané sloupce.');
        }

        $nhFk = $lookupColumn($columns, ['idnh', 'id_nh', 'idmaster', 'id_nhmaster']);
        if (!$nhFk) {
            throw new RuntimeException('Tabulka balp_nhods neobsahuje sloupec pro vazbu na nátěrovou hmotu.');
        }

        $ralFk = $lookupColumn($columns, ['idral', 'id_ral', 'ral_id', 'idralbarva', 'idbarva', 'id_barva']);
        if ($idRal !== null && !$ralFk) {
            throw new RuntimeException('Tabulka balp_nhods neobsahuje sloupec pro vazbu na odstín RAL.');
        }

        $dtOdColumn = $lookupColumn($columns, ['dtod']);
        $dtDoColumn = $lookupColumn($columns, ['dtdo']);

        $conditions = [$quoteIdent($nhFk) . ' = :nh_id'];
        $params = [':nh_id' => $nhId];

        if ($idRal !== null && $ralFk) {
            $conditions[] = $quoteIdent($ralFk) . ' = :ral_id';
            $params[':ral_id'] = $idRal;
        }

        if ($dtOdColumn) {
            $conditions[] = '(' . $quoteIdent($dtOdColumn) . ' IS NULL OR ' . $quoteIdent($dtOdColumn) . ' <= CURDATE())';
        }
        if ($dtDoColumn) {
            $conditions[] = '(' . $quoteIdent($dtDoColumn) . ' IS NULL OR ' . $quoteIdent($dtDoColumn) . ' >= CURDATE())';
        }

        $orderParts = [];
        if ($dtDoColumn) {
            $dtDoQuoted = $quoteIdent($dtDoColumn);
            $orderParts[] = 'CASE WHEN (' . $dtDoQuoted . ' IS NULL OR ' . $dtDoQuoted . ' >= CURDATE()) THEN 0 ELSE 1 END';
            $orderParts[] = 'COALESCE(' . $dtDoQuoted . ", '9999-12-31')";
        }
        if ($dtOdColumn) {
            $orderParts[] = 'COALESCE(' . $quoteIdent($dtOdColumn) . ", '0000-01-01')";
        }
        $orderParts[] = $quoteIdent('id') . ' DESC';

        $sqlLookup = 'SELECT ' . $quoteIdent('id') . ' FROM ' . $quoteIdent('balp_nhods')
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY ' . implode(', ', $orderParts)
            . ' LIMIT 1';

        $lookupStmt = $pdo->prepare($sqlLookup);
        foreach ($params as $key => $value) {
            $lookupStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $lookupStmt->execute();
        $idNhOdsValue = $lookupStmt->fetchColumn();

        if (!$idNhOdsValue) {
            throw new RuntimeException('Chybí vazba na položku nátěrové hmoty (idnhods).');
        }

        $idNhOds = (int)$idNhOdsValue;
    }

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
    $colsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $colsStmt->execute([':t' => $table]);
    $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Pomocná funkce pro výběr existujícího sloupce ze známých aliasů
    $colIdNhOds   = $lookupColumn($columns, ['idnhods','id_nhods','nhods_id']);
    $colCisloN    = $lookupColumn($columns, ['cislo_nhods','cislo_nh','cislo']);
    $colVp        = $lookupColumn($columns, ['cislo_vp','vp','vp_cislo']);
    $colDatum     = $lookupColumn($columns, ['datum','datum_vyroby','datum_vyr','dt']);
    $colMnozstvi  = $lookupColumn($columns, ['mnozstvi_g','mnozstvi','vyrobit_g','qty_g']);
    $colPozn      = $lookupColumn($columns, ['poznamka','pozn','note']);
    $colIdRal     = $lookupColumn($columns, ['idral','id_ral','ral_id','idbarva','id_barva']);

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
    if ($colIdRal && $idRal !== null) {
        $fields[] = $colIdRal; $ph[] = ':idral'; $params[':idral'] = $idRal;
    }

    $sqlIns = 'INSERT INTO ' . $quoteIdent($table) . ' (' . implode(', ', array_map($quoteIdent, $fields)) . ')'
        . ' VALUES (' . implode(', ', $ph) . ')';
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
