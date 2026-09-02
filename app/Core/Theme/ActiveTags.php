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
 * Static bridge exposing TagService to include/taxonomy-functions.php's
 * get_the_tags()/the_tags()/get_related_posts() — same shape as
 * ActiveCategories, since resolving a post's own tags is a live, per-post
 * lookup each render rather than a value SiteBranding can snapshot once
 * at bootstrap.
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
