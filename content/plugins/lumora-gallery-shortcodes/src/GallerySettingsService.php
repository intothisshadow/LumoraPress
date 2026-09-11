<?php

/**
 * Stores and validates this plugin's connection settings for one or more separately-installed Lumora Gallery sites' databases.
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
 * Settings are stored as one JSON-encoded row in this site's own options table, the same
 * pattern `LumoraShieldService` uses — no dedicated table needed for a handful of Gallery
 * connections. Each connection is keyed by a short slug (the value a shortcode's own
 * `gallery="..."` attribute names); one of them is marked the default, used whenever a
 * shortcode omits `gallery` entirely.
 *
 * This plugin never assumes a Lumora Gallery site shares this site's database, filesystem, or
 * Kernel — it opens its own independent, read-only PDO connection per configured Gallery
 * database, mirroring `WordPressSource`'s pattern for another application's database.
 */
final class GallerySettingsService
{
    private const OPTION_KEY = 'lumora_gallery_shortcodes_settings';

    /**
     * @var array{connections: array<string, array{label: string, db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}>, default_connection: string|null}|null
     */
    private ?array $stateCache = null;

    /**
     * @return array<string, array{label: string, db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}>
     */
    public function connections(): array
    {
        return $this->state()['connections'];
    }

    /**
     * @return array{label: string, db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}|null
     */
    public function connection(string $slug): ?array
    {
        return $this->state()['connections'][$slug] ?? null;
    }

    /**
     * The slug a shortcode resolves to when it omits `gallery` entirely. Null only when no
     * connection has ever been configured — every real Gallery connection always has one,
     * since saveConnection() sets this the first time a connection is added.
     */
    public function defaultConnection(): ?string
    {
        return $this->state()['default_connection'];
    }

    public function setDefaultConnection(string $slug): void
    {
        $state = $this->state();

        if (!isset($state['connections'][$slug])) {
            return;
        }

        $state['default_connection'] = $slug;
        $this->persist($state);
    }

    /**
     * Creates or updates one connection. $slug is immutable once a connection exists — editing
     * a connection never renames it, so a `gallery="{slug}"` attribute already published in
     * content keeps resolving to the same site. The very first connection ever saved
     * automatically becomes the default.
     *
     * @param array{label: string, db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string} $settings
     */
    public function saveConnection(string $slug, array $settings): void
    {
        $state = $this->state();
        $state['connections'][$slug] = $settings;

        if ($state['default_connection'] === null) {
            $state['default_connection'] = $slug;
        }

        $this->persist($state);
    }

    /**
     * Removes one connection. If it was the default, the default falls back to whichever
     * connection happens to be first afterward, or null once none remain — the admin UI is
     * expected to stop an admin from deleting the last connection outright, but this stays
     * safe regardless.
     */
    public function deleteConnection(string $slug): void
    {
        $state = $this->state();

        if (!isset($state['connections'][$slug])) {
            return;
        }

        unset($state['connections'][$slug]);

        if ($state['default_connection'] === $slug) {
            $remainingSlugs = array_keys($state['connections']);
            $state['default_connection'] = $remainingSlugs[0] ?? null;
        }

        $this->persist($state);
    }

