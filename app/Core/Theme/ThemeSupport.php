<?php

/**
 * Which optional Customize features the active theme says it implements.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.23.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * A theme calls add_theme_support() from functions.php for each feature it implements. A Theme
 * Option that needs a theme's templates or stylesheet to do anything names a feature, and the
 * Customize screen hides it while the active theme hasn't declared that feature, instead of
 * offering a setting that silently does nothing.
 */
final class ThemeSupport
{
    /** @var array<string, true> */
    private static array $features = [];

    public static function add(string ...$features): void
    {
        foreach ($features as $feature) {
            self::$features[$feature] = true;
        }
    }

    public static function has(string $feature): bool
    {
        return isset(self::$features[$feature]);
    }

    public static function reset(): void
    {
        self::$features = [];
    }
}
