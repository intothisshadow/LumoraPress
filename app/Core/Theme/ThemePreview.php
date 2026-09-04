<?php

/**
 * Static bridge tracking whether the current request is an admin's live preview of a not-yet-activated theme (LP-044).
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

/**
 * Tracks whether the current request is an admin's "Preview" of a
 * not-yet-activated theme, and renders the front-end banner marking the
 * page as a preview. Only the front controller calls activate(), and only
 * for the current request — config's active_theme is never touched, so no
 * other visitor is affected.
 */
final class ThemePreview
{
    private static ?ThemeInfo $theme = null;

    public static function activate(ThemeInfo $theme): void
    {
        self::$theme = $theme;
    }

    public static function reset(): void
    {
        self::$theme = null;
    }

    public static function isActive(): bool
    {
        return self::$theme !== null;
    }

    public static function theme(): ?ThemeInfo
    {
        return self::$theme;
    }

    /**
     * Appends `?lp_preview_theme=` to $url when a preview is active, so
     * clicking through the site keeps previewing the same theme instead
     * of reverting on the next click. No-op when no preview is active, or
     * when $url points at a different host — an admin-only preview
     * parameter shouldn't leak onto an external nav menu link.
     */
    public static function appendToLink(string $url): string
    {
        if (self::$theme === null || $url === '') {
            return $url;
        }

        $urlHost = parse_url($url, PHP_URL_HOST);

        if ($urlHost !== null && strcasecmp($urlHost, (string) parse_url(home_url(), PHP_URL_HOST)) !== 0) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'lp_preview_theme=' . rawurlencode(self::$theme->slug);
    }

    /**
     * Inserts the preview bar markup after the page's opening <body> tag.
     * Works against the rendered HTML string rather than a template hook
     * so it applies to every theme, including ones unaware of preview mode.
     */
    public static function injectBanner(string $html, string $exitUrl): string
    {
        if (self::$theme === null) {
            return $html;
        }

        $bar = '<div class="lp-theme-preview-bar" role="status">'
            . '<link rel="stylesheet" href="' . htmlspecialchars(admin_asset_url('css/theme-preview-bar.css'), ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="lp-theme-preview-bar__label">Previewing theme: <strong>' . htmlspecialchars(self::$theme->name, ENT_QUOTES, 'UTF-8') . '</strong></span>'
            . '<a class="lp-theme-preview-bar__exit" href="' . htmlspecialchars($exitUrl, ENT_QUOTES, 'UTF-8') . '">Exit Preview</a>'
            . '</div>';

        $replaced = preg_replace('/(<body\b[^>]*>)/i', '$1' . $bar, $html, 1);

        return $replaced ?? $html;
    }
}
