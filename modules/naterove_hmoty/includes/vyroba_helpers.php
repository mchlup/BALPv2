<?php
require_once balp_project_root() . '/helpers.php';
require_once __DIR__ . '/nh_helpers.php';

if (!function_exists('nh_vyr_normalize_vp_digits')) {
    function nh_vyr_normalize_vp_digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', (string)$value);
        if ($digits === '') {
            return null;
        }
        $digits = substr($digits, 0, 6);
        return str_pad($digits, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('nh_vyr_format_vp')) {
    function nh_vyr_format_vp(?string $value): ?string
    {
        $digits = nh_vyr_normalize_vp_digits($value);
        if ($digits === null) {
            return null;
        }
        $a = substr($digits, 0, 2);
        $b = substr($digits, 2, 4);
        return sprintf('%02d-%04d', (int)$a, (int)$b);
    }
}

if (!function_exists('nh_vyr_column_ref')) {
    function nh_vyr_column_ref(string $alias, string $column): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            $alias = 'v';
        }
        return $alias . '.' . sql_quote_ident($column);
    }
}

if (!function_exists('nh_vyr_digits_condition')) {
    function nh_vyr_digits_condition(PDO $pdo, string $alias, string $param, string $operator = '>='): string
    {
        $column = nh_vyr_vp_column($pdo);
        $columnRef = nh_vyr_column_ref($alias, $column);
        $op = in_array($operator, ['>=', '<=', '=', '>', '<'], true) ? $operator : '>=';
        return "REPLACE($columnRef, '-', '') $op :$param";
    }
}

if (!function_exists('nh_vyr_digits_expr')) {
    function nh_vyr_digits_expr(PDO $pdo, string $alias = 'v'): string
    {
        $column = nh_vyr_vp_column($pdo);
        $columnRef = nh_vyr_column_ref($alias, $column);
        return "CAST(REPLACE($columnRef, '-', '') AS UNSIGNED)";
    }
}

if (!function_exists('nh_vyr_table_name')) {
    function nh_vyr_table_name(): string
    {
        return 'balp_nhods_vyr';
    }
}

if (!function_exists('nh_vyr_vp_column')) {
    function nh_vyr_vp_column(PDO $pdo): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $column = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['cislo_vp', 'cislo', 'cislo_vyr', 'vp_cislo', 'cislovp'], 'cislo_vp');
        return $cache = $column ?? 'cislo_vp';
    }
}

if (!function_exists('nh_vyr_date_column')) {
    function nh_vyr_date_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['datum_vyroby', 'dtvyrprik', 'datum', 'dt_vyroby', 'dtvyroby', 'datum_vyr']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_qty_column')) {
    function nh_vyr_qty_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['vyrobit_g', 'vyrobit', 'mnozstvi', 'mnozstvi_g', 'navazit', 'navazit_g']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_note_column')) {
    function nh_vyr_note_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['poznamka', 'pozn', 'poz']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_rec_table_name')) {
    function nh_vyr_rec_table_name(): string
    {
        return 'balp_nhods_vyr_rec';
    }
}

if (!function_exists('nh_vyr_zk_table_name')) {
    function nh_vyr_zk_table_name(): string
    {
        return 'balp_nhods_vyr_zk';
    }
}

if (!function_exists('nh_vyr_table_has_column')) {
    function nh_vyr_table_has_column(PDO $pdo, string $table, string $column): bool
    {
        $columns = balp_table_get_columns($pdo, $table);
        return isset($columns[strtolower($column)]);
    }
}

if (!function_exists('nh_vyr_resolve_column')) {
    function nh_vyr_resolve_column(PDO $pdo, string $table, array $candidates, ?string $fallback = null): ?string
    {
        $columns = balp_table_get_columns($pdo, $table);
        foreach ($candidates as $candidate) {
            if (isset($columns[strtolower($candidate)])) {
                return $candidate;
            }
        }
        return $fallback;
    }
}

if (!function_exists('nh_vyr_build_coalesce_expr')) {
    function nh_vyr_build_coalesce_expr(array $parts, string $fallback = 'NULL'): string
    {
        $filtered = array_values(array_filter($parts, static function ($part) {
            return is_string($part) && $part !== '';
        }));
        if (!$filtered) {
            return $fallback;
        }
        if (count($filtered) === 1) {
            return $filtered[0];
        }
        return 'COALESCE(' . implode(', ', $filtered) . ')';
    }
}

