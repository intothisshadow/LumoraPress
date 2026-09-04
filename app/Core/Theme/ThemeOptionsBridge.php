<?php

/**
 * Static bridge exposing the request's ThemeOptions instance to the procedural theme_option()/theme_options_css() template helpers.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use RuntimeException;

/**
 * Bridges the request's ThemeOptions instance to theme_option()/
 * theme_options_css() — theme templates have no direct route to the Kernel.
 */
final class ThemeOptionsBridge
{
    private static ?ThemeOptions $instance = null;

    private static ?string $headerImageUrl = null;

    public static function set(ThemeOptions $options): void
    {
        self::$instance = $options;
    }

    public static function instance(): ThemeOptions
    {
        if (self::$instance === null) {
            throw new RuntimeException('Theme options have not been initialized.');
        }

        return self::$instance;
    }

    /**
     * The header image's resolved URL, set once MediaService is available —
     * ThemeOptions itself is constructed earlier in the request, before
     * MediaService exists to turn the media ID into a URL.
     */
    public static function setHeaderImageUrl(?string $url): void
    {
        self::$headerImageUrl = $url;
    }

    public static function headerImageUrl(): ?string
    {
        return self::$headerImageUrl;
    }
}
