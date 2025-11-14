<?php
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('vzornik_ral', 'helpers');

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

if (!function_exists('nh_vyr_next_vp_digits')) {
    function nh_vyr_next_vp_digits(PDO $pdo, ?DateTimeInterface $now = null): ?string
    {
        $now = $now ?? new DateTimeImmutable('now');
        $yearPrefix = $now->format('y');
        $minValue = (int)($yearPrefix . '0000');
        $maxValue = (int)($yearPrefix . '9999');

        $table = sql_quote_ident(nh_vyr_table_name());
        $alias = 'v';
        $digitsExpr = nh_vyr_digits_expr($pdo, $alias);

        $sql = 'SELECT MAX(' . $digitsExpr . ') AS max_digits FROM ' . $table . ' AS ' . $alias
            . ' WHERE ' . $digitsExpr . ' BETWEEN :min AND :max';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':min', $minValue, PDO::PARAM_INT);
        $stmt->bindValue(':max', $maxValue, PDO::PARAM_INT);
        $stmt->execute();

        $maxDigits = $stmt->fetchColumn();
        if ($maxDigits === false || $maxDigits === null) {
            $maxDigits = 0;
        }

        $maxDigits = (int)$maxDigits;
        $next = max($maxDigits, $minValue) + 1;
        if ($next > $maxValue) {
            return null;
        }

        return str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('nh_vyr_next_vp_formatted')) {
    function nh_vyr_next_vp_formatted(PDO $pdo, ?DateTimeInterface $now = null): ?array
    {
        $digits = nh_vyr_next_vp_digits($pdo, $now);
        if ($digits === null) {
            return null;
        }

        return [
            'digits' => $digits,
            'formatted' => nh_vyr_format_vp($digits),
        ];
    }
}

if (!function_exists('nh_vyr_column_ref')) {
    function nh_vyr_column_ref(string $alias, string $column): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            $alias = 'v';
        }

        return sql_quote_ident($alias) . '.' . sql_quote_ident($column);
    }
}

if (!function_exists('nh_vyr_supports_regexp_replace')) {
    function nh_vyr_supports_regexp_replace(PDO $pdo): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->query("SELECT REGEXP_REPLACE('A-12/34', '[^0-9]', '')");
            if ($stmt !== false) {
                $stmt->fetchColumn();
                return $cache[$key] = true;
            }
        } catch (Throwable $ignored) {
            // driver nepodporuje REGEXP_REPLACE
        }

        return $cache[$key] = false;
    }
}

if (!function_exists('nh_vyr_digits_only_expr')) {
    function nh_vyr_digits_only_expr(PDO $pdo, string $columnRef): string
    {
        $base = 'COALESCE(' . $columnRef . ", '')";

        if (nh_vyr_supports_regexp_replace($pdo)) {
            return "REGEXP_REPLACE($base, '[^0-9]', '')";
        }

        $expr = $base;
        foreach (['-', '/', ' ', '.', ',', ';', ':', '_'] as $char) {
            $expr = "REPLACE($expr, '" . str_replace("'", "''", $char) . "', '')";
        }

        foreach (range('A', 'Z') as $letter) {
            $expr = "REPLACE($expr, '$letter', '')";
        }
        foreach (range('a', 'z') as $letter) {
            $expr = "REPLACE($expr, '$letter', '')";
        }

        return $expr;
    }
}

if (!function_exists('nh_vyr_digits_unsigned_expr')) {
    function nh_vyr_digits_unsigned_expr(PDO $pdo, string $columnRef): string
    {
        return 'CAST(' . nh_vyr_digits_only_expr($pdo, $columnRef) . ' AS UNSIGNED)';
    }
}

if (!function_exists('nh_vyr_digits_condition')) {
    function nh_vyr_digits_condition(PDO $pdo, string $alias, string $param, string $operator = '>='): string
    {
        $column = nh_vyr_vp_column($pdo);
        $columnRef = nh_vyr_column_ref($alias, $column);
        $op = in_array($operator, ['>=', '<=', '=', '>', '<'], true) ? $operator : '>=';
        return nh_vyr_digits_unsigned_expr($pdo, $columnRef) . " $op :$param";
    }
}