if (!function_exists('nh_vyr_vyr_nh_fk')) {
    function nh_vyr_vyr_nh_fk(PDO $pdo): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $column = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['idnh', 'id_nh', 'idnhods', 'idnhod', 'idnhmaster']);
        return $cache = $column ?? 'idnh';
    }
}

if (!function_exists('nh_vyr_rec_vyr_fk')) {
    function nh_vyr_rec_vyr_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_rec_table_name(), ['idvyrprik', 'id_vyrprik', 'idvyrpr', 'idvyr', 'idvyrobni_prikaz']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_rec_sur_fk')) {
    function nh_vyr_rec_sur_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_rec_table_name(), ['idsur', 'id_sur', 'idsurovina', 'idsuroviny']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_rec_pol_fk')) {
    function nh_vyr_rec_pol_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_rec_table_name(), ['idpol', 'id_pol', 'idpolotovar', 'idpolotovaru']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_rec_qty_column')) {
    function nh_vyr_rec_qty_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_rec_table_name(), ['gkg', 'mnozstvi_g', 'mnozstvi', 'g', 'qty']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_rec_navazit_column')) {
    function nh_vyr_rec_navazit_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_rec_table_name(), ['navazit_g', 'navazit', 'navazeno', 'navazka', 'navazitg']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_rec_techpor_column')) {
    function nh_vyr_rec_techpor_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_rec_table_name(), ['techpor', 'poradi', 'por']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_zk_vyr_fk')) {
    function nh_vyr_zk_vyr_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['idvyrprik', 'id_vyrprik', 'idvyr', 'idvyrobni_prikaz']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_zk_param_fk')) {
    function nh_vyr_zk_param_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['idzk', 'id_zk', 'idparam', 'id_param']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_zk_value_column')) {
    function nh_vyr_zk_value_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['hodnota', 'hodn', 'value', 'zjisteno']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_zk_date_column')) {
    function nh_vyr_zk_date_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['datum', 'dtzk', 'dt', 'datum_zk', 'datum_mereni']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_zk_type_column')) {
    function nh_vyr_zk_type_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['typ', 'druh', 'popis']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_zk_unit_column')) {
    function nh_vyr_zk_unit_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['jednotka', 'unit', 'jed']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_zk_note_column')) {
    function nh_vyr_zk_note_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['pozn', 'poznamka', 'poz']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_fetch_detail')) {
    function nh_vyr_fetch_detail(PDO $pdo, int $id): array
    {
        $result = [
            'item' => null,
            'rows' => [],
            'zkousky' => [],
        ];

        $vTable = sql_quote_ident(nh_vyr_table_name());
        $nhTable = sql_quote_ident(balp_nh_table_name());
        $fkToNh = nh_vyr_vyr_nh_fk($pdo);
        $joinNh = "LEFT JOIN $nhTable AS nh ON nh.id = v." . sql_quote_ident($fkToNh);

        $vpColumn = nh_vyr_vp_column($pdo);
        $dateColumn = nh_vyr_date_column($pdo);
        $qtyColumn = nh_vyr_qty_column($pdo);
        $noteColumn = nh_vyr_note_column($pdo);

        $selectParts = [
            'v.id',
            nh_vyr_column_ref('v', $fkToNh) . ' AS idnh',
            nh_vyr_column_ref('nh', 'cislo') . ' AS cislo_nh',
            nh_vyr_column_ref('nh', 'nazev') . ' AS nazev_nh',
        ];
        $selectParts[] = nh_vyr_column_ref('v', $vpColumn ?? 'cislo_vp') . ' AS cislo_vp_raw';
        $selectParts[] = $dateColumn ? nh_vyr_column_ref('v', $dateColumn) . ' AS datum_vyroby_raw' : 'NULL AS datum_vyroby_raw';
        $selectParts[] = $qtyColumn ? nh_vyr_column_ref('v', $qtyColumn) . ' AS vyrobit_g_raw' : 'NULL AS vyrobit_g_raw';
        $selectParts[] = $noteColumn ? nh_vyr_column_ref('v', $noteColumn) . ' AS poznamka_raw' : 'NULL AS poznamka_raw';
        if (nh_vyr_table_has_column($pdo, nh_vyr_table_name(), 'techpor')) {
            $selectParts[] = nh_vyr_column_ref('v', 'techpor') . ' AS techpor';
        }

        $selectSql = 'SELECT ' . implode(', ', $selectParts)
            . " FROM $vTable AS v $joinNh WHERE v.id = :id LIMIT 1";

        $stmt = $pdo->prepare($selectSql);
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$item) {
            return $result;
        }
        $result['item'] = nh_vyr_normalize_header_row($pdo, $item);

        // Receptura
        if (balp_nh_table_exists($pdo, nh_vyr_rec_table_name())) {
            $recFk = nh_vyr_rec_vyr_fk($pdo);
            if ($recFk) {
                $recTable = sql_quote_ident(nh_vyr_rec_table_name());
                $recAlias = 'r';
                $conditions = "$recAlias." . sql_quote_ident($recFk) . " = :id";

                $surJoin = '';
                $surAlias = 'sur';
                $surCol = nh_vyr_rec_sur_fk($pdo);
                if ($surCol && balp_nh_table_exists($pdo, 'balp_sur')) {
                    $surJoin = "LEFT JOIN " . sql_quote_ident('balp_sur') . " AS $surAlias ON $surAlias.id = $recAlias." . sql_quote_ident($surCol);
                } else {
                    $surAlias = null;
                }

                $polJoin = '';
                $polAlias = 'pol';
                $polCol = nh_vyr_rec_pol_fk($pdo);
                if ($polCol && balp_nh_table_exists($pdo, 'balp_pol')) {
                    $polJoin = "LEFT JOIN " . sql_quote_ident('balp_pol') . " AS $polAlias ON $polAlias.id = $recAlias." . sql_quote_ident($polCol);
                } else {
                    $polAlias = null;
                }

                $codeParts = [];
                if ($surAlias) {
                    $codeParts[] = "$surAlias." . sql_quote_ident('cislo');
                }
                if ($polAlias) {
                    $codeParts[] = "$polAlias." . sql_quote_ident('cislo');
                }
                if (nh_vyr_table_has_column($pdo, nh_vyr_rec_table_name(), 'cislo')) {
                    $codeParts[] = "$recAlias." . sql_quote_ident('cislo');
                }
                $codeExpr = nh_vyr_build_coalesce_expr($codeParts, "$recAlias." . sql_quote_ident('id'));

                $nameParts = [];
                if ($surAlias) {
                    $nameParts[] = "$surAlias." . sql_quote_ident('nazev');
                }
                if ($polAlias) {
                    $nameParts[] = "$polAlias." . sql_quote_ident('nazev');
                }
                if (nh_vyr_table_has_column($pdo, nh_vyr_rec_table_name(), 'nazev')) {
                    $nameParts[] = "$recAlias." . sql_quote_ident('nazev');
                }
                $nameExpr = nh_vyr_build_coalesce_expr($nameParts, "''");

                $typeCases = [];
                if ($surAlias) {
                    $typeCases[] = "WHEN $surAlias.id IS NOT NULL THEN 'Surovina'";
                }
                if ($polAlias) {
                    $typeCases[] = "WHEN $polAlias.id IS NOT NULL THEN 'Polotovar'";
                }
                $typeExpr = $typeCases ? ('CASE ' . implode(' ', $typeCases) . " ELSE '' END") : "''";

                $qtyCol = nh_vyr_rec_qty_column($pdo);
                $qtyExpr = $qtyCol ? "$recAlias." . sql_quote_ident($qtyCol) : 'NULL';
                $navCol = nh_vyr_rec_navazit_column($pdo);
                $navExpr = $navCol ? "$recAlias." . sql_quote_ident($navCol) : 'NULL';
                $recTechpor = nh_vyr_rec_techpor_column($pdo);
                $techporExpr = $recTechpor ? "$recAlias." . sql_quote_ident($recTechpor) : 'NULL';

                $orderParts = [];
                if ($recTechpor) {
                    $orderParts[] = $techporExpr;
                }
                $orderParts[] = $codeExpr;
                $orderSql = ' ORDER BY ' . implode(', ', $orderParts);

                $sql = "SELECT $recAlias.id AS id, $typeExpr AS typ, $codeExpr AS cislo, $nameExpr AS nazev, $qtyExpr AS mnozstvi,"
                    . " $navExpr AS navazit, $techporExpr AS techpor"
                    . " FROM $recTable AS $recAlias"
                    . " $surJoin"
                    . " $polJoin"
                    . " WHERE $conditions"
                    . $orderSql;

                $recStmt = $pdo->prepare($sql);
                $recStmt->execute([':id' => $id]);
                $rows = $recStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $normalized = [];
                foreach ($rows as $row) {
                    $normalized[] = nh_vyr_normalize_recipe_row($row);
                }
                $result['rows'] = $normalized;
            }
        }

        // Zkoušky
        if (balp_nh_table_exists($pdo, nh_vyr_zk_table_name())) {
            $zkFk = nh_vyr_zk_vyr_fk($pdo);
            if ($zkFk) {
                $zkTable = sql_quote_ident(nh_vyr_zk_table_name());
                $zkAlias = 'zk';
                $paramAlias = 'par';
                $paramJoin = '';
                if (balp_nh_table_exists($pdo, 'balp_zk_par')) {
                    $paramJoin = "LEFT JOIN " . sql_quote_ident('balp_zk_par') . " AS $paramAlias ON $paramAlias.id = $zkAlias." . sql_quote_ident(nh_vyr_zk_param_fk($pdo) ?? 'idzk');
                }

                $valueCol = nh_vyr_zk_value_column($pdo);
                $unitCol = nh_vyr_zk_unit_column($pdo);
                $noteCol = nh_vyr_zk_note_column($pdo);
                $dateCol = nh_vyr_zk_date_column($pdo);
                $typeCol = nh_vyr_zk_type_column($pdo);

                $paramColumns = $paramJoin ? balp_table_get_columns($pdo, 'balp_zk_par') : [];
                $paramNameExpr = ($paramJoin && isset($paramColumns['nazev'])) ? "$paramAlias." . sql_quote_ident('nazev') : 'NULL';
                $paramNoteExpr = ($paramJoin && isset($paramColumns['pozn'])) ? "$paramAlias." . sql_quote_ident('pozn') : 'NULL';

                $selectParts = [];
                $selectParts[] = $dateCol ? "$zkAlias." . sql_quote_ident($dateCol) . ' AS datum_raw' : 'NULL AS datum_raw';
                $selectParts[] = $typeCol ? "$zkAlias." . sql_quote_ident($typeCol) . ' AS typ_raw' : 'NULL AS typ_raw';
                $selectParts[] = $valueCol ? "$zkAlias." . sql_quote_ident($valueCol) . ' AS hodnota_raw' : 'NULL AS hodnota_raw';
                if ($unitCol) {
                    $selectParts[] = "$zkAlias." . sql_quote_ident($unitCol) . ' AS jednotka_raw';
                } elseif ($paramJoin && isset($paramColumns['jednotka'])) {
                    $selectParts[] = "$paramAlias." . sql_quote_ident('jednotka') . ' AS jednotka_raw';
                } else {
                    $selectParts[] = 'NULL AS jednotka_raw';
                }
                $selectParts[] = $noteCol ? "$zkAlias." . sql_quote_ident($noteCol) . ' AS poznamka_raw' : 'NULL AS poznamka_raw';
                $selectParts[] = $paramNameExpr . ' AS param_nazev';
                $selectParts[] = $paramNoteExpr ? ($paramNoteExpr . ' AS param_pozn') : 'NULL AS param_pozn';

                $conditions = "$zkAlias." . sql_quote_ident($zkFk) . " = :id";

                $sql = 'SELECT ' . implode(', ', $selectParts)
                    . " FROM $zkTable AS $zkAlias"
                    . " $paramJoin"
                    . " WHERE $conditions";
                if ($paramNameExpr !== 'NULL') {
                    $sql .= ' ORDER BY ' . $paramNameExpr;
                }

                $zkStmt = $pdo->prepare($sql);
                $zkStmt->execute([':id' => $id]);
                $rows = $zkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $result['zkousky'] = nh_vyr_normalize_test_rows($rows);
            }
        }

        return $result;
    }
}

