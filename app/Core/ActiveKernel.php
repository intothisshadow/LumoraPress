<?php

/**
 * Static bridge exposing the bootstrapped Kernel to plugin code with no route to it of its own.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Core;

use RuntimeException;

/**
 * Static bridge exposing the fully-constructed Kernel, for plugin code
 * that needs several Kernel-wired services at once. A plugin's top-level
 * bootstrap file runs before Kernel exists, so only read this lazily from
 * inside a hook callback — reading it eagerly at plugin-load time throws.
 */
final class ActiveKernel
{
    private static ?Kernel $instance = null;

    public static function set(Kernel $kernel): void
    {
        self::$instance = $kernel;
    }

    public static function instance(): Kernel
    {
        if (self::$instance === null) {
            throw new RuntimeException('Kernel has not been initialized.');
        }

        return self::$instance;
    }
}
