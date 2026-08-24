<?php

/**
 * Static bridge exposing Auth to the procedural edit_post_link()/the_edit_post_link() theme helpers.
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

use LumoraPress\Core\Security\Auth;
use RuntimeException;

/**
 * Static bridge exposing Auth (the currently-authenticated front-end
 * visitor, if any) to include/permalink-functions.php's edit_post_link()/
 * edit_page_link() — same shape as Authors/ActivePages/ActiveCategories.
 * The capability check itself (edit_post_link()'s own job) still lives
 * in core, not a theme: see that function's docblock for why.
 */
final class ActiveAuth
{
    private static ?Auth $auth = null;

    public static function set(Auth $auth): void
    {
        self::$auth = $auth;
    }

    public static function auth(): Auth
    {
        if (self::$auth === null) {
            throw new RuntimeException('ActiveAuth has not been initialized.');
        }

        return self::$auth;
    }
}