if (!function_exists('nh_vyr_first_value')) {
    function nh_vyr_first_value(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if ($key === null) {
                continue;
            }
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }
        return null;
    }
}

if (!function_exists('nh_vyr_normalize_numeric')) {
    function nh_vyr_normalize_numeric($value, ?int $precision = null)
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            $normalized = str_replace([' '], '', $trimmed);
            if (strpos($normalized, ',') !== false && strpos($normalized, '.') === false) {
                $normalized = str_replace(',', '.', $normalized);
            }
            if (is_numeric($normalized)) {
                $value = (float)$normalized;
            } else {
                return $trimmed;
            }
        }
        if (is_int($value) || is_float($value)) {
            $number = (float)$value;
            if ($precision !== null) {
                $number = round($number, $precision);
            }
            return $number;
        }
        return $value;
    }
}

if (!function_exists('nh_vyr_normalize_header_row')) {
    function nh_vyr_normalize_header_row(PDO $pdo, array $row): array
    {
        $result = $row;

        $vpColumn = nh_vyr_vp_column($pdo);
        $vpValue = nh_vyr_first_value($row, ['cislo_vp_raw', $vpColumn, 'cislo_vp', 'cislo']);
        if ($vpValue === null || $vpValue === '') {
            $result['cislo_vp'] = null;
        } else {
            $formatted = nh_vyr_format_vp((string)$vpValue);
            $result['cislo_vp'] = $formatted ?? (is_scalar($vpValue) ? (string)$vpValue : $vpValue);
        }

        $dateColumn = nh_vyr_date_column($pdo);
        $dateValue = nh_vyr_first_value($row, ['datum_vyroby_raw', $dateColumn, 'datum_vyroby', 'dtvyrprik', 'datum']);
        if ($dateValue === null || $dateValue === '') {
            $result['datum_vyroby'] = null;
        } else {
            $result['datum_vyroby'] = substr((string)$dateValue, 0, 10);
        }

        $qtyColumn = nh_vyr_qty_column($pdo);
        $qtyValue = nh_vyr_first_value($row, ['vyrobit_g_raw', $qtyColumn, 'vyrobit_g', 'mnozstvi', 'mnozstvi_g']);
        $result['vyrobit_g'] = nh_vyr_normalize_numeric($qtyValue, 3);

        $noteColumn = nh_vyr_note_column($pdo);
        $noteValue = nh_vyr_first_value($row, ['poznamka_raw', $noteColumn, 'poznamka', 'pozn', 'poz']);
        if (is_string($noteValue)) {
            $noteValue = trim($noteValue);
        }
        $result['poznamka'] = ($noteValue === '' ? null : $noteValue);

        unset($result['cislo_vp_raw'], $result['datum_vyroby_raw'], $result['vyrobit_g_raw'], $result['poznamka_raw']);

        return $result;
    }
}