if (!function_exists('nh_vyr_digits_expr')) {
    function nh_vyr_digits_expr(PDO $pdo, string $alias = 'v'): string
    {
        $column = nh_vyr_vp_column($pdo);
        $columnRef = nh_vyr_column_ref($alias, $column);
        return nh_vyr_digits_unsigned_expr($pdo, $columnRef);
    }
}

if (!function_exists('nh_vyr_table_exists')) {
    function nh_vyr_table_exists(PDO $pdo, string $table): bool
    {
        try {
            if (function_exists('balp_nh_table_exists')) {
                return balp_nh_table_exists($pdo, $table);
            }
            $pdo->query('SELECT 1 FROM ' . sql_quote_ident($table) . ' LIMIT 0');
            return true;
        } catch (Throwable $ignored) {
            return false;
        }
    }
}

if (!function_exists('nh_vyr_table_name')) {
    function nh_vyr_table_name(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $config = cfg();
        $candidates = [];

        $configured = $config['tables']['nh_vyr'] ?? $config['tables']['nhods_vyr'] ?? null;
        if (is_string($configured)) {
            $configured = trim($configured);
            if ($configured !== '') {
                $candidates[] = $configured;
            }
        }

        foreach ([
            'balp_nhods_vyr',
            'balp_nh_vyr',
            'balp_nhvyroba',
            'balp_nh_vyroba',
        ] as $candidate) {
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        $pdo = null;
        try {
            $pdo = db();
        } catch (Throwable $ignored) {
            $pdo = null;
        }

        if ($pdo instanceof PDO) {
            foreach ($candidates as $candidate) {
                if (nh_vyr_table_exists($pdo, $candidate)) {
                    return $resolved = $candidate;
                }
            }
        }

        return $resolved = ($candidates[0] ?? 'balp_nhods_vyr');
    }
}

if (!function_exists('nh_vyr_vp_column')) {
    function nh_vyr_vp_column(PDO $pdo): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $column = nh_vyr_resolve_column(
            $pdo,
            nh_vyr_table_name(),
            ['cislo_vp', 'cislo', 'cislo_vyr', 'vp_cislo', 'cislovp'],
            'cislo_vp',
            true
        );
        return $cache = $column;
    }
}

if (!function_exists('nh_vyr_date_column')) {
    function nh_vyr_date_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column(
            $pdo,
            nh_vyr_table_name(),
            ['dtvyrprik', 'datum', 'dt_vyroby', 'datum_vyroby', 'datum_vyr', 'dtvyroby']
        );
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
        $cache = nh_vyr_resolve_column(
            $pdo,
            nh_vyr_table_name(),
            ['vyrobit', 'vyrobit_g', 'mnozstvi', 'mnozstvi_g', 'navazit', 'navazit_g']
        );
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
    function nh_vyr_resolve_column(
        PDO $pdo,
        string $table,
        array $candidates,
        ?string $fallback = null,
        bool $required = false
    ): ?string {
        $columns = balp_table_get_columns($pdo, $table);
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                $definition = $columns[$key];
                return $definition['Field'] ?? $candidate;
            }
        }

        if ($fallback !== null) {
            $fallbackKey = strtolower($fallback);
            if (isset($columns[$fallbackKey])) {
                $definition = $columns[$fallbackKey];
                return $definition['Field'] ?? $fallback;
            }
        }

        if ($required) {
            $expected = $candidates;
            if ($fallback !== null && !in_array($fallback, $expected, true)) {
                $expected[] = $fallback;
            }
            $message = sprintf(
                'Chybí požadovaný sloupec v tabulce %s (očekáván jeden z: %s).',
                $table,
                implode(', ', $expected)
            );
            throw new RuntimeException($message);
        }

        return null;
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
        $column = nh_vyr_resolve_column(
            $pdo,
            nh_vyr_table_name(),
            ['idnh', 'id_nh', 'idnhmaster'],
            'idnh',
            true
        );
        return $cache = $column;
    }
}

if (!function_exists('nh_vyr_ral_fk')) {
    function nh_vyr_ral_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['idral', 'id_ral', 'ral_id', 'idralbarva', 'idbarva', 'ral']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_ral_text_column')) {
    function nh_vyr_ral_text_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = nh_vyr_resolve_column(
            $pdo,
            nh_vyr_table_name(),
            ['ral_text', 'ral', 'raloznaceni', 'odstin_ral', 'ral_label', 'ral_popis']
        );

        return $cache;
    }
}

