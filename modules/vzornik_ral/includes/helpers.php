<?php
require_once balp_project_root() . '/helpers.php';
if (function_exists('balp_include_module_include')) {
    try {
        balp_include_module_include('naterove_hmoty', 'helpers');
    } catch (Throwable $ignored) {
        // pokud pomocné funkce nejsou k dispozici, pokračujeme – definujeme minimum zde
    }
}

if (!function_exists('balp_table_get_columns')) {
    function balp_table_get_columns(PDO $pdo, string $table, bool $forceReload = false): array
    {
        static $cache = [];
        $key = strtolower($table);
        if ($forceReload) {
            unset($cache[$key]);
        }
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $columns = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . sql_quote_ident($table));
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['Field'])) {
                        $columns[strtolower((string)$row['Field'])] = $row;
                    }
                }
            }
        } catch (Throwable $ignored) {
            $columns = [];
        }

        return $cache[$key] = $columns;
    }
}

if (!function_exists('balp_ral_table_name')) {
    function balp_ral_table_name(): string
    {
        return 'balp_ral';
    }
}

if (!function_exists('balp_ral_table_has_column')) {
    function balp_ral_table_has_column(PDO $pdo, string $column): bool
    {
        $columns = balp_table_get_columns($pdo, balp_ral_table_name());
        return isset($columns[strtolower($column)]);
    }
}

if (!function_exists('balp_ral_resolve_column')) {
    function balp_ral_resolve_column(PDO $pdo, array $candidates, ?string $fallback = null): ?string
    {
        $columns = balp_table_get_columns($pdo, balp_ral_table_name());
        foreach ($candidates as $candidate) {
            if (isset($columns[strtolower($candidate)])) {
                return $candidate;
            }
        }
        return $fallback;
    }
}

if (!function_exists('balp_ral_id_column')) {
    function balp_ral_id_column(PDO $pdo): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $column = balp_ral_resolve_column($pdo, ['id', 'idral', 'ral_id', 'id_ral']);
        return $cache = $column ?? 'id';
    }
}

if (!function_exists('balp_ral_code_column')) {
    function balp_ral_code_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = balp_ral_resolve_column($pdo, ['cislo', 'kod', 'oznaceni', 'ral', 'cislo_ral']);
        return $cache;
    }
}

if (!function_exists('balp_ral_name_column')) {
    function balp_ral_name_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = balp_ral_resolve_column($pdo, [
            'nazev','popis','name','oznaceni_txt','oznaceni','barva_nazev','color_name'
        ]);
        return $cache;
    }
}

if (!function_exists('balp_ral_hex_column')) {
    function balp_ral_hex_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = balp_ral_resolve_column($pdo, [
            'hex','hex_kod','hexcode','barva_hex','rgb_hex','color','barva'
        ]);
        return $cache;
    }
}

if (!function_exists('balp_ral_rgb_column')) {
    function balp_ral_rgb_column(PDO $pdo): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = balp_ral_resolve_column($pdo, ['rgb', 'rgb_txt', 'rgb_text', 'rgb_str']);
        return $cache;
    }
}

if (!function_exists('balp_ral_rgb_component_column')) {
    function balp_ral_rgb_component_column(PDO $pdo, string $component): ?string
    {
        $component = strtolower($component);
        $candidates = [];
        if ($component === 'r') {
            $candidates = ['rgb_r','r','red','color_r','rgbr'];
        } elseif ($component === 'g') {
            $candidates = ['rgb_g','g','green','color_g','rgbg'];
        } elseif ($component === 'b') {
            $candidates = ['rgb_b','b','blue','color_b','rgbb'];
        }
        if (!$candidates) {
            return null;
        }
        return balp_ral_resolve_column($pdo, $candidates);
    }
}

if (!function_exists('balp_ral_first_value')) {
    function balp_ral_first_value(array $row, array $keys)
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

if (!function_exists('balp_ral_normalize_hex')) {
    function balp_ral_normalize_hex($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        $normalized = ltrim($trimmed, '#');
        if (!ctype_xdigit($normalized)) {
            return null;
        }
        if (strlen($normalized) === 3) {
            $normalized = $normalized[0] . $normalized[0]
                . $normalized[1] . $normalized[1]
                . $normalized[2] . $normalized[2];
        }
        if (strlen($normalized) < 6) {
            $normalized = str_pad($normalized, 6, '0', STR_PAD_RIGHT);
        }
        if (strlen($normalized) > 6) {
            $normalized = substr($normalized, 0, 6);
        }
        return '#' . strtoupper($normalized);
    }
}

if (!function_exists('balp_ral_normalize_rgb_value')) {
    function balp_ral_normalize_rgb_value($value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if (!is_numeric(str_replace(',', '.', $value))) {
                if (preg_match('/\d+/', $value, $m)) {
                    $value = $m[0];
                }
            }
            $value = str_replace(',', '.', $value);
        }
        if (is_numeric($value)) {
            $int = (int)round((float)$value);
            if ($int < 0) {
                $int = 0;
            }
            if ($int > 255) {
                $int = 255;
            }
            return $int;
        }
        return null;
    }
}

