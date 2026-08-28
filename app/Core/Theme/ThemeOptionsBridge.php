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
 * Static bridge exposing the request's ThemeOptions instance to the
 * procedural theme_option()/theme_options_css() template helpers
 * (include/helpers.php) — the same "set once in bootstrap, read via a
 * static class" pattern SiteBranding and ActiveTheme already use, since
 * theme templates have no direct route to the Kernel.
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
     * LP-123: the header image's resolved URL, set once at bootstrap
     * after MediaService exists (headerImageMediaId() itself is available
     * as soon as ThemeOptions is constructed, but MediaService — needed
     * to turn that ID into a URL — is constructed later in the request,
     * the same ordering constraint SiteBranding's own $resolveMediaUrl
     * closure works around for the site logo/favicon).
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