if (!function_exists('nh_vyr_vyr_shade_fk')) {
    function nh_vyr_vyr_shade_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['idnhods', 'id_nhods', 'nhods_id', 'idnhod']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_code_column')) {
    function nh_vyr_code_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), ['cislo_nhods', 'cislo_nh', 'cislo', 'kod_nh', 'kod_odstinu']);
        if ($cache === null) {
            $vpColumn = nh_vyr_vp_column($pdo);
            if ($vpColumn !== null && strcasecmp($vpColumn, 'cislo') !== 0) {
                $fallbackColumns = ['cislo'];
                $cache = nh_vyr_resolve_column($pdo, nh_vyr_table_name(), $fallbackColumns);
                if ($cache !== null && strcasecmp($cache, $vpColumn) === 0) {
                    $cache = null;
                }
            }
        }

        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_table_name')) {
    function nh_vyr_shade_table_name(): string
    {
        return 'balp_nhods';
    }
}

if (!function_exists('nh_vyr_shade_nh_fk')) {
    function nh_vyr_shade_nh_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['idnh', 'id_nh', 'idmaster', 'id_nhmaster']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_ral_fk')) {
    function nh_vyr_shade_ral_fk(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['idral', 'id_ral', 'ral_id', 'idralbarva', 'idbarva', 'id_barva']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_valid_from_column')) {
    function nh_vyr_shade_valid_from_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['dtod', 'platnost_od', 'valid_from', 'datum_od']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_valid_to_column')) {
    function nh_vyr_shade_valid_to_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['dtdo', 'platnost_do', 'valid_to', 'datum_do']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_code_column')) {
    function nh_vyr_shade_code_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['cislo', 'kod', 'oznaceni', 'cislo_full']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_variant_column')) {
    function nh_vyr_shade_variant_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['cislo_ods', 'cisloods', 'ods_cislo']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_nh_code_column')) {
    function nh_vyr_shade_nh_code_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['cislo_nh', 'cislonh', 'nh_cislo']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_shade_name_column')) {
    function nh_vyr_shade_name_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_shade_table_name(), ['nazev', 'name', 'popis']);
        return $cache;
    }
}

if (!function_exists('nh_vyr_fetch_shade')) {
    function nh_vyr_fetch_shade(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $table = sql_quote_ident(nh_vyr_shade_table_name());
        $alias = 'ods';
        $nhTable = sql_quote_ident(balp_nh_table_name());
        $nhAlias = 'nh';
        $nhFk = nh_vyr_shade_nh_fk($pdo);
        $ralFk = nh_vyr_shade_ral_fk($pdo);
        $codeCol = nh_vyr_shade_code_column($pdo);
        $variantCol = nh_vyr_shade_variant_column($pdo);
        $nhCodeCol = nh_vyr_shade_nh_code_column($pdo);
        $nameCol = nh_vyr_shade_name_column($pdo);

        $select = [
            "$alias." . sql_quote_ident('id') . ' AS id',
            $codeCol ? "$alias." . sql_quote_ident($codeCol) . ' AS shade_cislo' : 'NULL AS shade_cislo',
            $variantCol ? "$alias." . sql_quote_ident($variantCol) . ' AS shade_cislo_ods' : 'NULL AS shade_cislo_ods',
            $nhCodeCol ? "$alias." . sql_quote_ident($nhCodeCol) . ' AS shade_cislo_nh' : 'NULL AS shade_cislo_nh',
            $nameCol ? "$alias." . sql_quote_ident($nameCol) . ' AS shade_nazev' : 'NULL AS shade_nazev',
            $nhFk ? "$alias." . sql_quote_ident($nhFk) . ' AS shade_nh_id' : 'NULL AS shade_nh_id',
        ];

        if ($ralFk) {
            $select[] = "$alias." . sql_quote_ident($ralFk) . ' AS shade_ral_id';
        } else {
            $select[] = 'NULL AS shade_ral_id';
        }

        $select[] = "$nhAlias." . sql_quote_ident('cislo') . ' AS nh_cislo';
        $select[] = "$nhAlias." . sql_quote_ident('nazev') . ' AS nh_nazev';

        $join = '';
        if ($nhFk !== null) {
            $join = "LEFT JOIN $nhTable AS $nhAlias ON $nhAlias.id = $alias." . sql_quote_ident($nhFk);
        } else {
            $join = "LEFT JOIN $nhTable AS $nhAlias ON 1=1";
        }

        $sql = 'SELECT ' . implode(', ', $select)
            . " FROM $table AS $alias $join WHERE $alias." . sql_quote_ident('id') . ' = :id LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $result = [
            'id' => (int)($row['id'] ?? 0),
            'cislo' => nh_vyr_first_value($row, ['shade_cislo']),
            'cislo_nh' => nh_vyr_first_value($row, ['shade_cislo_nh', 'nh_cislo']),
            'cislo_ods' => nh_vyr_first_value($row, ['shade_cislo_ods']),
            'nazev' => nh_vyr_first_value($row, ['shade_nazev', 'nh_nazev']),
            'nh_id' => null,
            'ral_id' => null,
        ];

        if (array_key_exists('shade_nh_id', $row) && $row['shade_nh_id'] !== null) {
            $nhId = (int)$row['shade_nh_id'];
            $result['nh_id'] = $nhId > 0 ? $nhId : null;
        }
        if (array_key_exists('shade_ral_id', $row) && $row['shade_ral_id'] !== null) {
            $ralId = (int)$row['shade_ral_id'];
            $result['ral_id'] = $ralId > 0 ? $ralId : null;
        }

        return $result;
    }
}

