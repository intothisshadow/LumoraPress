<?php

/**
 * Prints a theme's Google Fonts <link> tags, using locally hosted copies when the administrator has downloaded them.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use LumoraPress\Services\LocalFontService;

/**
 * Themes call `the_font_stylesheets()` once instead of hand-writing preconnect and
 * stylesheet tags, so the local-hosting, preload, and font-display options reach every
 * theme without each one reimplementing them.
 */
final class FontStylesheets
{
    private static ?LocalFontService $fonts = null;

    private static bool $useLocal = false;

    private static bool $preload = true;

    private static string $display = 'swap';

    public static function configure(?LocalFontService $fonts, bool $useLocal, bool $preload, string $display): void
    {
        self::$fonts = $fonts;
        self::$useLocal = $useLocal;
        self::$preload = $preload;
        self::$display = LocalFontService::normalizeDisplay($display);
    }

    public static function reset(): void
    {
        self::configure(null, false, true, 'swap');
    }

    /**
     * @param array<int, string> $urls
     */
    public static function render(array $urls): void
    {
        $remote = [];
        $local = [];

        foreach ($urls as $url) {
            if (!is_string($url) || !LocalFontService::isGoogleFontsUrl($url)) {
                continue;
            }

            $copy = self::$useLocal ? self::$fonts?->localFor($url) : null;

            if ($copy !== null) {
                $local[] = $copy;
            } else {
                $remote[] = LocalFontService::withDisplay($url, self::$display);
            }
        }

        if ($remote !== []) {
            echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
            echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        }

        foreach ($remote as $url) {
            echo '<link rel="stylesheet" href="' . esc_url($url) . '">' . "\n";
        }

        foreach ($local as $copy) {
            if (self::$preload) {
                foreach ($copy['preload'] as $file) {
                    // Fonts are fetched in anonymous CORS mode, so a preload without crossorigin would be discarded and the file downloaded twice.
                    echo '<link rel="preload" href="' . esc_url($file) . '" as="font" type="font/woff2" crossorigin>' . "\n";
                }
            }

            echo '<link rel="stylesheet" href="' . esc_url($copy['css']) . '">' . "\n";
        }
    }
}
