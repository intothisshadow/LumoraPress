<?php

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
