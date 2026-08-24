<?php

/**
 * Static bridge exposing PageService to the procedural privacy_policy_url() theme helper.
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

use LumoraPress\Services\PageService;
use RuntimeException;

/**
 * Static bridge exposing PageService (resolving the Privacy Policy Page
 * config key to its live permalink) to include/permalink-functions.php's
 * privacy_policy_url() — same shape as Authors, since resolving a
 * configured page id to a URL is a live lookup each render rather than a
 * value SiteBranding can snapshot once at bootstrap.
 */
final class ActivePages
{
    private static ?PageService $pages = null;

    public static function set(PageService $pages): void
    {
        self::$pages = $pages;
    }

    public static function pages(): PageService
    {
        if (self::$pages === null) {
            throw new RuntimeException('ActivePages has not been initialized.');
        }

        return self::$pages;
    }
}
