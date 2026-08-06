<?php

/**
 * Static bridge exposing a single HookManager instance to the procedural add_action()/do_action()/add_filter()/apply_filters() functions.
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
 * Static bridge exposing a single HookManager instance to the procedural
 * add_action()/do_action()/add_filter()/apply_filters() functions used by
 * themes and plugins. Internal services receive HookManager via
 * constructor injection instead of using this bridge directly.
 */
final class Hooks
{
    private static ?HookManager $instance = null;

    public static function set(HookManager $manager): void
    {
        self::$instance = $manager;
    }

    public static function instance(): HookManager
    {
        if (self::$instance === null) {
            self::$instance = new HookManager();
        }

        return self::$instance;
    }
}
