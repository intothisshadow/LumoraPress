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
 * Static bridge exposing PostService to include/taxonomy-functions.php's
 * get_related_posts()/the_related_posts() — same shape as ActiveTags,
 * since finding the posts related to the one currently rendering is a
 * live, per-post lookup each render rather than a value SiteBranding can
 * snapshot once at bootstrap.
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
