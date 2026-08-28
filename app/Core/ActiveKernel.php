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
 * Static bridge exposing the fully-constructed Kernel — mirrors
 * ActiveConfig/ActiveTheme, but for plugin code that needs more than one
 * Kernel-wired service (e.g. Database *and* Mailer *and* CommentService)
 * and would otherwise need a separate single-purpose bridge for each.
 * Only usable from code that runs during/after a real request — a
 * plugin's own top-level bootstrap file executes before Kernel exists
 * (PluginManager::loadActive() runs well before `new Kernel(...)` in
 * include/bootstrap.php), so registering a hook callback that reads
 * ActiveKernel::instance() lazily, only once actually invoked, is the
 * supported pattern — calling it eagerly at plugin-load time throws.
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
