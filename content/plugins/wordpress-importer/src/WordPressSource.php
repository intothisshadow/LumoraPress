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
final class WordPressSource implements WordPressSourceInterface
{
    /**
     * WordPress HTML-entity-encodes plain-text fields (term names, post/
     * page titles and excerpts, display names, comment author names/
     * content) before storing them — typing "TV & Movies" into wp-admin
     * saves `TV &amp; Movies` in the database. Lumora Press's own
     * esc_html()/esc_attr() (include/helpers.php) then re-encode that
     * already-encoded value on the way out, producing `TV &amp;amp;
     * Movies` in the rendered HTML source — which a browser displays as
     * the literal text "TV &amp; Movies" rather than "TV & Movies"
     * (LP-113). Every plain-text field read by this class must be
     * decoded exactly once, here at the read boundary, before any
     * importer or service ever stores it — mirrors the identical
     * html_entity_decode() WordPressImportService::importSiteSettings()
     * already applies to blogname/blogdescription, extended to every
     * other plain-text field this class reads.
     */
    private static function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The only option_name values this class ever reads — see class
     * docblock for why a full table scan is never acceptable here.
     *
     * @var array<int, string>
     */
    private const SITE_OPTION_KEYS = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset',
        'permalink_structure', 'date_format', 'time_format',
        // 'home'/'siteurl' (LPP-004 URL & Link Migration) — the source
        // site's own base URL, read so internal in-content links can be
        // scoped to the source's own domain rather than rewriting a link
        // to some unrelated external site that happens to share a path
        // segment. WordPress keeps these as two separate options
        // (siteurl can differ from home on a install where WordPress
        // itself lives in a subdirectory) — 'home' is the one actual
        // page/post permalinks are built from.
        'home', 'siteurl',
        // Homepage (Settings > Reading's "Your homepage displays").
        // page_on_front/page_for_posts are WordPress page IDs, resolved
        // to local page IDs by WordPressImportService once the 'pages'
        // stage has run — see its applyPageDependentSiteSettings().
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
                'display_name' => self::decodeEntities((string) $row['display_name']),
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
     * Every `nav_menu_item` post's WordPress menu (taxonomy `nav_menu`)
     * term id, in one query — purpose-built for LPP-004 Stage 8's menu
     * import, joining term_relationships/term_taxonomy directly rather
     * than calling termIdsForPost() once per item (WordPress ties a menu
     * item to its menu the exact same way a post is tied to a category —
     * via term_relationships — so this is that same join, just scoped to
     * one taxonomy and returned in bulk).
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
     * A single option's raw value by its exact name — used for
     * `sidebars_widgets` and `theme_mods_{stylesheet}` (LPP-004 Stage 8),
     * neither of which fits siteOptions()'s fixed allowlist above.
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
     * widened read this class makes (see class docblock's "never a bare
     * SELECT *" rule). Needed for classic widget instances specifically:
     * a real WordPress site can have 70+ distinct `widget_*` option names
     * once every plugin that ever registered a widget is counted, so
     * there is no fixed allowlist to write the way siteOptions() does —
     * $prefix is always a hardcoded literal from calling code (e.g.
     * `'widget_'`), never user input, and the LIKE pattern's value is
     * still parameterized, so this stays just as safe as the narrower
     * queries elsewhere in this class while covering data siteOptions()'s
     * own approach structurally cannot. $prefix's own characters are not
     * escaped against LIKE's `%`/`_` wildcards — deliberately: every real
     * caller passes a fixed literal like `'widget_'` with no wildcard
     * meaning of its own, so escaping would add complexity (and a
     * database-portable `ESCAPE` clause is more fragile than it looks —
     * see this method's own regression test) for no real protection.
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
                // post_title/post_excerpt are plain-text fields rendered
                // via esc_html() (unlike post_content, which is stored as
                // real HTML and correctly interpreted by HtmlSanitizer's
                // DOM parser regardless of any entity-encoding within it)
                // — see decodeEntities()'s docblock.
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

        return array_map(static fn (array $row): string => self::decodeEntities((string) $row['name']), $rows);
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
                // comment_author/comment_content are both rendered via
                // esc_html() (format_comment_content(), include/
                // helpers.php) — see decodeEntities()'s docblock.
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

    /**
     * NextGEN Gallery's own `ngg_gallery` table, one row per gallery —
     * confirmed against a real production database (this ticket's own
     * established practice): `path` is the gallery's actual on-disk
     * folder name under `wp-content/gallery/` (e.g.
     * `/wp-content/gallery/some-gallery-name/`), *not* necessarily the
     * same as `slug` — the two can differ when a gallery was renamed
     * after creation, so importNextGenGalleries() resolves a picture's
     * file location from `path`, never `slug`.
     *
     * Unlike siteOptions()'s fixed-allowlist tables, `ngg_gallery` is
     * itself a plugin-specific table that won't exist at all on a
     * source site that never ran NextGEN Gallery — caught the same way
     * sdmDownloadStats() catches a missing `sdm_downloads` table.
     *
     * Plain-text fields here (`name`, `title`, `galdesc`) are
     * deliberately not run through decodeEntities() — NextGEN Gallery
     * has its own admin save routine, entirely separate from
     * `wp_insert_post()`/`wp_insert_term()`, so decodeEntities()'s own
     * rationale (WordPress's *own* KSES filtering double-encoding on
     * the way out) doesn't clearly apply, and no real NextGEN data
     * examined so far contains an HTML entity to confirm either way.
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
     * NextGEN Gallery's own `ngg_pictures` table, scoped to one gallery
     * — see nextGenGalleries()'s own docblock for why plain-text fields
     * here aren't run through decodeEntities() either.
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
     * A single aggregate query, never a row dump — matches the class
     * docblock's "never a bare SELECT *" rule even though this scans
     * every row in {prefix}posts, since only post_type and a count ever
     * leave the database.
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
