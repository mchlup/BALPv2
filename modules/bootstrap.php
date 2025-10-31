<?php
/**
 * Jednoduchý registr modulů BALP.
 */

if (!defined('BALP_UTF8_BOOTSTRAPPED')) {
    if (function_exists('ini_set')) {
        @ini_set('default_charset', 'UTF-8');
    }
    if (function_exists('mb_internal_encoding')) {
        @mb_internal_encoding('UTF-8');
    }
    if (function_exists('mb_http_output')) {
        @mb_http_output('UTF-8');
    }
    if (function_exists('mb_regex_encoding')) {
        @mb_regex_encoding('UTF-8');
    }
    if (function_exists('mb_language')) {
        @mb_language('uni');
    }
    if (function_exists('setlocale')) {
        foreach (['cs_CZ.UTF-8', 'cs_CZ.utf8', 'cs_CZ', 'Czech_Czechia.1250', 'en_US.UTF-8'] as $localeOption) {
            if (@setlocale(LC_ALL, $localeOption)) {
                if (defined('LC_NUMERIC')) {
                    @setlocale(LC_NUMERIC, 'C');
                }
                break;
            }
        }
    }
    define('BALP_UTF8_BOOTSTRAPPED', true);
}

if (PHP_SAPI !== 'cli' && function_exists('header') && !headers_sent()) {
    header('Content-Language: cs');
}

if (!function_exists('balp_to_utf8')) {
    function balp_to_utf8($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalizedKey = is_string($key) ? balp_to_utf8($key) : $key;
                $normalized[$normalizedKey] = balp_to_utf8($item);
            }
            return $normalized;
        }
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', ['UTF-8', 'Windows-1250', 'ISO-8859-2', 'Windows-1252']);
                if ($converted !== false) {
                    return $converted;
                }
                return mb_convert_encoding($value, 'UTF-8', 'Windows-1250');
            }
            return $value;
        }
        return $value;
    }
}

if (!function_exists('balp_utf8_pdo_options')) {
    function balp_utf8_pdo_options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci',
        ];
    }
}

function balp_project_root(): string
{
    return dirname(__DIR__);
}

function balp_api_path(string $file): string
{
    return balp_project_root() . '/api/' . ltrim($file, '/');
}

function balp_modules_registry(): array
{
    static $modules = null;
    if ($modules === null) {
        $modules = [];
        foreach (glob(__DIR__ . '/*/module.php') as $definition) {
            $module = require $definition;
            if (!is_array($module)) {
                throw new RuntimeException(sprintf('Modulový soubor %s musí vracet pole.', $definition));
            }
            if (empty($module['slug'])) {
                throw new RuntimeException(sprintf('Modul v %s nemá definovaný klíč "slug".', $definition));
            }
            $modules[$module['slug']] = $module;
        }
    }
    return $modules;
}

function balp_module(string $slug): array
{
    $modules = balp_modules_registry();
    if (!isset($modules[$slug])) {
        throw new InvalidArgumentException(sprintf('Modul "%s" nebyl nalezen.', $slug));
    }
    return $modules[$slug];
}

function balp_include_module_api(string $slug, string $endpoint): void
{
    $module = balp_module($slug);
    $map = $module['api'] ?? [];
    if (!isset($map[$endpoint])) {
        throw new InvalidArgumentException(sprintf('Modul "%s" nezná API endpoint "%s".', $slug, $endpoint));
    }
    require $map[$endpoint];
}

function balp_include_module_include(string $slug, string $name): void
{
    $module = balp_module($slug);
    $includes = $module['includes'] ?? [];
    if (!isset($includes[$name])) {
        throw new InvalidArgumentException(sprintf('Modul "%s" nemá include "%s".', $slug, $name));
    }
    require_once $includes[$name];
}

function balp_module_assets(string $slug): array
{
    $module = balp_module($slug);
    return $module['assets'] ?? [];
}