if (!function_exists('balp_ral_normalize_rgb_components')) {
    function balp_ral_normalize_rgb_components($rgbString, $rValue, $gValue, $bValue): array
    {
        $components = [];
        if ($rgbString !== null && $rgbString !== '') {
            $matches = [];
            if (preg_match_all('/\d+/', (string)$rgbString, $matches)) {
                foreach ($matches[0] as $part) {
                    if (count($components) >= 3) {
                        break;
                    }
                    $norm = balp_ral_normalize_rgb_value($part);
                    if ($norm !== null) {
                        $components[] = $norm;
                    }
                }
            }
        }
        if (count($components) < 3) {
            $r = balp_ral_normalize_rgb_value($rValue);
            $g = balp_ral_normalize_rgb_value($gValue);
            $b = balp_ral_normalize_rgb_value($bValue);
            if ($r !== null && $g !== null && $b !== null) {
                $components = [$r, $g, $b];
            }
        }
        if (count($components) >= 3) {
            $components = [
                $components[0],
                $components[1],
                $components[2],
            ];
        } else {
            $components = [];
        }
        $text = $components ? implode(', ', $components) : null;
        $css = $components ? sprintf('rgb(%d, %d, %d)', $components[0], $components[1], $components[2]) : null;
        return [
            'components' => $components ?: null,
            'text' => $text,
            'css' => $css,
        ];
    }
}

if (!function_exists('balp_ral_normalize_row')) {
    function balp_ral_normalize_row(PDO $pdo, array $row): array
    {
        $idColumn = balp_ral_id_column($pdo);
        $codeColumn = balp_ral_code_column($pdo);
        $nameColumn = balp_ral_name_column($pdo);
        $hexColumn = balp_ral_hex_column($pdo);
        $rgbColumn = balp_ral_rgb_column($pdo);
        $rColumn = balp_ral_rgb_component_column($pdo, 'r');
        $gColumn = balp_ral_rgb_component_column($pdo, 'g');
        $bColumn = balp_ral_rgb_component_column($pdo, 'b');

        $id = balp_ral_first_value($row, ['id', $idColumn]);
        $code = $codeColumn ? balp_ral_first_value($row, [$codeColumn]) : null;
        $name = $nameColumn ? balp_ral_first_value($row, [$nameColumn]) : null;
        $hex = $hexColumn ? balp_ral_first_value($row, [$hexColumn]) : null;
        $rgbString = $rgbColumn ? balp_ral_first_value($row, [$rgbColumn]) : null;
        $rValue = $rColumn ? balp_ral_first_value($row, [$rColumn]) : null;
        $gValue = $gColumn ? balp_ral_first_value($row, [$gColumn]) : null;
        $bValue = $bColumn ? balp_ral_first_value($row, [$bColumn]) : null;

        $hexNorm = balp_ral_normalize_hex($hex);
        $rgbNorm = balp_ral_normalize_rgb_components($rgbString, $rValue, $gValue, $bValue);
        if ($hexNorm === null && $rgbNorm['components']) {
            $hexNorm = sprintf('#%02X%02X%02X', $rgbNorm['components'][0], $rgbNorm['components'][1], $rgbNorm['components'][2]);
        }
        $code = is_string($code) ? trim($code) : $code;
        if ($code === '') {
            $code = null;
        }
        $name = is_string($name) ? trim($name) : $name;
        if (is_string($name) && $name !== '') {
            if (function_exists('balp_to_utf8')) {
                $name = balp_to_utf8($name);
            } else {
                $name = @mb_convert_encoding($name, 'UTF-8', ['UTF-8','Windows-1250','ISO-8859-2','Windows-1252']);
            }
        }
        if ($name === '') {
            $name = null;
        }
        $id = is_numeric($id) ? (int)$id : null;

        return [
            'id' => $id,
            'cislo' => $code,
            'nazev' => $name,
            'hex' => $hexNorm,
            'rgb' => $rgbNorm['text'],
            'rgb_components' => $rgbNorm['components'],
            'color' => $hexNorm ?? $rgbNorm['css'],
        ];
    }
}

if (!function_exists('balp_ral_fetch')) {
    function balp_ral_fetch(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $table = sql_quote_ident(balp_ral_table_name());
        $alias = 'ral';
        $idColumn = balp_ral_id_column($pdo);
        $sql = 'SELECT ' . $alias . '.* FROM ' . $table . ' AS ' . $alias
            . ' WHERE ' . $alias . '.' . sql_quote_ident($idColumn) . ' = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return balp_ral_normalize_row($pdo, $row);
    }
}

if (!function_exists('balp_ral_lookup')) {
    function balp_ral_lookup(PDO $pdo, ?string $code, ?string $name = null): ?array
    {
        $code = is_string($code) ? trim($code) : '';
        $name = is_string($name) ? trim($name) : '';
        $table = sql_quote_ident(balp_ral_table_name());
        $alias = 'ral';
        $idColumn = balp_ral_id_column($pdo);
        $codeColumn = balp_ral_code_column($pdo);
        $nameColumn = balp_ral_name_column($pdo);
        $conditions = [];
        $params = [];
        if ($codeColumn && $code !== '') {
            $conditions[] = 'LOWER(' . $alias . '.' . sql_quote_ident($codeColumn) . ') = LOWER(:code)';
            $params[':code'] = $code;
        }
        if (!$conditions && $nameColumn && $name !== '') {
            $conditions[] = $alias . '.' . sql_quote_ident($nameColumn) . ' LIKE :name';
            $params[':name'] = $name;
        }
        if (!$conditions) {
            return null;
        }
        $sql = 'SELECT ' . $alias . '.* FROM ' . $table . ' AS ' . $alias
            . ' WHERE ' . implode(' OR ', $conditions)
            . ' ORDER BY ' . $alias . '.' . sql_quote_ident($idColumn) . ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return balp_ral_normalize_row($pdo, $row);
    }
}