if (!function_exists('nh_vyr_normalize_recipe_row')) {
    function nh_vyr_normalize_recipe_row(array $row): array
    {
        $result = $row;
        if (array_key_exists('mnozstvi', $result)) {
            $result['mnozstvi'] = nh_vyr_normalize_numeric($result['mnozstvi'], 3);
        }
        if (array_key_exists('navazit', $result)) {
            $result['navazit'] = nh_vyr_normalize_numeric($result['navazit'], 3);
        }
        if (array_key_exists('techpor', $result)) {
            $result['techpor'] = nh_vyr_normalize_numeric($result['techpor']);
        }
        return $result;
    }
}

if (!function_exists('nh_vyr_normalize_test_rows')) {
    function nh_vyr_normalize_test_rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $datumRaw = nh_vyr_first_value($row, ['datum_raw', 'datum']);
            $datum = null;
            if ($datumRaw !== null && $datumRaw !== '') {
                $datum = substr((string)$datumRaw, 0, 10);
            }

            $typRaw = nh_vyr_first_value($row, ['typ_raw', 'typ', 'param_nazev']);
            if (is_string($typRaw)) {
                $typRaw = trim($typRaw);
            }
            $typ = $typRaw === '' ? null : $typRaw;

            $valueRaw = nh_vyr_first_value($row, ['hodnota_raw', 'hodnota']);
            $value = nh_vyr_normalize_numeric($valueRaw, 3);

            $unit = nh_vyr_first_value($row, ['jednotka_raw', 'jednotka']);
            if (is_string($unit)) {
                $unit = trim($unit);
            }
            if ($unit === '') {
                $unit = null;
            }

            $notes = [];
            foreach (['poznamka_raw', 'poznamka', 'param_pozn'] as $noteKey) {
                $note = nh_vyr_first_value($row, [$noteKey]);
                if (is_string($note)) {
                    $note = trim($note);
                }
                if ($note !== null && $note !== '') {
                    $notes[] = $note;
                }
            }
            if ($notes) {
                $notes = array_values(array_unique($notes));
            }

            $resultText = '';
            if ($value !== null && $value !== '') {
                if (is_float($value) || is_int($value)) {
                    $formatted = number_format((float)$value, 3, ',', ' ');
                    $formatted = rtrim(rtrim($formatted, '0'), ',');
                    if ($formatted === '') {
                        $formatted = '0';
                    }
                    $resultText = $formatted;
                } else {
                    $resultText = (string)$value;
                }
                if ($unit) {
                    $resultText .= ' ' . $unit;
                }
            }

            if ($notes) {
                $notesText = implode('; ', $notes);
                if ($resultText !== '') {
                    $resultText .= ' (' . $notesText . ')';
                } else {
                    $resultText = $notesText;
                }
            }

            $out[] = [
                'datum' => $datum,
                'typ' => $typ,
                'vysledek' => $resultText !== '' ? $resultText : null,
            ];
        }
        return $out;
    }
}
