<?php

declare(strict_types=1);

use LumoraPress\Core\Hooks\Hooks;

/**
 * Procedural plugin API. Loaded once during bootstrap so themes and
 * plugins can use the familiar add_action()/do_action()/add_filter()/
 * apply_filters() functions without touching the underlying HookManager.
 */

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->addAction($hook, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        Hooks::instance()->doAction($hook, ...$args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->addFilter($hook, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return Hooks::instance()->applyFilters($hook, $value, ...$args);
    }
}