if (!function_exists('nh_vyr_find_shade_id')) {
    function nh_vyr_find_shade_id(PDO $pdo, int $nhId, ?int $ralId = null): ?int
    {
        if ($nhId <= 0) {
            return null;
        }

        $table = sql_quote_ident(nh_vyr_shade_table_name());
        $alias = 'ods';
        $nhFk = nh_vyr_shade_nh_fk($pdo);
        $ralFk = nh_vyr_shade_ral_fk($pdo);
        if ($nhFk === null) {
            return null;
        }

        $conditions = ["$alias." . sql_quote_ident($nhFk) . ' = :nh_id'];
        $params = [':nh_id' => $nhId];

        if ($ralId !== null) {
            if ($ralFk === null) {
                return null;
            }
            $conditions[] = "$alias." . sql_quote_ident($ralFk) . ' = :ral_id';
            $params[':ral_id'] = $ralId;
        }

        $validFrom = nh_vyr_shade_valid_from_column($pdo);
        if ($validFrom !== null) {
            $col = "$alias." . sql_quote_ident($validFrom);
            $conditions[] = "($col IS NULL OR $col <= CURRENT_DATE())";
        }

        $validTo = nh_vyr_shade_valid_to_column($pdo);
        if ($validTo !== null) {
            $col = "$alias." . sql_quote_ident($validTo);
            $conditions[] = "($col IS NULL OR $col >= CURRENT_DATE())";
        }

        $orderParts = [];
        if ($validTo !== null) {
            $col = "$alias." . sql_quote_ident($validTo);
            $orderParts[] = "CASE WHEN ($col IS NULL OR $col >= CURRENT_DATE()) THEN 0 ELSE 1 END";
            $orderParts[] = "COALESCE($col, '9999-12-31')";
        }
        if ($validFrom !== null) {
            $col = "$alias." . sql_quote_ident($validFrom);
            $orderParts[] = "COALESCE($col, '0000-01-01')";
        }
        $orderParts[] = "$alias." . sql_quote_ident('id') . ' DESC';

        $sql = 'SELECT ' . "$alias." . sql_quote_ident('id') . ' AS id'
            . " FROM $table AS $alias"
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY ' . implode(', ', $orderParts)
            . ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false || $id === null) {
            return null;
        }

        return (int)$id;
    }
}