    /**
     * Derives a URL-safe, unique slug from a connection's label — e.g. "Xena Archive" becomes
     * "xena-archive", or "xena-archive-2" if that slug is already taken. Used by the Settings
     * screen when adding a new connection; editing an existing one never re-derives its slug.
     *
     * @param array<string, mixed> $existingConnections keyed by already-taken slug
     */
    public static function slugify(string $label, array $existingConnections): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $label), '-'));

        if ($base === '') {
            $base = 'gallery';
        }

        $slug = $base;
        $suffix = 2;

        while (isset($existingConnections[$slug])) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    public function isConfigured(string $slug): bool
    {
        $connection = $this->connection($slug);

        return $connection !== null && $connection['db_host'] !== '' && $connection['db_name'] !== '' && $connection['db_user'] !== '';
    }

    /**
     * True when at least one connection is usable — backs the "no Gallery connection
     * configured at all yet" warning on the Shortcodes docs screen.
     */
    public function hasAnyConfiguredConnection(): bool
    {
        foreach (array_keys($this->connections()) as $slug) {
            if ($this->isConfigured($slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opens a fresh, read-only-in-practice connection (no write query is
     * ever issued against it — see `GalleryQueryService`) to one
     * configured Gallery database. Null on any failure — unknown slug,
     * unconfigured connection, connection refused, wrong credentials,
     * unreachable host — so a caller never has to catch a raw PDO/
     * `DatabaseConnectionException` itself, and a misconfigured or
     * temporarily-down Gallery site never surfaces a stack trace or
     * connection string on this site's own pages.
     */
    public function connect(string $slug): ?Database
    {
        $connection = $this->connection($slug);

        if ($connection === null || !$this->isConfigured($slug)) {
            return null;
        }

        try {
            return Database::connect(
                host: $connection['db_host'],
                database: $connection['db_name'],
                username: $connection['db_user'],
                password: $connection['db_password'],
                port: $connection['db_port'],
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
    public function testConnection(string $slug): bool
    {
        $database = $this->connect($slug);

        if ($database === null) {
            return false;
        }

        try {
            $database->fetchColumn('SELECT 1 FROM ' . $this->connection($slug)['table_prefix'] . 'albums LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Best-effort auto-detection of Gallery's `base_url`, which unlike the DB_* values isn't
     * a config.php constant — it lives in Gallery's own `{prefix}config` table, so detection
     * needs a query against the just-detected connection. Takes $settings directly (an ad hoc
     * array, not a stored connection) since this runs before the admin has saved anything.
     * Fails silently (returns null) on any connection/query error, like connect()/
     * testConnection() do for a saved connection.
     *
     * @param array{db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, db_prefix: string} $settings
     */
    public function detectBaseUrl(array $settings): ?string
    {
        try {
            $database = Database::connect(
                host: $settings['db_host'],
                database: $settings['db_name'],
                username: $settings['db_user'],
                password: $settings['db_password'],
                port: $settings['db_port'],
            );
        } catch (DatabaseConnectionException) {
            return null;
        }

        try {
            $value = $database->fetchColumn(
                'SELECT value FROM ' . $settings['db_prefix'] . "config WHERE name = 'base_url'",
            );

            return is_string($value) && $value !== '' ? rtrim($value, '/') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{connections: array<string, array{label: string, db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}>, default_connection: string|null}
     */
    private function state(): array
    {
        if ($this->stateCache !== null) {
            return $this->stateCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        if (is_array($decoded) && isset($decoded['connections']) && is_array($decoded['connections'])) {
            $connections = [];

            foreach ($decoded['connections'] as $slug => $settings) {
                if (!is_string($slug) || !is_array($settings)) {
                    continue;
                }

                $connections[$slug] = $this->normalizeConnection($settings);
            }

            $this->stateCache = [
                'connections' => $connections,
                'default_connection' => is_string($decoded['default_connection'] ?? null) && isset($connections[$decoded['default_connection']])
                    ? $decoded['default_connection']
                    : (array_key_first($connections) ?? null),
            ];

            return $this->stateCache;
        }

        // Pre-LPP-021 shape: one flat connection settings array (or an
        // empty/never-configured array) rather than {connections, ...}.
        // Wrapping it into a single "default" connection means an
        // existing install with one Gallery configured needs no admin
        // action at all, and every already-published shortcode (none of
        // which can name a `gallery` attribute that didn't exist yet)
        // keeps resolving to the exact same site it always has.
        $legacy = is_array($decoded) ? $decoded : [];
        $hadLegacyConnection = ($legacy['db_host'] ?? '') !== '' || ($legacy['db_name'] ?? '') !== '';

        $this->stateCache = $hadLegacyConnection
            ? [
                'connections' => ['default' => $this->normalizeConnection([...$legacy, 'label' => 'Gallery'])],
                'default_connection' => 'default',
            ]
            : ['connections' => [], 'default_connection' => null];

        $this->persist($this->stateCache);

        return $this->stateCache;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{label: string, db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}
     */
    private function normalizeConnection(array $settings): array
    {
        return [
            'label' => (string) ($settings['label'] ?? ''),
            'db_host' => (string) ($settings['db_host'] ?? ''),
            'db_port' => (int) ($settings['db_port'] ?? 3306),
            'db_name' => (string) ($settings['db_name'] ?? ''),
            'db_user' => (string) ($settings['db_user'] ?? ''),
            'db_password' => (string) ($settings['db_password'] ?? ''),
            'table_prefix' => (string) ($settings['table_prefix'] ?? 'lum_'),
            'base_url' => (string) ($settings['base_url'] ?? ''),
        ];
    }

    /**
     * @param array{connections: array<string, array{label: string, db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, table_prefix: string, base_url: string}>, default_connection: string|null} $state
     */
    private function persist(array $state): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($state));
        $this->stateCache = $state;
    }
}
