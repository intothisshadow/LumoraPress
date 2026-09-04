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
 * Bridges PermalinkService to post_permalink()/category_permalink()/
 * tag_permalink() — a live lookup, since the permalink structure can
 * change at any time via Settings > Permalinks.
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
