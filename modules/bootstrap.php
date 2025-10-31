<?php
/**
 * Jednoduchý registr modulů BALP.
 */

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
