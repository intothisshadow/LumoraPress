<?php

/**
 * Static bridge exposing TagService to the procedural taxonomy-functions.php theme helpers.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use LumoraPress\Services\TagService;
use RuntimeException;

/**
 * Bridges TagService to get_the_tags()/the_tags()/get_related_posts() — a
 * live, per-post lookup each render, so it can't be snapshotted like SiteBranding.
 */
final class ActiveTags
{
    private static ?TagService $tags = null;

    public static function set(TagService $tags): void
    {
        self::$tags = $tags;
    }

    public static function tags(): TagService
    {
        if (self::$tags === null) {
            throw new RuntimeException('ActiveTags has not been initialized.');
        }

        return self::$tags;
    }
}
