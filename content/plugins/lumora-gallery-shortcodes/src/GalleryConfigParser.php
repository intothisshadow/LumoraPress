<?php

/**
 * Reads a separately-installed Lumora Gallery site's config.php as plain text to pre-fill this plugin's connection settings.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraGalleryShortcodes;

/**
 * Parses database credentials out of a Lumora Gallery install's
 * config.php, mirroring content/plugins/wordpress-importer/src/
 * WordPressConfigParser.php's exact approach and safety contract for the
 * identical underlying problem: config.php is treated as untrusted input
 * from an arbitrary external site's filesystem — read and pattern-matched
 * as plain text (`define('DB_...', '...')` statements), never `include`d
 * or `eval`d. A malformed or heavily customized config.php simply yields
 * fewer detected fields rather than executing anything.
 *
 * Every returned value is a pre-fill suggestion only — the admin's own
 * Settings form fields remain fully editable either way. Unlike
 * WordPressConfigParser, there is no uploads-folder path to resolve here
 * (this plugin only ever needs a database connection, never filesystem
 * access to the Gallery install), and Gallery's own config.php uses a
 * DB_PREFIX constant rather than WordPress's `$table_prefix` variable.
 */
final class GalleryConfigParser
{
    /**
     * @return array{db_host?: string, db_port?: string, db_name?: string, db_user?: string, db_password?: string, table_prefix?: string}
     */
    public function parse(string $configPath): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new \RuntimeException("config.php was not found or is not readable at \"{$configPath}\".");
        }

        $contents = file_get_contents($configPath);

        if ($contents === false) {
            throw new \RuntimeException("Could not read \"{$configPath}\".");
        }

        $detected = [];

        $dbName = self::matchDefine($contents, 'DB_NAME');
        $dbUser = self::matchDefine($contents, 'DB_USER');
        $dbPassword = self::matchDefine($contents, 'DB_PASS');
        $dbHost = self::matchDefine($contents, 'DB_HOST');
        $dbPrefix = self::matchDefine($contents, 'DB_PREFIX');

        if ($dbName !== null) {
            $detected['db_name'] = $dbName;
        }

        if ($dbUser !== null) {
            $detected['db_user'] = $dbUser;
        }

        if ($dbPassword !== null) {
            $detected['db_password'] = $dbPassword;
        }

        if ($dbHost !== null) {
            // Same "host:port" allowance WordPressConfigParser's own
            // DB_HOST handling makes — only a numeric suffix is split
            // off into db_port, so an unrecognized suffix is left in
            // db_host as-is rather than guessed at.
            if (preg_match('/^(.+):(\d+)$/', $dbHost, $hostMatch) === 1) {
                $detected['db_host'] = $hostMatch[1];
                $detected['db_port'] = $hostMatch[2];
            } else {
                $detected['db_host'] = $dbHost;
            }
        }

        if ($dbPrefix !== null) {
            $detected['table_prefix'] = $dbPrefix;
        }

        return $detected;
    }

    private static function matchDefine(string $contents, string $constant): ?string
    {
        $pattern = '/define\s*\(\s*[\'"]' . preg_quote($constant, '/') . '[\'"]\s*,\s*[\'"]((?:[^\'"\\\\]|\\\\.)*)[\'"]\s*\)/';

        if (preg_match($pattern, $contents, $match) !== 1) {
            return null;
        }

        return stripslashes($match[1]);
    }
}
