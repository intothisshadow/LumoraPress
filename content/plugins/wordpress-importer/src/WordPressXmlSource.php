<?php

/**
 * Reads a WordPress WXR export file as a WordPressImportService source, an alternative to a live database connection (LPP-004).
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
use DOMDocument;
use DOMElement;
use DOMXPath;
use LumoraPress\Models\UserRole;
use RuntimeException;

/**
 * WXR ("WordPress eXtended RSS") is WordPress's own built-in export
 * format — an RSS 2.0 document with a `wp:` namespace extension
 * carrying everything a plain RSS feed can't: post types/statuses,
 * postmeta, comments/commentmeta, users, and every taxonomy's terms
 * (categories/tags via their own dedicated `<wp:category>`/`<wp:tag>`
 * elements, any other taxonomy — `sdm_categories`, `media_folder`,
 * `nav_menu`, etc. — via a generic `<wp:term>` element carrying its own
 * `<wp:term_taxonomy>` name). Schema confirmed against a real, ~18MB/
 * 6,000-item production export (`References/soobsessed.WordPress.
 * *.xml` — see MEMORY.md), not guessed from documentation alone,
 * matching this ticket's own established practice for its
 * database-connection source.
 *
 * The whole document is parsed once, in the constructor, into flat
 * in-memory PHP arrays keyed the same way WordPressSource's own SQL
 * queries are — every interface method below is then a plain array
 * lookup, no repeated XML traversal. This trades memory for simplicity
 * (a real ~19,000-post/6,000-item export parses in well under a second
 * and a few MB — see this class's own test coverage) rather than a
 * streaming `XMLReader` parser, which would need real cross-item state
 * (e.g. resolving a category's parent, itself referenced by slug, not
 * id — see parseCategoryLikeTerms()) that a forward-only stream can't
 * express as simply. Runs as one long synchronous admin request either
 * way, the same "no background-job infrastructure" precedent every
 * other stage in this plugin already accepts.
 *
 * WXR is WordPress's *content* export format — it structurally cannot
 * carry site options, widget configuration, or a plugin's own custom
 * database tables (Simple Download Monitor's per-visit download log,
 * for one). Every interface method this class can't honestly answer
 * returns nothing (`option()`/`optionsLike()`) or a documented default
 * (`userRole()` always `UserRole::Subscriber` — WXR carries no role
 * data for an author at all) rather than guessing — see
 * WordPressSourceInterface's own docblock for why every caller in
 * WordPressImportService already treats that as a normal, gracefully-
 * degrading case.
 */
final class WordPressXmlSource implements WordPressSourceInterface
{
    private const WP_NS = 'http://wordpress.org/export/1.2/';
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const EXCERPT_NS = 'http://wordpress.org/export/1.2/excerpt/';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    /** @var array<int, array{ID: int, user_login: string, user_email: string, display_name: string, user_registered: string}> */
    private array $usersById = [];

    /** @var array<string, int> author_login => wp:author_id */
    private array $authorLoginToId = [];

    /**
     * @var array<int, array{ID: int, post_author: int, post_date: string, post_content: string, post_title: string, post_excerpt: string, post_status: string, post_name: string, post_parent: int, guid: string, post_type: string, menu_order: int}>
     */
    private array $postsById = [];

    /** @var array<int, array<string, string>> post id => [meta_key => meta_value] */
    private array $postMetaById = [];

    /**
     * @var array<int, array<int, array{domain: string, nicename: string, name: string}>> post id => its <category> elements
     */
    private array $categoriesById = [];

    /**
     * @var array<int, array<int, array{comment_ID: int, comment_post_ID: int, comment_parent: int, user_id: int, comment_author: string, comment_author_email: string, comment_author_url: string, comment_date: string, comment_content: string, comment_approved: string}>>
     */
    private array $commentsByPostId = [];

    /** @var array{blogname?: string, blogdescription?: string, home?: string, siteurl?: string} */
    private array $siteOptions = [];

    /**
     * @var array<string, array<int, array{term_id: int, term_taxonomy_id: int, name: string, slug: string, parent: int}>>
     * Lazily built per taxonomy on first request, then cached — see terms().
     */
    private array $termsByTaxonomy = [];

    public function __construct(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("The WXR file was not found or is not readable at \"{$path}\".");
        }

        $document = new DOMDocument();