if (!function_exists('nh_vyr_fetch_shade_by_nh_id')) {
    function nh_vyr_fetch_shade_by_nh_id(PDO $pdo, int $nhId): ?array
    {
        if ($nhId <= 0) {
            return null;
        }

        $table = sql_quote_ident(nh_vyr_shade_table_name());
        $alias = 'ods';
        $nhTable = sql_quote_ident(balp_nh_table_name());
        $nhAlias = 'nh';
        $nhFk = nh_vyr_shade_nh_fk($pdo);
        if ($nhFk === null) {
            return null;
        }

        $ralFk = nh_vyr_shade_ral_fk($pdo);
        $codeCol = nh_vyr_shade_code_column($pdo);
        $variantCol = nh_vyr_shade_variant_column($pdo);
        $nhCodeCol = nh_vyr_shade_nh_code_column($pdo);
        $nameCol = nh_vyr_shade_name_column($pdo);

        $select = [
            "$alias." . sql_quote_ident('id') . ' AS id',
            $codeCol ? "$alias." . sql_quote_ident($codeCol) . ' AS shade_cislo' : 'NULL AS shade_cislo',
            $variantCol ? "$alias." . sql_quote_ident($variantCol) . ' AS shade_cislo_ods' : 'NULL AS shade_cislo_ods',
            $nhCodeCol ? "$alias." . sql_quote_ident($nhCodeCol) . ' AS shade_cislo_nh' : 'NULL AS shade_cislo_nh',
            $nameCol ? "$alias." . sql_quote_ident($nameCol) . ' AS shade_nazev' : 'NULL AS shade_nazev',
            $nhFk ? "$alias." . sql_quote_ident($nhFk) . ' AS shade_nh_id' : 'NULL AS shade_nh_id',
        ];

        if ($ralFk) {
            $select[] = "$alias." . sql_quote_ident($ralFk) . ' AS shade_ral_id';
        } else {
            $select[] = 'NULL AS shade_ral_id';
        }

        $select[] = "$nhAlias." . sql_quote_ident('cislo') . ' AS nh_cislo';
        $select[] = "$nhAlias." . sql_quote_ident('nazev') . ' AS nh_nazev';

        $join = "LEFT JOIN $nhTable AS $nhAlias ON $nhAlias.id = $alias." . sql_quote_ident($nhFk);

        $sql = 'SELECT ' . implode(', ', $select)
            . " FROM $table AS $alias $join"
            . ' WHERE ' . "$alias." . sql_quote_ident($nhFk) . ' = :id'
            . ' ORDER BY ' . "$alias." . sql_quote_ident('id') . ' DESC'
            . ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $nhId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $result = [
            'id' => (int)($row['id'] ?? 0),
            'cislo' => nh_vyr_first_value($row, ['shade_cislo']),
            'cislo_nh' => nh_vyr_first_value($row, ['shade_cislo_nh', 'nh_cislo']),
            'cislo_ods' => nh_vyr_first_value($row, ['shade_cislo_ods']),
            'nazev' => nh_vyr_first_value($row, ['shade_nazev', 'nh_nazev']),
            'nh_id' => null,
            'ral_id' => null,
        ];

        if (array_key_exists('shade_nh_id', $row) && $row['shade_nh_id'] !== null) {
            $nhIdValue = (int)$row['shade_nh_id'];
            $result['nh_id'] = $nhIdValue > 0 ? $nhIdValue : null;
        }
        if (array_key_exists('shade_ral_id', $row) && $row['shade_ral_id'] !== null) {
            $ralIdValue = (int)$row['shade_ral_id'];
            $result['ral_id'] = $ralIdValue > 0 ? $ralIdValue : null;
        }

        return $result;
    }
}

