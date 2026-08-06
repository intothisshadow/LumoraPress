<?php

/**
 * Static bridge exposing the bootstrapped PressConfig to plugin code with no route to the Kernel of its own.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core;

use RuntimeException;

/**
 * Static bridge exposing the bootstrapped PressConfig to plugin code —
 * mirrors ActiveTheme/ActiveContentRenderer: a plugin's main file is
 * `require_once`d from inside PluginManager::load(), so it has no
 * constructor-injection route to Kernel-wired services of its own.
 */
final class ActiveConfig
{
    private static ?PressConfig $instance = null;

    public static function set(PressConfig $config): void
    {
        self::$instance = $config;
    }

    public static function instance(): PressConfig
    {
        if (self::$instance === null) {
            throw new RuntimeException('Config has not been initialized.');
        }

        return self::$instance;
    }
}