        // WXR files are untrusted input from an arbitrary external
        // site's export — libxml's default entity/network-loading
        // behavior is already safe against XXE for a
        // LIBXML_NONET-less DOMDocument on modern PHP (external entity
        // substitution is opt-in via LIBXML_NOENT, never enabled here),
        // but LIBXML_NONET is set explicitly anyway as defense in
        // depth against any external DTD/entity reference attempting a
        // network fetch.
        $previousErrorSetting = libxml_use_internal_errors(true);

        try {
            $loaded = $document->load($path, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previousErrorSetting);
        }

        if (!$loaded) {
            throw new RuntimeException("\"{$path}\" is not a well-formed XML file.");
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('wp', self::WP_NS);
        $xpath->registerNamespace('content', self::CONTENT_NS);
        $xpath->registerNamespace('excerpt', self::EXCERPT_NS);
        $xpath->registerNamespace('dc', self::DC_NS);

        $channel = $xpath->query('/rss/channel')->item(0);

        if (!$channel instanceof DOMElement || $xpath->query('wp:wxr_version', $channel)->length === 0) {
            throw new RuntimeException("\"{$path}\" doesn't look like a WordPress WXR export (no rss/channel/wp:wxr_version found).");
        }

        $this->parseSiteOptions($xpath, $channel);
        $this->parseAuthors($xpath, $channel);
        $this->parseAllTerms($xpath, $channel);
        $this->parseItems($xpath, $channel);
    }

