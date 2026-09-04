<?php

/**
 * Static bridge exposing PostService to the procedural taxonomy-functions.php theme helpers.
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

use LumoraPress\Services\PostService;
use RuntimeException;

/**
 * Bridges PostService to get_related_posts()/the_related_posts() — a live,
 * per-post lookup each render, so it can't be snapshotted like SiteBranding.
 */
final class ActivePosts
{
    private static ?PostService $posts = null;

    public static function set(PostService $posts): void
    {
        self::$posts = $posts;
    }

    public static function posts(): PostService
    {
        if (self::$posts === null) {
            throw new RuntimeException('ActivePosts has not been initialized.');
        }

        return self::$posts;
    }
}
