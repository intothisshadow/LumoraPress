<?php

/**
 * Reads a source site's wp-config.php as plain text to pre-fill the import connection form (LPP-004).
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\WordPressImporter;

/**
 * Parses database credentials and the uploads folder location out of a
 * source WordPress install's wp-config.php, so the admin doesn't have to
 * copy each value into the Import screen's connection form by hand.
 *
 * wp-config.php is treated as untrusted input from an arbitrary external
 * site's filesystem — it is read and pattern-matched as plain text
 * (line-by-line regex over `define('DB_...', '...')` and
 * `$table_prefix = '...'` statements), never `include`d or `eval`d. A
 * malformed or heavily customized wp-config.php simply yields fewer
 * detected fields rather than executing anything.
 *
 * Every returned value is a pre-fill suggestion only — the admin's own
 * connection form fields remain fully editable either way.
 */
final class WordPressConfigParser
{
    /**
     * @return array{db_host?: string, db_port?: string, db_name?: string, db_user?: string, db_password?: string, db_prefix?: string, uploads_path?: string}
     */
    public function parse(string $wpConfigPath): array
    {
        if (!is_file($wpConfigPath) || !is_readable($wpConfigPath)) {
            throw new \RuntimeException("wp-config.php was not found or is not readable at \"{$wpConfigPath}\".");
        }

        $contents = file_get_contents($wpConfigPath);

        if ($contents === false) {
            throw new \RuntimeException("Could not read \"{$wpConfigPath}\".");
        }

        $detected = [];

        $dbName = self::matchDefine($contents, 'DB_NAME');
        $dbUser = self::matchDefine($contents, 'DB_USER');
        $dbPassword = self::matchDefine($contents, 'DB_PASSWORD');
        $dbHost = self::matchDefine($contents, 'DB_HOST');
        $tablePrefix = self::matchTablePrefix($contents);

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
            // WordPress allows DB_HOST to carry a "host:port" (or, more
            // rarely, "host:/path/to/socket") value — only a numeric
            // suffix is split off into db_port, so an unrecognized
            // socket-path suffix is left in db_host as-is rather than
            // guessed at.
            if (preg_match('/^(.+):(\d+)$/', $dbHost, $hostMatch) === 1) {
                $detected['db_host'] = $hostMatch[1];
                $detected['db_port'] = $hostMatch[2];
            } else {
                $detected['db_host'] = $dbHost;
            }
        }

        if ($tablePrefix !== null) {
            $detected['db_prefix'] = $tablePrefix;
        }

        $uploadsPath = self::resolveUploadsPath($contents, $wpConfigPath);

        if ($uploadsPath !== null) {
            $detected['uploads_path'] = $uploadsPath;
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

    private static function matchTablePrefix(string $contents): ?string
    {
        if (preg_match('/\$table_prefix\s*=\s*[\'"]((?:[^\'"\\\\]|\\\\.)*)[\'"]\s*;/', $contents, $match) !== 1) {
            return null;
        }

        return stripslashes($match[1]);
    }

    /**
     * wp-config.php conventionally sits at the WordPress install root
     * (ABSPATH), so the uploads folder is `<that directory>/wp-content/
     * uploads` unless overridden — WP_CONTENT_DIR replaces the
     * `wp-content` segment (as an absolute path, or one relative to
     * ABSPATH), and UPLOADS replaces the whole `wp-content/uploads`
     * path, relative to ABSPATH, when either constant is present.
     */
    private static function resolveUploadsPath(string $contents, string $wpConfigPath): ?string
    {
        $absPath = rtrim(dirname($wpConfigPath), '/');

        $uploadsOverride = self::matchDefinedPath($contents, 'UPLOADS', $absPath);

        if ($uploadsOverride !== null && $uploadsOverride !== '') {
            $candidate = $absPath . '/' . ltrim($uploadsOverride, '/');
        } else {
            $contentDir = self::matchDefinedPath($contents, 'WP_CONTENT_DIR', $absPath);

            if ($contentDir !== null && $contentDir !== '') {
                $contentDir = str_starts_with($contentDir, '/')
                    ? rtrim($contentDir, '/')
                    : $absPath . '/' . ltrim(rtrim($contentDir, '/'), '/');
            } else {
                $contentDir = $absPath . '/wp-content';
            }

            $candidate = $contentDir . '/uploads';
        }

        return is_dir($candidate) ? $candidate : null;
    }

    /**
     * Reads a `define('CONST', ...)` value that names a path, covering
     * the two forms real wp-config.php files use for WP_CONTENT_DIR/
     * UPLOADS: a plain string literal, or the common
     * `dirname(__FILE__) . '/segment'` / `__DIR__ . '/segment'`
     * concatenation. The latter is recognized by pattern only — never
     * evaluated as PHP — so it only ever yields a path relative to
     * wp-config.php's own directory, which is already what $absPath is.
     */
    private static function matchDefinedPath(string $contents, string $constant, string $absPath): ?string
    {
        $literal = self::matchDefine($contents, $constant);

        if ($literal !== null) {
            return $literal;
        }

        $pattern = '/define\s*\(\s*[\'"]' . preg_quote($constant, '/') . '[\'"]\s*,\s*(?:dirname\s*\(\s*__FILE__\s*\)|__DIR__)\s*\.\s*[\'"]((?:[^\'"\\\\]|\\\\.)*)[\'"]\s*\)/';

        if (preg_match($pattern, $contents, $match) !== 1) {
            return null;
        }

        return ltrim(stripslashes($match[1]), '/');
    }
}
