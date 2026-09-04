<?php

/**
 * Read-only PDO access to a source WordPress database.
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
 * Wraps a second Database connection to the *source* WordPress site's own
 * MySQL/MariaDB server and exposes typed, read-only queries over its core
 * schema. Every query names its columns and filters explicitly — never a
 * bare `SELECT *` — since a real WordPress database mixes in dozens of
 * unrelated plugin tables, and wp_options can carry millions of rows from
 * tracking plugins alone.
 */
final class WordPressSource implements WordPressSourceInterface
{
    /**
     * WordPress HTML-entity-encodes plain-text fields before storing them
     * (e.g. "TV & Movies" saves as `TV &amp; Movies`). Lumora Press's own
     * esc_html()/esc_attr() would re-encode that on output, so every
     * plain-text field this class reads must be decoded exactly once here.
     */
    private static function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The only option_name values this class ever reads.
     *
     * @var array<int, string>
     */
    private const SITE_OPTION_KEYS = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset',
        'permalink_structure', 'date_format', 'time_format',
        // home/siteurl can differ (e.g. WordPress in a subdirectory);
        // 'home' is the one page/post permalinks are actually built from.
        'home', 'siteurl',
        // page_on_front/page_for_posts are WordPress page IDs, resolved to
        // local ids once the 'pages' stage runs — see
        // WordPressImportService::applyPageDependentSiteSettings().
        'show_on_front', 'page_on_front', 'page_for_posts',
        // Reading.
        'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'blog_public',
        // Discussion.
        'default_comment_status', 'comment_moderation', 'comment_whitelist',
        'close_comments_for_old_posts', 'close_comments_days_old',
        'thread_comments', 'thread_comments_depth', 'page_comments',
        'comments_per_page', 'default_comments_page', 'comment_order',
        'comments_notify', 'moderation_notify', 'require_name_email',
        'comment_registration', 'moderation_keys',
        // WordPress 5.5 renamed 'blacklist_keys' to 'disallowed_keys' —
        // both are read so an older source database still resolves.
        'disallowed_keys', 'blacklist_keys', 'comment_max_links',
        'show_avatars', 'avatar_rating', 'avatar_default',
        // Media (Settings > Media's thumbnail/medium/large dimensions).
        'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
        'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
        // Privacy — also a WordPress page ID, resolved the same way as
        // page_on_front/page_for_posts above.
        'wp_page_for_privacy_policy',
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
     * True only if the connection is live *and* a posts table exists under
     * the given prefix — a wrong prefix against an otherwise reachable
     * server is the most common failure mode here.
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
                'display_name' => self::decodeEntities((string) $row['display_name']),
                'user_registered' => (string) $row['user_registered'],
            ],
            $rows,
        );
    }

    /**
     * Reads {prefix}capabilities from wp_usermeta and maps it to the
     * closest UserRole, falling back to Subscriber for an unmapped role.
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
     * @return array<int, array{term_id: int, term_taxonomy_id: int, name: string, slug: string, parent: int}>
     */
    public function terms(string $taxonomy): array
    {
        $rows = $this->source->fetchAll(
            'SELECT t.term_id, tt.term_taxonomy_id, t.name, t.slug, tt.parent
               FROM ' . $this->table('term_taxonomy') . ' tt
               JOIN ' . $this->table('terms') . ' t ON t.term_id = tt.term_id
              WHERE tt.taxonomy = :taxonomy
              ORDER BY tt.parent ASC, t.name ASC',
            ['taxonomy' => $taxonomy],
        );

        return array_map(
            static fn (array $row): array => [
                'term_id' => (int) $row['term_id'],
                'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
                'name' => self::decodeEntities((string) $row['name']),
                'slug' => (string) $row['slug'],
                'parent' => (int) $row['parent'],
            ],
            $rows,
        );
    }

    /**
     * Every `nav_menu_item` post's `nav_menu` term id, in one query rather
     * than calling termIdsForPost() once per item.
     *
     * @return array<int, int> nav_menu_item post id => nav_menu term id
     */
    public function navMenuItemTermTaxonomyIds(): array
    {
        $rows = $this->source->fetchAll(
            'SELECT tr.object_id, t.term_id
               FROM ' . $this->table('term_relationships') . ' tr
               JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               JOIN ' . $this->table('terms') . ' t ON t.term_id = tt.term_id
              WHERE tt.taxonomy = :taxonomy',
            ['taxonomy' => 'nav_menu'],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['object_id']] = (int) $row['term_id'];
        }

        return $map;
    }

    /**
     * A single option's raw value by exact name — for options like
     * `sidebars_widgets` that don't fit siteOptions()'s fixed allowlist.
     */
    public function option(string $name): ?string
    {
        $row = $this->source->fetchOne(
            'SELECT option_value FROM ' . $this->table('options') . ' WHERE option_name = :name',
            ['name' => $name],
        );

        return $row !== null ? (string) $row['option_value'] : null;
    }

    /**
     * Every option whose name starts with $prefix — the one deliberately
     * widened read this class makes, needed because a site can have 70+
     * distinct `widget_*` option names with no fixed allowlist to write.
     * $prefix is always a hardcoded literal from calling code, never user
     * input, so its LIKE wildcard characters are intentionally unescaped.
     *
     * @return array<string, string>
     */
    public function optionsLike(string $prefix): array
    {
        $rows = $this->source->fetchAll(
            'SELECT option_name, option_value FROM ' . $this->table('options') . ' WHERE option_name LIKE :prefix',
            ['prefix' => $prefix . '%'],
        );

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['option_name']] = (string) $row['option_value'];
        }

        return $options;
    }

    /**
     * @param array<int, string> $postTypes
     * @param array<int, string> $statuses
     * @return array<int, array{ID: int, post_author: int, post_date: string, post_content: string, post_title: string, post_excerpt: string, post_status: string, post_name: string, post_parent: int, guid: string, post_type: string, menu_order: int}>
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
            'SELECT ID, post_author, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_parent, guid, post_type, menu_order
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
                // Plain-text fields need decoding; post_content is real
                // HTML and HtmlSanitizer handles any encoding within it.
                'post_title' => self::decodeEntities((string) $row['post_title']),
                'post_excerpt' => self::decodeEntities((string) $row['post_excerpt']),
                'post_status' => (string) $row['post_status'],
                'post_name' => (string) $row['post_name'],
                'post_parent' => (int) $row['post_parent'],
                'guid' => (string) $row['guid'],
                'post_type' => (string) $row['post_type'],
                'menu_order' => (int) $row['menu_order'],
            ],
            $rows,
        );
    }

    /**
     * First value per meta_key — every key this importer reads is
     * single-valued in practice, even though the schema allows repeats.
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

        return array_map(static fn (array $row): string => self::decodeEntities((string) $row['name']), $rows);
    }

    /**
     * Mirrors termNamesForPost(), returning term ids instead of names, for
     * a caller resolving through an already-built wpTermId => local id map.
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
                // Both are plain-text fields rendered via esc_html().
                'comment_author' => self::decodeEntities((string) $row['comment_author']),
                'comment_author_email' => (string) $row['comment_author_email'],
                'comment_author_url' => (string) $row['comment_author_url'],
                'comment_date' => (string) $row['comment_date'],
                'comment_content' => self::decodeEntities((string) $row['comment_content']),
                'comment_approved' => (string) $row['comment_approved'],
            ],
            $rows,
        );
    }

    /**
     * Simple Download Monitor's download total: `sdm_count_offset`
     * postmeta plus every logged row. Only the aggregate count and most
     * recent timestamp are read, never per-visitor log data (IP, country,
     * user agent). The `sdm_downloads` table won't exist on a site that
     * never ran the plugin — caught the same way as testConnection().
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

    /**
     * NextGEN Gallery's `ngg_gallery` table. `path` is the actual on-disk
     * folder name and can differ from `slug` when a gallery was renamed
     * after creation, so file lookups always use `path`. Plain-text fields
     * here aren't run through decodeEntities() — NextGEN Gallery has its
     * own save routine, separate from WordPress's own KSES-filtered one.
     * Missing on a source site that never ran the plugin.
     *
     * @return array<int, array{gid: int, name: string, slug: string, path: string, title: string, galdesc: string, author: int}>
     */
    public function nextGenGalleries(): array
    {
        try {
            $rows = $this->source->fetchAll(
                'SELECT gid, name, slug, path, title, galdesc, author FROM ' . $this->table('ngg_gallery') . ' ORDER BY gid ASC',
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'gid' => (int) $row['gid'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'path' => (string) $row['path'],
                'title' => (string) $row['title'],
                'galdesc' => (string) $row['galdesc'],
                'author' => (int) $row['author'],
            ],
            $rows,
        );
    }

    /**
     * NextGEN Gallery's `ngg_pictures` table, scoped to one gallery — see
     * nextGenGalleries() for why fields here aren't entity-decoded.
     *
     * @return array<int, array{pid: int, filename: string, description: string, alttext: string, imagedate: string, exclude: int}>
     */
    public function nextGenPictures(int $galleryId): array
    {
        try {
            $rows = $this->source->fetchAll(
                'SELECT pid, filename, description, alttext, imagedate, exclude
                   FROM ' . $this->table('ngg_pictures') . '
                  WHERE galleryid = :gallery_id
                  ORDER BY sortorder ASC, pid ASC',
                ['gallery_id' => $galleryId],
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'pid' => (int) $row['pid'],
                'filename' => (string) $row['filename'],
                'description' => (string) $row['description'],
                'alttext' => (string) $row['alttext'],
                'imagedate' => (string) $row['imagedate'],
                'exclude' => (int) $row['exclude'],
            ],
            $rows,
        );
    }

    /**
     * NextGEN Gallery's `ngg_album` table. Member gallery ids live in
     * `sortorder` as base64-encoded JSON (e.g. `WyI5IiwiOCJd` → `["9","8"]`),
     * not a `gallery_ids` column. A malformed/empty value yields an empty
     * `galleryIds` list rather than throwing — the album still gets created.
     *
     * @return array<int, array{id: int, name: string, slug: string, galleryIds: array<int, int>}>
     */
    public function nextGenAlbums(): array
    {
        try {
            $rows = $this->source->fetchAll(
                'SELECT id, name, slug, sortorder FROM ' . $this->table('ngg_album') . ' ORDER BY id ASC',
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(
            function (array $row): array {
                $decoded = base64_decode((string) $row['sortorder'], true);
                $galleryIds = $decoded !== false ? json_decode($decoded, true) : null;

                return [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'slug' => (string) $row['slug'],
                    'galleryIds' => is_array($galleryIds) ? array_map('intval', $galleryIds) : [],
                ];
            },
            $rows,
        );
    }

    /**
     * @return array<int, string>
     */
    public function oldSlugs(int $postId): array
    {
        $rows = $this->source->fetchAll(
            'SELECT meta_value FROM ' . $this->table('postmeta') . '
                WHERE post_id = :post_id AND meta_key = :meta_key
             ORDER BY meta_id ASC',
            ['post_id' => $postId, 'meta_key' => '_wp_old_slug'],
        );

        return array_map(static fn (array $row): string => (string) $row['meta_value'], $rows);
    }

    /**
     * A single aggregate query, never a row dump — only post_type and a
     * count ever leave the database.
     *
     * @return array<string, int>
     */
    public function postTypeCounts(): array
    {
        $rows = $this->source->fetchAll(
            'SELECT post_type, COUNT(*) AS total FROM ' . $this->table('posts') . ' GROUP BY post_type',
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['post_type']] = (int) $row['total'];
        }

        return $counts;
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
