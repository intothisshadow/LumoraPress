<?php

/**
 * Stores and validates this plugin's connection settings for a separately-installed Lumora Gallery site's database.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraGalleryShortcodes;

use LumoraPress\Core\ActiveConfig;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Database\DatabaseConnectionException;
use Throwable;

/**
 * Settings are stored as one JSON-encoded row in this site's own options
 * table (`ActiveConfig`/`PressConfig`), the same pattern
 * `LumoraShieldService::settings()`/`saveSettings()` already established
 * — no dedicated table needed for a handful of connection fields.
 *
 * This plugin never assumes Lumora Gallery shares this site's database,
 * filesystem, or Kernel — it opens its own independent, read-only PDO
 * connection to a *separately-configured* Gallery database, mirroring
 * `content/plugins/wordpress-importer/`'s own `WordPressSource` pattern
 * for talking to another application's database entirely.
 */
final class GallerySettingsService
{
    private const OPTION_KEY = 'lumora_gallery_shortcodes_settings';

    /**
     * @var array{db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}|null
     */
    private ?array $settingsCache = null;

    /**
     * @return array{db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $settings = is_array($decoded) ? $decoded : [];

        $this->settingsCache = [
            'db_host' => (string) ($settings['db_host'] ?? ''),
            'db_port' => (int) ($settings['db_port'] ?? 3306),
            'db_name' => (string) ($settings['db_name'] ?? ''),
            'db_user' => (string) ($settings['db_user'] ?? ''),
            'db_password' => (string) ($settings['db_password'] ?? ''),
            'table_prefix' => (string) ($settings['table_prefix'] ?? 'lum_'),
            'base_url' => (string) ($settings['base_url'] ?? ''),
        ];

        return $this->settingsCache;
    }

    /**
     * @param array{db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string} $settings
     */
    public function saveSettings(array $settings): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    public function isConfigured(): bool
    {
        $settings = $this->settings();

        return $settings['db_host'] !== '' && $settings['db_name'] !== '' && $settings['db_user'] !== '';
    }

    /**
     * Opens a fresh, read-only-in-practice connection (no write query is
     * ever issued against it — see `GalleryQueryService`) to the
     * configured Gallery database. Null on any failure — connection
     * refused, wrong credentials, unreachable host — so a caller never
     * has to catch a raw PDO/`DatabaseConnectionException` itself, and a
     * misconfigured or temporarily-down Gallery site never surfaces a
     * stack trace or connection string on this site's own pages (per
     * `CLAUDE.md`'s "fail securely" requirement).
     */
    public function connect(): ?Database
    {
        $settings = $this->settings();

        if (!$this->isConfigured()) {
            return null;
        }

        try {
            return Database::connect(
                host: $settings['db_host'],
                database: $settings['db_name'],
                username: $settings['db_user'],
                password: $settings['db_password'],
                port: $settings['db_port'],
            );
        } catch (DatabaseConnectionException) {
            return null;
        }
    }

    /**
     * Used by the Settings screen's "Test Connection" action — a real
     * query against the configured table prefix's `albums` table, not
     * just a successful login, since a connection can succeed against
     * the wrong database/prefix entirely (a same-server MySQL user with
     * access to several databases, for instance).
     */
    public function testConnection(): bool
    {
        $database = $this->connect();

        if ($database === null) {
            return false;
        }

        try {
            $database->fetchColumn('SELECT 1 FROM ' . $this->settings()['table_prefix'] . 'albums LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
