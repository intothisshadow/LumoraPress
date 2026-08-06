<?php

/**
 * Static bridge exposing MediaService/ThumbnailService/PressConfig to the procedural featured-image theme helpers (LP-040).
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

use LumoraPress\Core\PressConfig;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\ThumbnailService;
use RuntimeException;

/**
 * Static bridge exposing MediaService/ThumbnailService/PressConfig (LP-040)
 * to the procedural has_post_thumbnail()/post_thumbnail_url()/
 * the_post_thumbnail() helpers in include/media-functions.php. Unlike
 * SiteBranding — which snapshots plain string values set once at bootstrap
 * because they never vary per request — featured images need a live,
 * per-post lookup each time, so this holds the services themselves, the
 * same shape ActiveTheme uses for ThemeRenderer.
 */
final class FeaturedImages
{
    private static ?MediaService $media = null;

    private static ?ThumbnailService $thumbnails = null;

    private static ?PressConfig $config = null;

    public static function set(MediaService $media, ThumbnailService $thumbnails, PressConfig $config): void
    {
        self::$media = $media;
        self::$thumbnails = $thumbnails;
        self::$config = $config;
    }

    public static function media(): MediaService
    {
        if (self::$media === null) {
            throw new RuntimeException('FeaturedImages has not been initialized.');
        }

        return self::$media;
    }

    public static function thumbnails(): ThumbnailService
    {
        if (self::$thumbnails === null) {
            throw new RuntimeException('FeaturedImages has not been initialized.');
        }

        return self::$thumbnails;
    }

    public static function config(): PressConfig
    {
        if (self::$config === null) {
            throw new RuntimeException('FeaturedImages has not been initialized.');
        }

        return self::$config;
    }
}
