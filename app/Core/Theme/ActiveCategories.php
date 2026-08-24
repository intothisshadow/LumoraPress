<?php

/**
 * Static bridge exposing CategoryService to the procedural post_categories()/the_post_categories() theme helpers.
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

use LumoraPress\Services\CategoryService;
use RuntimeException;

/**
 * Static bridge exposing CategoryService to include/permalink-functions.php's
 * post_categories()/the_post_categories() — same shape as Authors/
 * ActivePages, since resolving a post's own categories is a live,
 * per-post lookup each render rather than a value SiteBranding can
 * snapshot once at bootstrap.
 */
final class ActiveCategories
{
    private static ?CategoryService $categories = null;

    public static function set(CategoryService $categories): void
    {
        self::$categories = $categories;
    }

    public static function categories(): CategoryService
    {
        if (self::$categories === null) {
            throw new RuntimeException('ActiveCategories has not been initialized.');
        }

        return self::$categories;
    }
}