if (!function_exists('nh_vyr_lookup_shade')) {
    function nh_vyr_lookup_shade(PDO $pdo, ?string $cisloNh, ?string $cisloOds, ?string $cislo): ?array
    {
        $cisloNh = $cisloNh !== null ? trim($cisloNh) : null;
        $cisloOds = $cisloOds !== null ? trim($cisloOds) : null;
        $cislo = $cislo !== null ? trim($cislo) : null;

        if (($cisloNh === null || $cisloNh === '')
            && ($cisloOds === null || $cisloOds === '')
            && ($cislo === null || $cislo === '')) {
            return null;
        }

        $table = sql_quote_ident(nh_vyr_shade_table_name());
        $alias = 'ods';
        $nhTable = sql_quote_ident(balp_nh_table_name());
        $nhAlias = 'nh';
        $nhFk = nh_vyr_shade_nh_fk($pdo);
        $codeCol = nh_vyr_shade_code_column($pdo);
        $variantCol = nh_vyr_shade_variant_column($pdo);
        $nhCodeCol = nh_vyr_shade_nh_code_column($pdo);
        $nameCol = nh_vyr_shade_name_column($pdo);

        $select = [
            "$alias." . sql_quote_ident('id') . ' AS id',
            $codeCol ? "$alias." . sql_quote_ident($codeCol) . ' AS shade_cislo' : 'NULL AS shade_cislo',
            $variantCol ? "$alias." . sql_quote_ident($variantCol) . ' AS shade_cislo_ods' : 'NULL AS shade_cislo_ods',
            $nhCodeCol ? "$alias." . sql_quote_ident($nhCodeCol) . ' AS shade_cislo_nh' : 'NULL AS shade_cislo_nh',
            $nameCol ? "$alias." . sql_quote_ident($nameCol) . ' AS shade_nazev' : 'NULL AS shade_nazev',
            "$nhAlias." . sql_quote_ident('cislo') . ' AS nh_cislo',
            "$nhAlias." . sql_quote_ident('nazev') . ' AS nh_nazev',
        ];

        $join = '';
        if ($nhFk !== null) {
            $join = "LEFT JOIN $nhTable AS $nhAlias ON $nhAlias.id = $alias." . sql_quote_ident($nhFk);
        } else {
            $join = "LEFT JOIN $nhTable AS $nhAlias ON 1=1";
        }

        $where = ['1'];
        $params = [];

        if ($cisloNh !== null && $cisloNh !== '') {
            $conditions = [];
            if ($nhCodeCol) {
                $conditions[] = "$alias." . sql_quote_ident($nhCodeCol) . ' = :cislo_nh';
            }
            $conditions[] = "$nhAlias." . sql_quote_ident('cislo') . ' = :cislo_nh';
            $where[] = '(' . implode(' OR ', $conditions) . ')';
            $params[':cislo_nh'] = $cisloNh;
        }

        if ($cisloOds !== null && $cisloOds !== '' && $variantCol) {
            $where[] = "$alias." . sql_quote_ident($variantCol) . ' = :cislo_ods';
            $params[':cislo_ods'] = $cisloOds;
        }

        if ($cislo !== null && $cislo !== '' && $codeCol) {
            $where[] = "$alias." . sql_quote_ident($codeCol) . ' = :cislo';
            $params[':cislo'] = $cislo;
        }

        $sql = 'SELECT ' . implode(', ', $select)
            . " FROM $table AS $alias $join"
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . "$alias." . sql_quote_ident('id') . ' DESC'
            . ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'cislo' => nh_vyr_first_value($row, ['shade_cislo']),
            'cislo_nh' => nh_vyr_first_value($row, ['shade_cislo_nh', 'nh_cislo']),
            'cislo_ods' => nh_vyr_first_value($row, ['shade_cislo_ods']),
            'nazev' => nh_vyr_first_value($row, ['shade_nazev', 'nh_nazev']),
        ];
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
        $joins = ["LEFT JOIN $nhTable AS nh ON nh.id = v." . sql_quote_ident($fkToNh)];

        $ralFk = nh_vyr_ral_fk($pdo);
        $ralAlias = 'ral';
        $ralIdColumn = null;
        $ralCodeColumn = null;
        $ralNameColumn = null;
        $ralHexColumn = null;
        $ralRgbColumn = null;
        $ralRColumn = null;
        $ralGColumn = null;
        $ralBColumn = null;
        if ($ralFk) {
            $ralTable = sql_quote_ident(balp_ral_table_name());
            $ralIdColumn = balp_ral_id_column($pdo);
            $ralCodeColumn = balp_ral_code_column($pdo);
            $ralNameColumn = balp_ral_name_column($pdo);
            $ralHexColumn = balp_ral_hex_column($pdo);
            $ralRgbColumn = balp_ral_rgb_column($pdo);
            $ralRColumn = balp_ral_rgb_component_column($pdo, 'r');
            $ralGColumn = balp_ral_rgb_component_column($pdo, 'g');
            $ralBColumn = balp_ral_rgb_component_column($pdo, 'b');
            $joins[] = "LEFT JOIN $ralTable AS $ralAlias ON $ralAlias." . sql_quote_ident($ralIdColumn)
                . ' = v.' . sql_quote_ident($ralFk);
        }

        $vpColumn = nh_vyr_vp_column($pdo);
        $dateColumn = nh_vyr_date_column($pdo);
        $qtyColumn = nh_vyr_qty_column($pdo);
        $noteColumn = nh_vyr_note_column($pdo);
        $ralTextColumn = nh_vyr_ral_text_column($pdo);

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
        $selectParts[] = $ralTextColumn ? nh_vyr_column_ref('v', $ralTextColumn) . ' AS ral_text_raw' : 'NULL AS ral_text_raw';
        if (nh_vyr_table_has_column($pdo, nh_vyr_table_name(), 'techpor')) {
            $selectParts[] = nh_vyr_column_ref('v', 'techpor') . ' AS techpor';
        }
        if ($ralFk) {
            $selectParts[] = 'v.' . sql_quote_ident($ralFk) . ' AS ral_id_raw';
            if ($ralIdColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralIdColumn) . ' AS ral_id';
            }
            if ($ralCodeColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralCodeColumn) . ' AS ral_cislo';
            }
            if ($ralNameColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralNameColumn) . ' AS ral_nazev';
            }
            if ($ralHexColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralHexColumn) . ' AS ral_hex';
            }
            if ($ralRgbColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralRgbColumn) . ' AS ral_rgb';
            }
            if ($ralRColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralRColumn) . ' AS ral_rgb_r';
            }
            if ($ralGColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralGColumn) . ' AS ral_rgb_g';
            }
            if ($ralBColumn) {
                $selectParts[] = "$ralAlias." . sql_quote_ident($ralBColumn) . ' AS ral_rgb_b';
            }
        }

        $joinSql = implode(' ', $joins);
        $selectSql = 'SELECT ' . implode(', ', $selectParts)
            . " FROM $vTable AS v $joinSql WHERE v.id = :id LIMIT 1";

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

        $ralIdValue = nh_vyr_first_value($row, ['ral_id', 'ral_id_raw']);
        if ($ralIdValue === null || $ralIdValue === '') {
            $result['ral_id'] = null;
        } else {
            $result['ral_id'] = (int)$ralIdValue;
            if ($result['ral_id'] <= 0) {
                $result['ral_id'] = null;
            }
        }

        $ralTextRaw = nh_vyr_first_value($row, ['ral_text_raw', 'ral_text']);
        if (is_string($ralTextRaw)) {
            $ralTextRaw = trim($ralTextRaw);
            if ($ralTextRaw === '') {
                $ralTextRaw = null;
            }
        } else {
            $ralTextRaw = null;
        }
        $result['ral_text'] = $ralTextRaw;

        $ralCode = nh_vyr_first_value($row, ['ral_cislo']);
        if (is_string($ralCode)) {
            $ralCode = trim($ralCode);
            if ($ralCode === '') {
                $ralCode = null;
            }
        }
        $result['ral_cislo'] = $ralCode ?? $ralTextRaw;

        $ralName = nh_vyr_first_value($row, ['ral_nazev']);
        if (is_string($ralName)) {
            $ralName = trim($ralName);
            if ($ralName === '') {
                $ralName = null;
            }
        }
        $result['ral_nazev'] = $ralName;

        $ralHexRaw = nh_vyr_first_value($row, ['ral_hex']);
        $ralHex = balp_ral_normalize_hex($ralHexRaw);
        $ralRgbRaw = nh_vyr_first_value($row, ['ral_rgb']);
        $ralRgbR = nh_vyr_first_value($row, ['ral_rgb_r']);
        $ralRgbG = nh_vyr_first_value($row, ['ral_rgb_g']);
        $ralRgbB = nh_vyr_first_value($row, ['ral_rgb_b']);
        $ralRgbNormalized = balp_ral_normalize_rgb_components($ralRgbRaw, $ralRgbR, $ralRgbG, $ralRgbB);
        if ($ralHex === null && $ralRgbNormalized['components']) {
            $ralHex = sprintf('#%02X%02X%02X', $ralRgbNormalized['components'][0], $ralRgbNormalized['components'][1], $ralRgbNormalized['components'][2]);
        }
        $result['ral_hex'] = $ralHex;
        $result['ral_rgb'] = $ralRgbNormalized['text'];
        $result['ral_rgb_components'] = $ralRgbNormalized['components'];
        $result['ral_color'] = $ralHex ?? $ralRgbNormalized['css'];

        unset($result['cislo_vp_raw'], $result['datum_vyroby_raw'], $result['vyrobit_g_raw'], $result['poznamka_raw']);
        unset($result['ral_id_raw'], $result['ral_text_raw'], $result['ral_rgb_r'], $result['ral_rgb_g'], $result['ral_rgb_b']);

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
