<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        respond_json(['error' => 'Auth disabled'], 403);
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        respond_json(['error' => 'missing token'], 401);
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $pdo = db();
    balp_ensure_nh_table($pdo);
    $nhTable = sql_quote_ident(balp_nh_table_name());
    $nhAlias = 'nh';
    $vtExpr = balp_nh_vp_expression($pdo, $nhAlias);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        respond_json(['error' => 'missing id'], 400);
    }

    $hasCisloVt = strtoupper($vtExpr) !== 'NULL';
    $idCol = "$nhAlias." . sql_quote_ident('id');
    $cisloCol = "$nhAlias." . sql_quote_ident('cislo');
    $nazevCol = "$nhAlias." . sql_quote_ident('nazev');
    $poznCol = "$nhAlias." . sql_quote_ident('pozn');
    $dtodCol = "$nhAlias." . sql_quote_ident('dtod');
    $dtdoCol = "$nhAlias." . sql_quote_ident('dtdo');
    $katCol = "$nhAlias." . sql_quote_ident('kategorie_id');
    $hasCategory = balp_nh_has_column($pdo, 'kategorie_id');
    $vtSelect = $hasCisloVt
        ? '(' . $vtExpr . ') AS ' . sql_quote_ident('cislo_vt')
        : 'NULL AS ' . sql_quote_ident('cislo_vt');
    $kategorieSelect = $hasCategory
        ? $katCol . ' AS ' . sql_quote_ident('kategorie_id')
        : 'NULL AS ' . sql_quote_ident('kategorie_id');

    $stmt = $pdo->prepare('SELECT '
        . "$idCol AS id, "
        . "$cisloCol AS cislo, "
        . "$vtSelect, "
        . "$nazevCol AS nazev, "
        . "$poznCol AS pozn, "
        . "$dtodCol AS dtod, "
        . "$dtdoCol AS dtdo, "
        . "$kategorieSelect FROM $nhTable AS $nhAlias WHERE $idCol = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        respond_json(['error' => 'not found'], 404);
    }

    if (!array_key_exists('cislo_vt', $row)) {
        $row['cislo_vt'] = null;
    }
    if (!array_key_exists('cislo_vp', $row)) {
        $row['cislo_vp'] = $row['cislo_vt'];
    }

    $row['kod'] = $row['cislo'];
    $row['name'] = $row['nazev'];
    if (!array_key_exists('kategorie_id', $row) || $row['kategorie_id'] === null || $row['kategorie_id'] === '') {
        $row['kategorie_id'] = null;
    } elseif (!is_int($row['kategorie_id'])) {
        $row['kategorie_id'] = (int)$row['kategorie_id'];
    }

    $normalizeDate = static function ($value) {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string)$value;
        if (strlen($value) >= 10) {
            return substr($value, 0, 10);
        }
        return $value;
    };
    $row['dtod'] = $normalizeDate($row['dtod'] ?? null);
    $row['dtdo'] = $normalizeDate($row['dtdo'] ?? null);

    $tableExists = static function (PDO $pdo, string $table) {
        try {
            $pdo->query('SELECT 1 FROM ' . sql_quote_ident($table) . ' LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    };

    $detail = [
        'item' => $row,
        'ods' => [],
    ];

    $hasNhOds = $tableExists($pdo, 'balp_nhods');
    $hasNhOdsCeny = $tableExists($pdo, 'balp_nhods_ceny');
    $hasNhOdsRec = $tableExists($pdo, 'balp_nhods_rec');
    $hasSur = $tableExists($pdo, 'balp_sur');
    $hasPol = $tableExists($pdo, 'balp_pol');

    if ($hasNhOds) {
        $nhodsTable = sql_quote_ident('balp_nhods');
        $odsStmt = $pdo->prepare("SELECT id, idnh, cislo, nazev, pozn, dtod, dtdo FROM $nhodsTable WHERE idnh = :id ORDER BY cislo, id");
        $odsStmt->execute([':id' => $id]);
        $odsRows = $odsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($odsRows as $odsRow) {
            $odsId = (int)($odsRow['id'] ?? 0);
            $odsRow['dtod'] = $normalizeDate($odsRow['dtod'] ?? null);
            $odsRow['dtdo'] = $normalizeDate($odsRow['dtdo'] ?? null);

            $odsRow['active_price'] = null;
            $odsRow['prices'] = [];
            $odsRow['recipe'] = [];

            if ($hasNhOdsCeny && $odsId > 0) {
                $cenyTable = sql_quote_ident('balp_nhods_ceny');
                $activePriceStmt = $pdo->prepare(
                    "SELECT id, idnhods, sur_nak, mat_nak, vn_kg, uvn_kg, dtod, dtdo
                     FROM $cenyTable
                     WHERE idnhods = :ods_id
                       AND (dtod IS NULL OR dtod <= NOW())
                       AND (dtdo IS NULL OR dtdo > NOW())
                     ORDER BY dtod DESC
                     LIMIT 1"
                );
                $activePriceStmt->execute([':ods_id' => $odsId]);
                $activePrice = $activePriceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($activePrice) {
                    $activePrice['dtod'] = $normalizeDate($activePrice['dtod'] ?? null);
                    $activePrice['dtdo'] = $normalizeDate($activePrice['dtdo'] ?? null);
                }
                $odsRow['active_price'] = $activePrice;

                $pricesStmt = $pdo->prepare(
                    "SELECT id, idnhods, sur_nak, mat_nak, vn_kg, uvn_kg, dtod, dtdo
                     FROM $cenyTable
                     WHERE idnhods = :ods_id
                     ORDER BY dtod DESC, id DESC"
                );
                $pricesStmt->execute([':ods_id' => $odsId]);
                $prices = $pricesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($prices as &$priceRow) {
                    $priceRow['dtod'] = $normalizeDate($priceRow['dtod'] ?? null);
                    $priceRow['dtdo'] = $normalizeDate($priceRow['dtdo'] ?? null);
                }
                unset($priceRow);
                $odsRow['prices'] = $prices;
            }

            if ($hasNhOdsRec && $odsId > 0) {
                $recTable = sql_quote_ident('balp_nhods_rec');
                $surTable = $hasSur ? sql_quote_ident('balp_sur') : null;
                $polTable = $hasPol ? sql_quote_ident('balp_pol') : null;

                $joins = [];
                if ($surTable) {
                    $joins[] = "LEFT JOIN $surTable AS sur ON rec.idsur = sur.id";
                }
                if ($polTable) {
                    $joins[] = "LEFT JOIN $polTable AS pol ON rec.idpol = pol.id";
                }
                $joinsSql = $joins ? (" " . implode(" ", $joins)) : '';

                $recipeSql = "SELECT rec.id, rec.idsur, rec.idpol, rec.techpor, rec.gkg, rec.dtod, rec.dtdo,
                        " . ($surTable ? 'sur.cislo AS sur_cislo, sur.nazev AS sur_nazev,' : 'NULL AS sur_cislo, NULL AS sur_nazev,') .
                        ($polTable ? ' pol.cislo AS pol_cislo, pol.nazev AS pol_nazev' : ' NULL AS pol_cislo, NULL AS pol_nazev') .
                    " FROM $recTable AS rec$joinsSql
                       WHERE rec.idnhods = :ods_id
                         AND (rec.dtod IS NULL OR rec.dtod <= NOW())
                         AND (rec.dtdo IS NULL OR rec.dtdo >= NOW())
                     ORDER BY rec.techpor, COALESCE(sur_cislo, pol_cislo, ''), rec.id";

                $recipeStmt = $pdo->prepare($recipeSql);
                $recipeStmt->execute([':ods_id' => $odsId]);
                $recipeRows = $recipeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($recipeRows as &$recRow) {
                    $recRow['dtod'] = $normalizeDate($recRow['dtod'] ?? null);
                    $recRow['dtdo'] = $normalizeDate($recRow['dtdo'] ?? null);
                    $cislo = $recRow['sur_cislo'] ?? $recRow['pol_cislo'] ?? null;
                    $nazev = $recRow['sur_nazev'] ?? $recRow['pol_nazev'] ?? null;
                    $recRow['cislo'] = $cislo;
                    $recRow['nazev'] = $nazev;
                    $recRow['typ'] = ($recRow['idsur'] ?? 0) ? 'sur' : (($recRow['idpol'] ?? 0) ? 'pol' : null);
                }
                unset($recRow);
                $odsRow['recipe'] = $recipeRows;
            }

            $detail['ods'][] = $odsRow;
        }
    }

    echo json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
