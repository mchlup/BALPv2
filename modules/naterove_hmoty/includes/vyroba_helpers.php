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

if (!function_exists('nh_vyr_digits_condition')) {
    function nh_vyr_digits_condition(string $column, string $param, string $operator = '>='): string
    {
        $op = in_array($operator, ['>=', '<=', '=', '>', '<'], true) ? $operator : '>=';
        return "REPLACE($column, '-', '') $op :$param";
    }
}

if (!function_exists('nh_vyr_digits_expr')) {
    function nh_vyr_digits_expr(string $alias = 'v'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            $alias = 'v';
        }
        $col = $alias . '.' . sql_quote_ident('cislo_vp');
        return "CAST(REPLACE($col, '-', '') AS UNSIGNED)";
    }
}

if (!function_exists('nh_vyr_table_name')) {
    function nh_vyr_table_name(): string
    {
        return 'balp_nhods_vyr';
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
        $cache = nh_vyr_resolve_column($pdo, nh_vyr_zk_table_name(), ['hodnota', 'hodn', 'value']);
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

        $stmt = $pdo->prepare(
            "SELECT v.*, nh.cislo AS cislo_nh, nh.nazev AS nazev_nh FROM $vTable AS v $joinNh WHERE v.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$item) {
            return $result;
        }
        $result['item'] = $item;

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

                $sql = "SELECT $recAlias.id AS id, $typeExpr AS typ, $codeExpr AS cislo, $nameExpr AS nazev, $qtyExpr AS mnozstvi"
                    . " FROM $recTable AS $recAlias"
                    . " $surJoin"
                    . " $polJoin"
                    . " WHERE $conditions"
                    . " ORDER BY $codeExpr";

                $recStmt = $pdo->prepare($sql);
                $recStmt->execute([':id' => $id]);
                $result['rows'] = $recStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

                $valueExpr = $valueCol ? "$zkAlias." . sql_quote_ident($valueCol) : 'NULL';
                $unitExpr = $unitCol ? "$zkAlias." . sql_quote_ident($unitCol) : ($paramJoin ? "$paramAlias." . sql_quote_ident('jednotka') : 'NULL');
                $noteExpr = $noteCol ? "$zkAlias." . sql_quote_ident($noteCol) : "''";

                $nameExpr = $paramJoin ? "$paramAlias." . sql_quote_ident('nazev') : 'NULL';

                $conditions = "$zkAlias." . sql_quote_ident($zkFk) . " = :id";

                $sql = "SELECT $nameExpr AS nazev, $valueExpr AS hodnota, $unitExpr AS jednotka, $noteExpr AS poznamka"
                    . " FROM $zkTable AS $zkAlias"
                    . " $paramJoin"
                    . " WHERE $conditions"
                    . ($nameExpr !== 'NULL' ? ' ORDER BY ' . $nameExpr : '');

                $zkStmt = $pdo->prepare($sql);
                $zkStmt->execute([':id' => $id]);
                $result['zkousky'] = $zkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }

        return $result;
    }
}
