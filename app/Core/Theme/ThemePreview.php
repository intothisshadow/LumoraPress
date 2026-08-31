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
 * Static bridge tracking whether the current request is an admin's
 * "Preview" of a not-yet-activated theme (LP-044's optional "Preview
 * theme" action), and rendering the front-end banner that marks the
 * page as a preview rather than the live site.
 *
 * The public front controller (index.php) is the only caller of
 * activate() — it swaps ThemeRenderer's active theme for the request
 * only (config's active_theme option is never touched, so no other
 * visitor is affected) after confirming the requester is authorized,
 * then records that here so injectBanner() knows to mark the output.
 * Mirrors SiteBranding/FeaturedImages: a static bridge because themes
 * render through procedural template files with no other route to
 * request-scoped state.
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
     * Appends the `?lp_preview_theme=` query parameter to $url when a
     * preview is active for this request, so clicking through the site
     * (a post/page/category/tag/author link, a nav menu item) keeps
     * previewing the same theme instead of silently reverting to the
     * real active theme on the very next click (LP-138). A no-op —
     * returns $url unchanged — for the overwhelming majority of
     * requests, where no preview is active; safe to call unconditionally
     * from every internal link-building function.
     *
     * Refuses to touch a URL pointing at a different host (a nav menu's
     * custom link can point anywhere, not just this site) — an
     * admin-only preview parameter has no business leaking onto a
     * third-party URL a visitor happens to click while it's active.
     * post_permalink()/page_permalink()/etc. only ever return same-host
     * URLs by construction, so this only ever actually matters for
     * nav_menu()'s external "custom link" items.
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
     * Inserts the preview bar markup immediately after the page's
     * opening <body> tag. Works against the fully-rendered HTML string
     * rather than a template hook so it applies to every theme
     * unmodified, including custom themes with no knowledge of preview
     * mode at all.
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
