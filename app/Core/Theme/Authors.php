<?php

/**
 * Static bridge exposing UserService to the procedural the_author()/author_url() theme helpers (LP-008).
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

use LumoraPress\Services\UserService;
use RuntimeException;

/**
 * Bridges UserService to the_author()/author_url() — a live, per-post
 * lookup each render, so it can't be snapshotted like SiteBranding.
 */
final class Authors
{
    private static ?UserService $users = null;

    public static function set(UserService $users): void
    {
        self::$users = $users;
    }

    public static function users(): UserService
    {
        if (self::$users === null) {
            throw new RuntimeException('Authors has not been initialized.');
        }

        return self::$users;
    }
}