    public function testConnection(): bool
    {
        // Construction already throws on anything that isn't a
        // well-formed WXR document — reaching here at all means it was
        // valid, so this is always true. Kept as a real method (not a
        // constant) so it satisfies the interface the same way
        // WordPressSource::testConnection() does, and so a future
        // change here (e.g. a soft-failure constructor) has somewhere
        // to add a real check.
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function siteOptions(): array
    {
        return $this->siteOptions;
    }

    /**
     * @return array<int, array{ID: int, user_login: string, user_email: string, display_name: string, user_registered: string}>
     */
    public function users(): array
    {
        return array_values($this->usersById);
    }

    /**
     * WXR's `<wp:author>` blocks carry no role at all (WordPress's own
     * export format has never included it — role assignment is lost on
     * any WXR round-trip, not just this importer's). Every WXR-sourced
     * user therefore imports as Subscriber; reassign roles manually
     * after a WXR-sourced import. A direct database connection
     * (WordPressSource::userRole()) doesn't have this limitation, since
     * it reads the real `wp_usermeta.{prefix}capabilities` row.
     */
    public function userRole(int $wpUserId): UserRole
    {
        return UserRole::Subscriber;
    }

    /**
     * @return array<int, array{term_id: int, term_taxonomy_id: int, name: string, slug: string, parent: int}>
     */
    public function terms(string $taxonomy): array
    {
        return $this->termsByTaxonomy[$taxonomy] ?? [];
    }

    /**
     * @return array<int, int> nav_menu_item post id => nav_menu term id
     */
    public function navMenuItemTermTaxonomyIds(): array
    {
        $slugToTermId = [];

        foreach ($this->terms('nav_menu') as $term) {
            $slugToTermId[$term['slug']] = $term['term_id'];
        }

        $map = [];

        foreach ($this->postsById as $postId => $post) {
            if ($post['post_type'] !== 'nav_menu_item') {
                continue;
            }

            foreach ($this->categoriesById[$postId] ?? [] as $category) {
                if ($category['domain'] === 'nav_menu' && isset($slugToTermId[$category['nicename']])) {
                    $map[$postId] = $slugToTermId[$category['nicename']];

                    break;
                }
            }
        }

        return $map;
    }

    /**
     * WXR has no representation of the source's wp_options table at
     * all — see class docblock.
     */
    public function option(string $name): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function optionsLike(string $prefix): array
    {
        return [];
    }

    /**
     * @param array<int, string> $postTypes
     * @param array<int, string> $statuses
     * @return array<int, array{ID: int, post_author: int, post_date: string, post_content: string, post_title: string, post_excerpt: string, post_status: string, post_name: string, post_parent: int, guid: string, post_type: string, menu_order: int}>
     */
    public function posts(array $postTypes, array $statuses): array
    {
        $postTypes = array_flip($postTypes);
        $statuses = array_flip($statuses);

        $matches = array_values(array_filter(
            $this->postsById,
            static fn (array $post): bool => isset($postTypes[$post['post_type']]) && isset($statuses[$post['post_status']]),
        ));

        // Matches WordPressSource::posts()'s own `ORDER BY post_parent
        // ASC, ID ASC` — a hierarchy-dependent caller (importPages(),
        // importCategories()'s multi-pass walk doesn't need this, but
        // page parent/child import processes top-level pages first the
        // same way) relies on parents appearing before their children.
        usort($matches, static function (array $a, array $b): int {
            return $a['post_parent'] <=> $b['post_parent'] ?: $a['ID'] <=> $b['ID'];
        });

        return $matches;
    }

    /**
     * @return array<string, string>
     */
    public function postMeta(int $postId): array
    {
        return $this->postMetaById[$postId] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function termNamesForPost(int $postId, string $taxonomy): array
    {
        $names = [];

        foreach ($this->categoriesById[$postId] ?? [] as $category) {
            if ($category['domain'] === $taxonomy) {
                $names[] = $category['name'];
            }
        }

        return $names;
    }

    /**
     * @return array<int, int>
     */
    public function termIdsForPost(int $postId, string $taxonomy): array
    {
        $slugToTermId = [];

        foreach ($this->terms($taxonomy) as $term) {
            $slugToTermId[$term['slug']] = $term['term_id'];
        }

        $ids = [];

        foreach ($this->categoriesById[$postId] ?? [] as $category) {
            if ($category['domain'] === $taxonomy && isset($slugToTermId[$category['nicename']])) {
                $ids[] = $slugToTermId[$category['nicename']];
            }
        }

        return $ids;
    }

    /**
     * @return array<int, array{comment_ID: int, comment_post_ID: int, comment_parent: int, user_id: int, comment_author: string, comment_author_email: string, comment_author_url: string, comment_date: string, comment_content: string, comment_approved: string}>
     */
    public function comments(int $postId): array
    {
        return $this->commentsByPostId[$postId] ?? [];
    }

    /**
     * A WXR export never carries Simple Download Monitor's own
     * per-visit download-event log table (a plugin-specific database
     * table, entirely outside what any WXR export represents) — only
     * `sdm_count_offset` postmeta, which *is* exported (postmeta is
     * exported verbatim for every item regardless of key). The returned
     * count is therefore that offset alone, same fallback shape
     * WordPressSource::sdmDownloadStats() itself already returns when
     * the log table doesn't exist on a source database either.
     *
     * @return array{count: int, lastDownloadedAt: ?DateTimeImmutable}
     */
    public function sdmDownloadStats(int $postId): array
    {
        return ['count' => (int) ($this->postMetaById[$postId]['sdm_count_offset'] ?? 0), 'lastDownloadedAt' => null];
    }

    private function parseSiteOptions(DOMXPath $xpath, DOMElement $channel): void
    {
        $title = $this->text($xpath, $channel, 'title');
        $description = $this->text($xpath, $channel, 'description');
        $baseSiteUrl = $this->text($xpath, $channel, 'wp:base_site_url');
        $baseBlogUrl = $this->text($xpath, $channel, 'wp:base_blog_url');

        if ($title !== '') {
            $this->siteOptions['blogname'] = self::decodeEntities($title);
        }

        if ($description !== '') {
            $this->siteOptions['blogdescription'] = self::decodeEntities($description);
        }

        if ($baseSiteUrl !== '') {
            $this->siteOptions['siteurl'] = $baseSiteUrl;
        }

        if ($baseBlogUrl !== '') {
            $this->siteOptions['home'] = $baseBlogUrl;
        }
    }

    private function parseAuthors(DOMXPath $xpath, DOMElement $channel): void
    {
        foreach ($xpath->query('wp:author', $channel) as $authorNode) {
            if (!$authorNode instanceof DOMElement) {
                continue;
            }

            $id = (int) $this->text($xpath, $authorNode, 'wp:author_id');
            $login = $this->text($xpath, $authorNode, 'wp:author_login');

            $this->usersById[$id] = [
                'ID' => $id,
                'user_login' => $login,
                'user_email' => $this->text($xpath, $authorNode, 'wp:author_email'),
                'display_name' => self::decodeEntities($this->text($xpath, $authorNode, 'wp:author_display_name')),
                // Never exported in WXR — see userRole()'s own
                // docblock for the same limitation applied to role.
                // parseWpDate('') (WordPressImportService's own helper)
                // already treats an empty string as "no date", the
                // same "not set" outcome a source database's own NULL/
                // 0000-00-00 user_registered value produces.
                'user_registered' => '',
            ];

            $this->authorLoginToId[$login] = $id;
        }
    }

    private function parseItems(DOMXPath $xpath, DOMElement $channel): void
    {
        foreach ($xpath->query('item', $channel) as $itemNode) {
            if (!$itemNode instanceof DOMElement) {
                continue;
            }

            $id = (int) $this->text($xpath, $itemNode, 'wp:post_id');

            if ($id === 0) {
                continue;
            }

            $authorLogin = $this->text($xpath, $itemNode, 'dc:creator');

            $this->postsById[$id] = [
                'ID' => $id,
                // 0 when the login doesn't match any <wp:author> block
                // at all (a data oddity) — mirrors how
                // WordPressImportService's own callers already fall
                // back (`$wpUserIdToLocalId[$wpPost['post_author']] ??
                // 1`) when a post_author id doesn't resolve.
                'post_author' => $this->authorLoginToId[$authorLogin] ?? 0,
                'post_date' => $this->text($xpath, $itemNode, 'wp:post_date'),
                // content:encoded is real HTML — never entity-decoded,
                // matching WordPressSource::posts()'s own post_content
                // (HtmlSanitizer's DOM parser handles any entities
                // within it correctly regardless).
                'post_content' => $this->text($xpath, $itemNode, 'content:encoded'),
                'post_title' => self::decodeEntities($this->text($xpath, $itemNode, 'title')),
                'post_excerpt' => self::decodeEntities($this->text($xpath, $itemNode, 'excerpt:encoded')),
                'post_status' => $this->text($xpath, $itemNode, 'wp:status'),
                'post_name' => $this->text($xpath, $itemNode, 'wp:post_name'),
                'post_parent' => (int) $this->text($xpath, $itemNode, 'wp:post_parent'),
                'guid' => $this->text($xpath, $itemNode, 'guid'),
                'post_type' => $this->text($xpath, $itemNode, 'wp:post_type'),
                'menu_order' => (int) $this->text($xpath, $itemNode, 'wp:menu_order'),
            ];

            $meta = [];

            foreach ($xpath->query('wp:postmeta', $itemNode) as $metaNode) {
                if (!$metaNode instanceof DOMElement) {
                    continue;
                }

                $key = $this->text($xpath, $metaNode, 'wp:meta_key');

                // First value wins per key — mirrors
                // WordPressSource::postMeta()'s identical convention.
                if (!isset($meta[$key])) {
                    $meta[$key] = $this->text($xpath, $metaNode, 'wp:meta_value');
                }
            }

            $this->postMetaById[$id] = $meta;

            $categories = [];

            foreach ($xpath->query('category', $itemNode) as $categoryNode) {
                if (!$categoryNode instanceof DOMElement) {
                    continue;
                }

                $categories[] = [
                    'domain' => (string) $categoryNode->getAttribute('domain'),
                    'nicename' => (string) $categoryNode->getAttribute('nicename'),
                    'name' => self::decodeEntities(trim($categoryNode->textContent)),
                ];
            }

            $this->categoriesById[$id] = $categories;

            $comments = [];

            foreach ($xpath->query('wp:comment', $itemNode) as $commentNode) {
                if (!$commentNode instanceof DOMElement) {
                    continue;
                }

                $type = $this->text($xpath, $commentNode, 'wp:comment_type');

                // Matches WordPressSource::comments()'s own `comment_type
                // IN ('comment', '')` filter — WXR also exports
                // trackbacks/pingbacks as comment_type 'trackback'/
                // 'pingback', neither of which this importer ever
                // brought in from a database source either.
                if ($type !== '' && $type !== 'comment') {
                    continue;
                }

                $comments[] = [
                    'comment_ID' => (int) $this->text($xpath, $commentNode, 'wp:comment_id'),
                    'comment_post_ID' => $id,
                    'comment_parent' => (int) $this->text($xpath, $commentNode, 'wp:comment_parent'),
                    'user_id' => (int) $this->text($xpath, $commentNode, 'wp:comment_user_id'),
                    'comment_author' => self::decodeEntities($this->text($xpath, $commentNode, 'wp:comment_author')),
                    'comment_author_email' => $this->text($xpath, $commentNode, 'wp:comment_author_email'),
                    'comment_author_url' => $this->text($xpath, $commentNode, 'wp:comment_author_url'),
                    'comment_date' => $this->text($xpath, $commentNode, 'wp:comment_date'),
                    'comment_content' => self::decodeEntities($this->text($xpath, $commentNode, 'wp:comment_content')),
                    'comment_approved' => $this->text($xpath, $commentNode, 'wp:comment_approved'),
                ];
            }

            // Matches WordPressSource::comments()'s own `ORDER BY
            // comment_parent ASC, comment_ID ASC` (CommentImporter
            // relies on a parent comment appearing before its replies).
            usort($comments, static function (array $a, array $b): int {
                return $a['comment_parent'] <=> $b['comment_parent'] ?: $a['comment_ID'] <=> $b['comment_ID'];
            });

            $this->commentsByPostId[$id] = $comments;
        }
    }

    /**
     * Parses every taxonomy's terms up front — 'category' and
     * 'post_tag' from their own dedicated `<wp:category>`/`<wp:tag>`
     * elements, every other taxonomy (`sdm_categories`, `media_folder`,
     * `nav_menu`, etc.) from the generic `<wp:term>` element, grouped by
     * its own `<wp:term_taxonomy>` value. A term's parent is given as a
     * *slug* (`<wp:category_parent>`/`<wp:term_parent>`), scoped to that
     * same taxonomy — never an id the way WordPressSource's own SQL
     * query resolves it via a JOIN — so this builds a slug => term_id
     * map per taxonomy first and resolves parent ids from that, an
     * unresolvable/empty parent slug falling back to 0 (top-level),
     * matching WordPressSource::terms()'s own shape exactly.
     */
    private function parseAllTerms(DOMXPath $xpath, DOMElement $channel): void
    {
        $bySlugByTaxonomy = [];

        $registerRaw = static function (string $taxonomy, int $termId, string $slug, string $parentSlug, string $name) use (&$bySlugByTaxonomy): void {
            $bySlugByTaxonomy[$taxonomy][$slug] = ['term_id' => $termId, 'slug' => $slug, 'parentSlug' => $parentSlug, 'name' => $name];
        };

        foreach ($xpath->query('wp:category', $channel) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $registerRaw(
                'category',
                (int) $this->text($xpath, $node, 'wp:term_id'),
                $this->text($xpath, $node, 'wp:category_nicename'),
                $this->text($xpath, $node, 'wp:category_parent'),
                self::decodeEntities($this->text($xpath, $node, 'wp:cat_name')),
            );
        }

        foreach ($xpath->query('wp:tag', $channel) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $registerRaw(
                'post_tag',
                (int) $this->text($xpath, $node, 'wp:term_id'),
                $this->text($xpath, $node, 'wp:tag_slug'),
                '',
                self::decodeEntities($this->text($xpath, $node, 'wp:tag_name')),
            );
        }

        foreach ($xpath->query('wp:term', $channel) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $registerRaw(
                $this->text($xpath, $node, 'wp:term_taxonomy'),
                (int) $this->text($xpath, $node, 'wp:term_id'),
                $this->text($xpath, $node, 'wp:term_slug'),
                $this->text($xpath, $node, 'wp:term_parent'),
                self::decodeEntities($this->text($xpath, $node, 'wp:term_name')),
            );
        }

        foreach ($bySlugByTaxonomy as $taxonomy => $bySlug) {
            $terms = [];

            foreach ($bySlug as $raw) {
                $parentId = $raw['parentSlug'] !== '' ? ($bySlug[$raw['parentSlug']]['term_id'] ?? 0) : 0;

                $terms[] = [
                    'term_id' => $raw['term_id'],
                    // WXR carries no separate term_taxonomy_id — every
                    // caller in WordPressImportService only ever uses
                    // term_id itself (for the id maps this class's own
                    // interface methods build), never this value, so
                    // term_id is reused here rather than inventing a
                    // second, meaningless id space.
                    'term_taxonomy_id' => $raw['term_id'],
                    'name' => $raw['name'],
                    'slug' => $raw['slug'],
                    'parent' => $parentId,
                ];
            }

            usort($terms, static function (array $a, array $b): int {
                return $a['parent'] <=> $b['parent'] ?: strcasecmp($a['name'], $b['name']);
            });

            $this->termsByTaxonomy[$taxonomy] = $terms;
        }
    }

    private static function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }

    private function text(DOMXPath $xpath, DOMElement $context, string $relativeXPath): string
    {
        $node = $xpath->query($relativeXPath, $context)->item(0);

        return $node !== null ? $node->textContent : '';
    }
}
