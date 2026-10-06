<?php

/**
 * Opens the database connection the Link Directory's public output (shortcode and widget) uses.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use LumoraPress\Core\Database\Database;
use LumoraPress\Services\MediaService;

/**
 * The plugin's main file runs before the Kernel exists, so public output
 * that needs the database opens its own connection from the install's
 * config instead.
 */
final class LinkDirectoryConnection
{
    /**
     * @return array{0: Database, 1: string} the connection and the table prefix
     */
    public static function open(): array
    {
        $config = require LUMORA_ROOT . '/config/config.php';
        $database = Database::connect(
            host: (string) $config['db_host'],
            database: (string) $config['db_name'],
            username: (string) $config['db_user'],
            password: (string) $config['db_password'],
            port: (int) ($config['db_port'] ?? 3306),
        );

        return [$database, (string) $config['table_prefix']];
    }

    public static function media(): MediaService
    {
        [$database, $tablePrefix] = self::open();

        return new MediaService($database, $tablePrefix, LUMORA_ROOT . '/content/uploads', \LumoraPress\Core\Http\BasePath::get() . '/content/uploads');
    }
}
