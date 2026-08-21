<?php

/**
 * Read-only PDO access to a source WordPress database (LPP-004).
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

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\UserRole;
use Throwable;

/**
 * Wraps a second, independent Database connection (Database::connect()
 * against the *source* WordPress site's own MySQL/MariaDB credentials —
 * a different server/database entirely from this application's own) and
 * exposes typed, read-only queries over the standard WordPress core
 * schema. Every query names its columns and filters explicitly by
 * post_type/taxonomy/option_name — never a bare `SELECT *` over
 * wp_posts/wp_options — because a real WordPress install's database
 * typically carries dozens of unrelated plugin tables (stats, caching,
 * consent, import history) mixed in alongside the core tables this class
 * cares about, and wp_options in particular can carry millions of rows
 * from tracking/analytics plugins.
 *
 * No writes happen here at all — this class only ever reads.
 */
final class WordPressSource
{
    /**
     * The only option_name values this class ever reads — see class
     * docblock for why a full table scan is never acceptable here.
     *
     * @var array<int, string>
     */
    private const SITE_OPTION_KEYS = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset',
        'permalink_structure', 'date_format', 'time_format',
    ];

    public function __construct(
        private readonly Database $source,
        private readonly string $tablePrefix,
    ) {
    }

    public static function connect(
        string $host,
        string $database,
        string $username,
        string $password,
        string $tablePrefix,
        int $port = 3306,
    ): self {
        return new self(Database::connect($host, $database, $username, $password, port: $port), $tablePrefix);
    }

    /**
     * True only if the connection is live *and* a wp_posts table exists
     * under the given prefix — a wrong table prefix against an otherwise
     * reachable database server is the most common failure mode here,
     * and connect()'s own DatabaseConnectionException only covers
     * "couldn't reach the server at all" (see this class's connect()).
     */
    public function testConnection(): bool
    {
        try {
            $this->source->fetchColumn('SELECT 1 FROM ' . $this->table('posts') . ' LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function siteOptions(): array
    {
        $placeholders = [];
        $params = [];

        foreach (self::SITE_OPTION_KEYS as $i => $key) {
            $placeholders[] = ":key{$i}";
            $params["key{$i}"] = $key;
        }

        $rows = $this->source->fetchAll(
            'SELECT option_name, option_value FROM ' . $this->table('options') . '
                WHERE option_name IN (' . implode(', ', $placeholders) . ')',
            $params,
        );

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['option_name']] = (string) $row['option_value'];
        }

        return $options;
    }

    /**
     * @return array<int, array{ID: int, user_login: string, user_email: string, display_name: string, user_registered: string}>
     */
    public function users(): array
    {
        $rows = $this->source->fetchAll(
            'SELECT ID, user_login, user_email, display_name, user_registered FROM ' . $this->table('users') . ' ORDER BY ID ASC',
        );

        return array_map(
            static fn (array $row): array => [
                'ID' => (int) $row['ID'],
                'user_login' => (string) $row['user_login'],
                'user_email' => (string) $row['user_email'],
                'display_name' => (string) $row['display_name'],
                'user_registered' => (string) $row['user_registered'],
            ],
            $rows,
        );
    }

    /**
     * Reads {prefix}capabilities from wp_usermeta (WordPress's native
     * PHP-serialized role storage) and maps it to the closest matching
     * UserRole, falling back to Subscriber for a WordPress role with no
     * Lumora Press equivalent (e.g. a custom role a plugin added).
     */
    public function userRole(int $wpUserId): UserRole
    {
        $row = $this->source->fetchOne(
            'SELECT meta_value FROM ' . $this->table('usermeta') . '
                WHERE user_id = :user_id AND meta_key = :meta_key',
            ['user_id' => $wpUserId, 'meta_key' => $this->tablePrefix . 'capabilities'],
        );

        if ($row === null) {
            return UserRole::Subscriber;
        }

        $capabilities = @unserialize((string) $row['meta_value'], ['allowed_classes' => false]);

        if (!is_array($capabilities)) {
            return UserRole::Subscriber;
        }

        foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $wpRole) {
            if (!empty($capabilities[$wpRole])) {
                return UserRole::from($wpRole);
            }
        }

        return UserRole::Subscriber;
    }

    /**
     * @return array<int, array{term_id: int, name: string, slug: string, parent: int}>
     */
    public function terms(string $taxonomy): array
    {
        $rows = $this->source->fetchAll(
            'SELECT t.term_id, t.name, t.slug, tt.parent
               FROM ' . $this->table('term_taxonomy') . ' tt
               JOIN ' . $this->table('terms') . ' t ON t.term_id = tt.term_id
              WHERE tt.taxonomy = :taxonomy
              ORDER BY tt.parent ASC, t.name ASC',
            ['taxonomy' => $taxonomy],
        );

        return array_map(
            static fn (array $row): array => [
                'term_id' => (int) $row['term_id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'parent' => (int) $row['parent'],
            ],
            $rows,
        );
    }

    /**
     * @param array<int, string> $postTypes
     * @param array<int, string> $statuses
     * @return array<int, array{ID: int, post_author: int, post_date: string, post_content: string, post_title: string, post_excerpt: string, post_status: string, post_name: string, post_parent: int, guid: string, post_type: string}>
     */
    public function posts(array $postTypes, array $statuses): array
    {
        $typePlaceholders = [];
        $params = [];

        foreach ($postTypes as $i => $type) {
            $typePlaceholders[] = ":type{$i}";
            $params["type{$i}"] = $type;
        }

        $statusPlaceholders = [];

        foreach ($statuses as $i => $status) {
            $statusPlaceholders[] = ":status{$i}";
            $params["status{$i}"] = $status;
        }

        $rows = $this->source->fetchAll(
            'SELECT ID, post_author, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_parent, guid, post_type
               FROM ' . $this->table('posts') . '
              WHERE post_type IN (' . implode(', ', $typePlaceholders) . ')
                AND post_status IN (' . implode(', ', $statusPlaceholders) . ')
              ORDER BY post_parent ASC, ID ASC',
            $params,
        );

        return array_map(
            static fn (array $row): array => [
                'ID' => (int) $row['ID'],
                'post_author' => (int) $row['post_author'],
                'post_date' => (string) $row['post_date'],
                'post_content' => (string) $row['post_content'],
                'post_title' => (string) $row['post_title'],
                'post_excerpt' => (string) $row['post_excerpt'],
                'post_status' => (string) $row['post_status'],
                'post_name' => (string) $row['post_name'],
                'post_parent' => (int) $row['post_parent'],
                'guid' => (string) $row['guid'],
                'post_type' => (string) $row['post_type'],
            ],
            $rows,
        );
    }

    /**
     * First value per meta_key, keyed by key — every meta key this
     * importer reads (_wp_attached_file, _thumbnail_id,
     * _wp_attachment_image_alt) is single-valued in practice, even
     * though WordPress's schema technically allows repeats.
     *
     * @return array<string, string>
     */
    public function postMeta(int $postId): array
    {
        $rows = $this->source->fetchAll(
            'SELECT meta_key, meta_value FROM ' . $this->table('postmeta') . ' WHERE post_id = :post_id',
            ['post_id' => $postId],
        );

        $meta = [];

        foreach ($rows as $row) {
            $key = (string) $row['meta_key'];

            if (!isset($meta[$key])) {
                $meta[$key] = (string) $row['meta_value'];
            }
        }

        return $meta;
    }

    /**
     * @return array<int, string>
     */
    public function termNamesForPost(int $postId, string $taxonomy): array
    {
        $rows = $this->source->fetchAll(
            'SELECT t.name
               FROM ' . $this->table('term_relationships') . ' tr
               JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               JOIN ' . $this->table('terms') . ' t ON t.term_id = tt.term_id
              WHERE tr.object_id = :object_id AND tt.taxonomy = :taxonomy',
            ['object_id' => $postId, 'taxonomy' => $taxonomy],
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * Mirrors termNamesForPost() exactly, returning term ids instead of
     * names — for a caller that already has a wpTermId => local id map
     * built (e.g. Folders imported from a non-hierarchy-free taxonomy)
     * and needs to resolve through it rather than by name.
     *
     * @return array<int, int>
     */
    public function termIdsForPost(int $postId, string $taxonomy): array
    {
        $rows = $this->source->fetchAll(
            'SELECT t.term_id
               FROM ' . $this->table('term_relationships') . ' tr
               JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               JOIN ' . $this->table('terms') . ' t ON t.term_id = tt.term_id
              WHERE tr.object_id = :object_id AND tt.taxonomy = :taxonomy',
            ['object_id' => $postId, 'taxonomy' => $taxonomy],
        );

        return array_map(static fn (array $row): int => (int) $row['term_id'], $rows);
    }

    /**
     * @return array<int, array{comment_ID: int, comment_post_ID: int, comment_parent: int, user_id: int, comment_author: string, comment_author_email: string, comment_author_url: string, comment_date: string, comment_content: string, comment_approved: string}>
     */
    public function comments(int $postId): array
    {
        $rows = $this->source->fetchAll(
            'SELECT comment_ID, comment_post_ID, comment_parent, user_id, comment_author, comment_author_email, comment_author_url, comment_date, comment_content, comment_approved
               FROM ' . $this->table('comments') . '
              WHERE comment_post_ID = :post_id AND comment_type IN (:type_comment, :type_empty)
              ORDER BY comment_parent ASC, comment_ID ASC',
            ['post_id' => $postId, 'type_comment' => 'comment', 'type_empty' => ''],
        );

        return array_map(
            static fn (array $row): array => [
                'comment_ID' => (int) $row['comment_ID'],
                'comment_post_ID' => (int) $row['comment_post_ID'],
                'comment_parent' => (int) $row['comment_parent'],
                'user_id' => (int) $row['user_id'],
                'comment_author' => (string) $row['comment_author'],
                'comment_author_email' => (string) $row['comment_author_email'],
                'comment_author_url' => (string) $row['comment_author_url'],
                'comment_date' => (string) $row['comment_date'],
                'comment_content' => (string) $row['comment_content'],
                'comment_approved' => (string) $row['comment_approved'],
            ],
            $rows,
        );
    }

    /**
     * Simple Download Monitor's own download total for one `sdm_downloads`
     * post: `sdm_count_offset` postmeta (an admin-settable "starting
     * count", read the same way sdm_upload/sdm_description already are)
     * plus every row logged in its own `{prefix}sdm_downloads` table —
     * confirmed against a real production SDM install: post #2174's
     * offset of 275 plus 920 logged rows matched its real displayed
     * total of 1195 exactly. Only the aggregate count (and the most
     * recent log row's timestamp) is read here, never the log rows
     * themselves — each one carries a visitor IP/country/user agent/
     * referrer, an unasked-for privacy footprint this importer has no
     * reason to bring in.
     *
     * `{prefix}sdm_downloads` (a plugin table, not core WordPress) won't
     * exist at all on a source site that never ran Simple Download
     * Monitor — caught the same way testConnection() above catches a
     * connection-level failure, since a query against a genuinely
     * missing table throws regardless of how carefully it's scoped.
     *
     * @return array{count: int, lastDownloadedAt: ?DateTimeImmutable}
     */
    public function sdmDownloadStats(int $postId): array
    {
        $offset = (int) ($this->postMeta($postId)['sdm_count_offset'] ?? 0);

        try {
            $row = $this->source->fetchOne(
                'SELECT COUNT(*) AS log_count, MAX(date_time) AS last_downloaded_at
                   FROM ' . $this->table('sdm_downloads') . '
                  WHERE post_id = :post_id',
                ['post_id' => $postId],
            );
        } catch (Throwable) {
            return ['count' => $offset, 'lastDownloadedAt' => null];
        }

        $logCount = $row !== null ? (int) $row['log_count'] : 0;
        $lastDownloadedAt = $row !== null && $row['last_downloaded_at'] !== null
            ? new DateTimeImmutable((string) $row['last_downloaded_at'])
            : null;

        return ['count' => $offset + $logCount, 'lastDownloadedAt' => $lastDownloadedAt];
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
