<?php

/**
 * Reads a WordPress WXR export file as a WordPressImportService source, an alternative to a live database connection.
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
 * WXR ("WordPress eXtended RSS") is WordPress's own export format — RSS 2.0 with a `wp:`
 * namespace carrying post types/statuses, postmeta, comments, users, and taxonomy terms.
 *
 * The whole document is parsed once in the constructor into flat in-memory arrays keyed like
 * WordPressSource's SQL queries, so every interface method is a plain array lookup rather
 * than repeated XML traversal — trading memory for simplicity over a streaming parser.
 *
 * WXR can't structurally carry site options, widgets, or a plugin's custom tables, so methods
 * that can't honestly answer return nothing or a documented default rather than guess.
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

    /** @var array<int, array<int, string>> post id => every _wp_old_slug value, oldest first (document order) */
    private array $oldSlugsById = [];

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

        // WXR files are untrusted input. LIBXML_NOENT is never enabled
        // (so XXE isn't possible), but LIBXML_NONET is set explicitly
        // anyway as defense in depth against a network-fetching DTD.
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
        // well-formed WXR document, so reaching here always means valid.
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
     * WXR's `<wp:author>` blocks carry no role at all, so every WXR-sourced
     * user imports as Subscriber; reassign roles manually afterward. A
     * direct database connection doesn't have this limitation.
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
     * WXR has no representation of the source's wp_options table.
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

        // Matches WordPressSource::posts()'s ordering — page import
        // relies on parents appearing before their children.
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
     * A WXR export never carries Simple Download Monitor's own log table,
     * only the exported `sdm_count_offset` postmeta — same fallback shape
     * WordPressSource::sdmDownloadStats() returns when that table is
     * missing.
     *
     * @return array{count: int, lastDownloadedAt: ?DateTimeImmutable}
     */
    public function sdmDownloadStats(int $postId): array
    {
        return ['count' => (int) ($this->postMetaById[$postId]['sdm_count_offset'] ?? 0), 'lastDownloadedAt' => null];
    }

    /**
     * WXR has no representation of NextGEN Gallery's plugin tables.
     *
     * @return array<int, array{gid: int, name: string, slug: string, path: string, title: string, galdesc: string, author: int}>
     */
    public function nextGenGalleries(): array
    {
        return [];
    }

    /**
     * @return array<int, array{pid: int, filename: string, description: string, alttext: string, imagedate: string, exclude: int}>
     */
    public function nextGenPictures(int $galleryId): array
    {
        return [];
    }

    /**
     * Same reasoning as nextGenGalleries().
     *
     * @return array<int, array{id: int, name: string, slug: string, galleryIds: array<int, int>}>
     */
    public function nextGenAlbums(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function oldSlugs(int $postId): array
    {
        return $this->oldSlugsById[$postId] ?? [];
    }

    /**
     * @return array<string, int>
     */
    public function postTypeCounts(): array
    {
        return array_count_values(array_column($this->postsById, 'post_type'));
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
                // Never exported in WXR; parseWpDate('') already treats
                // an empty string as "no date".
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
                // 0 when the login doesn't match any <wp:author> block —
                // mirrors the post_author fallback used elsewhere.
                'post_author' => $this->authorLoginToId[$authorLogin] ?? 0,
                'post_date' => $this->text($xpath, $itemNode, 'wp:post_date'),
                // content:encoded is real HTML — never entity-decoded,
                // matching WordPressSource::posts()'s post_content.
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
                $value = $this->text($xpath, $metaNode, 'wp:meta_value');

                // First value wins per key — mirrors WordPressSource::postMeta().
                if (!isset($meta[$key])) {
                    $meta[$key] = $value;
                }

                // _wp_old_slug repeats (one row per rename), so it's
                // collected separately for oldSlugs() to return in full.
                if ($key === '_wp_old_slug') {
                    $this->oldSlugsById[$id][] = $value;
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

                // Matches WordPressSource::comments()'s filter — WXR also
                // exports trackbacks/pingbacks, neither imported here.
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

            // Matches WordPressSource::comments()'s ordering — a parent
            // comment must appear before its replies.
            usort($comments, static function (array $a, array $b): int {
                return $a['comment_parent'] <=> $b['comment_parent'] ?: $a['comment_ID'] <=> $b['comment_ID'];
            });

            $this->commentsByPostId[$id] = $comments;
        }
    }

    /**
     * Parses every taxonomy's terms up front — 'category'/'post_tag' from
     * their dedicated elements, everything else from the generic
     * `<wp:term>` element. A term's parent is given as a *slug*, not an
     * id, so this builds a slug => term_id map per taxonomy first and
     * resolves parent ids from it, falling back to 0 (top-level) when
     * unresolvable.
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
                    // WXR carries no separate term_taxonomy_id; term_id
                    // is reused rather than inventing a meaningless one.
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
