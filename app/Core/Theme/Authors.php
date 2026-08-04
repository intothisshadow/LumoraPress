<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use LumoraPress\Services\UserService;
use RuntimeException;

/**
 * Static bridge exposing UserService (LP-008 author archives/profile
 * links) to the procedural the_author()/author_url() helpers in
 * include/helpers.php — same shape as FeaturedImages, since resolving a
 * post's author is a live, per-post lookup each render rather than a
 * value that can be snapshotted once at bootstrap like SiteBranding's.
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
