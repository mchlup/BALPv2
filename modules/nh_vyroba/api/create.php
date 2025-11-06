<?php
/**
 * API endpoint pro vytvoření nového výrobního příkazu (NH výroba).
 *
 * Revize: sjednocení autentizace, lepší validace vstupů a bezpečnější
 * ukládání do databáze včetně ošetření chyb při ukládání.
 */

declare(strict_types=1);

require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('nh_vyroba', 'helpers');

header('Content-Type: application/json; charset=utf-8');

class NhVyrobaValidationException extends InvalidArgumentException
{
}

try {
    if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
        if (!headers_sent()) {
            header('Allow: POST');
        }
        respond_json(['ok' => false, 'error' => 'Metoda není podporována.'], 405);
    }

    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        respond_json(['ok' => false, 'error' => 'Autentizace je vypnutá.'], 403);
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        respond_json(['ok' => false, 'error' => 'Chybí autentizační token.'], 401);
    }

    try {
        jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);
    } catch (Throwable $e) {
        respond_json(['ok' => false, 'error' => 'Neplatný token.'], 401);
    }

    $pdo = db();

    $input = request_json();
    if (!is_array($input)) {
        $input = [];
    }
    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }

    $rawValue = static function (array $data, array $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }
        return null;
    };

    $stringValue = static function (array $data, array $keys, bool $emptyAsNull = false) use ($rawValue): ?string {
        $value = $rawValue($data, $keys);
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
        } elseif (is_numeric($value)) {
            $value = trim((string) $value);
        } else {
            return $emptyAsNull ? null : '';
        }
        if ($emptyAsNull && $value === '') {
            return null;
        }
        return $value;
    };

    $intValue = static function (array $data, array $keys) use ($rawValue): ?int {
        $value = $rawValue($data, $keys);
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if (is_numeric($value)) {
                $int = (int) $value;
                return $int > 0 ? $int : null;
            }
        }
        return null;
    };

    $cisloVpInput = $stringValue($input, ['cislo_vp', 'cisloVP', 'vp', 'vp_cislo']);
    if ($cisloVpInput === null || $cisloVpInput === '') {
        throw new NhVyrobaValidationException('Chybí číslo výrobního příkazu (cislo_vp).');
    }

    $datumVyrobyInput = $stringValue($input, ['datum_vyroby', 'datum', 'datum_vyr']);
    if ($datumVyrobyInput === null || $datumVyrobyInput === '') {
        throw new NhVyrobaValidationException('Chybí datum výroby.');
    }

    $mnozstviRaw = $stringValue($input, ['vyrobit_g', 'mnozstvi_g', 'mnozstvi', 'qty']);
    if ($mnozstviRaw === null || $mnozstviRaw === '') {
        throw new NhVyrobaValidationException('Chybí množství (g).');
    }

    $mnozstviNormalized = str_replace(',', '.', $mnozstviRaw);
    if (!is_numeric($mnozstviNormalized)) {
        throw new NhVyrobaValidationException('Neplatné množství (g).');
    }

    $poznamka = $stringValue($input, ['poznamka', 'pozn', 'note'], true) ?? '';

    $idNhOds = $intValue($input, ['idnhods', 'nhods_id', 'id_nhods']);
    $idRal = $intValue($input, ['idral', 'ral_id', 'id_ral']);
    $nhId = $intValue($input, ['nh_id', 'idnh', 'id_nh']);
    $cisloNh = $stringValue($input, ['cislo_nh', 'nh_cislo'], true);

    $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $datumVyrobyInput);
    if (!$parsedDate) {
        $parsedDate = DateTimeImmutable::createFromFormat('d.m.Y', $datumVyrobyInput)
            ?: DateTimeImmutable::createFromFormat('d. m. Y', $datumVyrobyInput);
    }
    if (!$parsedDate) {
        throw new NhVyrobaValidationException('Neplatný formát data výroby.');
    }
    $datumVyroby = $parsedDate->format('Y-m-d');

    $cisloVp = trim($cisloVpInput);
    if ($cisloVp === '') {
        throw new NhVyrobaValidationException('Číslo výrobního příkazu je prázdné.');
    }

    if ($idNhOds === null) {
        if ($nhId === null && $cisloNh !== null && $cisloNh !== '') {
            $tableNh = sql_quote_ident(balp_nh_table_name());
            $codeColumn = balp_nh_has_column($pdo, 'cislo') ? sql_quote_ident('cislo') : null;
            if ($codeColumn !== null) {
                $stmt = $pdo->prepare("SELECT id FROM $tableNh WHERE TRIM($codeColumn) = :cislo LIMIT 1");
                $stmt->bindValue(':cislo', $cisloNh, PDO::PARAM_STR);
                $stmt->execute();
                $foundId = $stmt->fetchColumn();
                if ($foundId !== false && $foundId !== null) {
                    $nhId = (int) $foundId;
                }
            }
        }

        if ($nhId === null) {
            throw new NhVyrobaValidationException('Chybí vazba na nátěrovou hmotu (nh_id).');
        }

        $foundShadeId = nh_vyr_find_shade_id($pdo, $nhId, $idRal);
        if ($foundShadeId === null) {
            throw new NhVyrobaValidationException('Nepodařilo se najít odstín nátěrové hmoty (idnhods).');
        }
        $idNhOds = $foundShadeId;
    }

    $shade = nh_vyr_fetch_shade($pdo, $idNhOds);
    if (!$shade) {
        throw new NhVyrobaValidationException('Zvolený odstín nátěrové hmoty neexistuje.');
    }

    if ($cisloNh === null || $cisloNh === '') {
        $cisloNh = $shade['cislo_nh'] ?? null;
    }

    $cisloNhods = $shade['cislo'] ?? null;
    if ($cisloNhods === null || $cisloNhods === '') {
        $cisloNhods = (string) $idNhOds;
    }

    $tableName = nh_vyr_table_name();
    $shadeFk = nh_vyr_vyr_shade_fk($pdo);
    $codeColumn = nh_vyr_code_column($pdo);
    $vpColumn = nh_vyr_vp_column($pdo);
    $dateColumn = nh_vyr_date_column($pdo);
    $qtyColumn = nh_vyr_qty_column($pdo);
    $noteColumn = nh_vyr_note_column($pdo);
    $ralFk = nh_vyr_ral_fk($pdo);

    if (!$shadeFk || !nh_vyr_table_has_column($pdo, $tableName, $shadeFk)) {
        throw new NhVyrobaValidationException('V tabulce výrobních příkazů chybí sloupec pro vazbu na odstín (idnhods).');
    }
    if (!$codeColumn || !nh_vyr_table_has_column($pdo, $tableName, $codeColumn)) {
        throw new NhVyrobaValidationException('V tabulce výrobních příkazů chybí sloupec pro uložení cislo_nhods.');
    }
    if (!$vpColumn || !nh_vyr_table_has_column($pdo, $tableName, $vpColumn)) {
        throw new NhVyrobaValidationException('V tabulce výrobních příkazů chybí sloupec pro číslo VP.');
    }
    if (!$dateColumn || !nh_vyr_table_has_column($pdo, $tableName, $dateColumn)) {
        throw new NhVyrobaValidationException('V tabulce výrobních příkazů chybí sloupec pro datum výroby.');
    }
    if (!$qtyColumn || !nh_vyr_table_has_column($pdo, $tableName, $qtyColumn)) {
        throw new NhVyrobaValidationException('V tabulce výrobních příkazů chybí sloupec pro množství (g).');
    }

    $addField = static function (
        ?string $column,
        string $placeholder,
        $value,
        array &$columns,
        array &$placeholders,
        array &$params,
        array &$types,
        ?int $typeOverride = null
    ): void {
        if ($column === null) {
            return;
        }
        $columns[$column] = $column;
        $placeholders[$column] = $placeholder;
        $params[$placeholder] = $value;
        if ($typeOverride !== null) {
            $types[$placeholder] = $typeOverride;
        }
    };

    $columns = [];
    $placeholders = [];
    $params = [];
    $types = [];

    $addField($vpColumn, ':vp', $cisloVp, $columns, $placeholders, $params, $types);
    $addField($dateColumn, ':datum', $datumVyroby, $columns, $placeholders, $params, $types);
    $addField($qtyColumn, ':mnozstvi', $mnozstviNormalized, $columns, $placeholders, $params, $types);
    $addField($shadeFk, ':idnhods', $idNhOds, $columns, $placeholders, $params, $types, PDO::PARAM_INT);
    $addField($codeColumn, ':cislo_nhods', $cisloNhods, $columns, $placeholders, $params, $types);

    if ($noteColumn && $poznamka !== '') {
        $addField($noteColumn, ':poznamka', $poznamka, $columns, $placeholders, $params, $types);
    }
    if ($ralFk && $idRal !== null) {
        $addField($ralFk, ':idral', $idRal, $columns, $placeholders, $params, $types, PDO::PARAM_INT);
    }

    if (!$columns) {
        throw new NhVyrobaValidationException('Nejsou definovány žádné sloupce pro vložení.');
    }

    $orderedColumns = array_keys($columns);
    $orderedPlaceholders = [];
    foreach ($orderedColumns as $column) {
        $orderedPlaceholders[] = $placeholders[$column];
    }

    $sql = 'INSERT INTO ' . sql_quote_ident($tableName)
        . ' (' . implode(', ', array_map('sql_quote_ident', $orderedColumns)) . ')'
        . ' VALUES (' . implode(', ', $orderedPlaceholders) . ')';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $placeholder => $value) {
        if ($value === null) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            continue;
        }
        $type = $types[$placeholder] ?? null;
        if ($type === null) {
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } else {
                $type = PDO::PARAM_STR;
            }
        }
        $stmt->bindValue($placeholder, $value, $type);
    }

    $stmt->execute();

    $newId = (int) $pdo->lastInsertId();
    $response = [
        'ok' => true,
        'id' => $newId,
        'cislo_vp' => $cisloVp,
        'datum_vyroby' => $datumVyroby,
        'vyrobit_g' => (float) $mnozstviNormalized,
        'idnhods' => $idNhOds,
        'cislo_nhods' => $cisloNhods,
    ];

    if ($idRal !== null) {
        $response['idral'] = $idRal;
    }
    if ($poznamka !== '') {
        $response['poznamka'] = $poznamka;
    }
    if ($cisloNh !== null && $cisloNh !== '') {
        $response['cislo_nh'] = $cisloNh;
    }
    $response['shade'] = $shade;

    respond_json($response, 201);
} catch (NhVyrobaValidationException $e) {
    respond_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (PDOException $e) {
    error_log('[nh_vyroba][create] DB error: ' . $e->getMessage());
    respond_json(['ok' => false, 'error' => 'Chyba při ukládání výroby.'], 500);
} catch (Throwable $e) {
    error_log('[nh_vyroba][create] Unexpected error: ' . $e->getMessage());
    respond_json(['ok' => false, 'error' => 'Neočekávaná chyba.'], 500);
}
