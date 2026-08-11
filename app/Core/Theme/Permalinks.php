<?php

/**
 * Static bridge exposing PermalinkService to the procedural post_permalink()/category_permalink()/tag_permalink() theme helpers (LP-078).
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use LumoraPress\Services\PermalinkService;
use RuntimeException;

/**
 * Static bridge exposing PermalinkService to the procedural
 * post_permalink()/category_permalink()/tag_permalink() helpers in
 * include/permalink-functions.php — same shape as Authors, since resolving
 * a post's URL is a live, per-post/per-request lookup (the structure can
 * change at any time via Settings > Permalinks) rather than a value that
 * can be snapshotted once at bootstrap like SiteBranding's.
 */
final class Permalinks
{
    private static ?PermalinkService $service = null;

    public static function set(PermalinkService $service): void
    {
        self::$service = $service;
    }

    public static function service(): PermalinkService
    {
        if (self::$service === null) {
            throw new RuntimeException('Permalinks has not been initialized.');
        }

        return self::$service;
    }
}
