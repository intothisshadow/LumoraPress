<?php

/**
 * Static bridge exposing the bootstrapped database connection to plugin code with no route to the Kernel of its own.
 *
 * @package LumoraPress
 * @subpackage Database
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 1.0.2
 */

declare(strict_types=1);

namespace LumoraPress\Core\Database;

/**
 * Lets plugin shortcodes and widgets reuse the request's one connection
 * instead of opening another. Shared hosts cap simultaneous connections per
 * database user, so a second connection per request makes "too many
 * connections" errors twice as likely under load.
 */
final class ActiveDatabase
{
    private static ?Database $instance = null;

    public static function set(?Database $database): void
    {
        self::$instance = $database;
    }

    /**
     * The shared connection when bootstrap has set one, otherwise a new
     * connection from the install's own config (tests, CLI scripts).
     *
     * @param array<string, mixed> $config the install's config.php values
     */
    public static function forConfig(array $config): Database
    {
        return self::$instance ?? Database::connect(
            host: (string) $config['db_host'],
            database: (string) $config['db_name'],
            username: (string) $config['db_user'],
            password: (string) $config['db_password'],
            port: (int) ($config['db_port'] ?? 3306),
        );
    }
}
