<?php

/**
 * Priority-ordered action and filter registry, the engine behind the classic add_action()/do_action()/add_filter()/apply_filters() plugin API.
 *
 * @package LumoraPress
 * @subpackage Hooks
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Hooks;

/**
 * Priority-ordered action and filter registry — the engine behind the
 * classic add_action()/do_action()/add_filter()/apply_filters() plugin API.
 */
final class HookManager
{
    /** @var array<string, array<int, list<callable>>> */
    private array $actions = [];

    /** @var array<string, array<int, list<callable>>> */
    private array $filters = [];

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->actions[$hook][$priority][] = $callback;
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        if (!isset($this->actions[$hook])) {
            return;
        }

        ksort($this->actions[$hook]);

        foreach ($this->actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $callback;
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$hook])) {
            return $value;
        }

        ksort($this->filters[$hook]);

        foreach ($this->filters[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }

    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }
}
