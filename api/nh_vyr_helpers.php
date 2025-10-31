<?php
require_once __DIR__ . '/../helpers.php';

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
