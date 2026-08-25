<?php

/**
 * The read-only source contract WordPressImportService drives, implemented by both a direct database connection and a WXR export file (LPP-004).
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
use LumoraPress\Models\UserRole;

/**
 * `WordPressSource` (a second, independent database connection to the
 * source site's own MySQL/MariaDB server) was this plugin's only source
 * until now. `WordPressXmlSource` (a WXR `.xml` export file, read
 * locally — never fetched remotely) implements this same contract so
 * `WordPressImportService` never has to know or care which one it's
 * actually driving; every stage (`importUsers()`, `importPosts()`,
 * `importMedia()`, etc.) is written once against this interface.
 *
 * A WXR export is WordPress's own *content* export format — it carries
 * posts/pages/attachments/comments/users/terms, but never site options,
 * widget configuration, or a plugin's own custom database tables (e.g.
 * Simple Download Monitor's per-visit download log). Implementations
 * are expected to return an honestly empty/default result for anything
 * their underlying source structurally cannot supply (`option()`/
 * `optionsLike()` returning nothing, `userRole()` falling back to
 * `UserRole::Subscriber`) rather than guessing — every caller in
 * `WordPressImportService` already treats "nothing found" as a normal,
 * gracefully-degrading case, the same way a real WordPress site with no
 * widgets or a plugin-free install already exercises today.
 */
interface WordPressSourceInterface
{
    /**
     * True only if the source is actually usable — a live, reachable
     * database connection with the expected `posts` table for
     * `WordPressSource`, or a well-formed, parseable WXR document with
     * a `<channel>` for `WordPressXmlSource`.
     */
    public function testConnection(): bool;

    /**
     * @return array<string, string>
     */
    public function siteOptions(): array;

    /**
     * @return array<int, array{ID: int, user_login: string, user_email: string, display_name: string, user_registered: string}>
     */
    public function users(): array;

    public function userRole(int $wpUserId): UserRole;

    /**
     * @return array<int, array{term_id: int, term_taxonomy_id: int, name: string, slug: string, parent: int}>
     */
    public function terms(string $taxonomy): array;

    /**
     * @return array<int, int> nav_menu_item post id => nav_menu term id
     */
    public function navMenuItemTermTaxonomyIds(): array;

    public function option(string $name): ?string;

    /**
     * @return array<string, string>
     */
    public function optionsLike(string $prefix): array;

    /**
     * @param array<int, string> $postTypes
     * @param array<int, string> $statuses
     * @return array<int, array{ID: int, post_author: int, post_date: string, post_content: string, post_title: string, post_excerpt: string, post_status: string, post_name: string, post_parent: int, guid: string, post_type: string, menu_order: int}>
     */
    public function posts(array $postTypes, array $statuses): array;

    /**
     * @return array<string, string>
     */
    public function postMeta(int $postId): array;

    /**
     * @return array<int, string>
     */
    public function termNamesForPost(int $postId, string $taxonomy): array;

    /**
     * @return array<int, int>
     */
    public function termIdsForPost(int $postId, string $taxonomy): array;

    /**
     * @return array<int, array{comment_ID: int, comment_post_ID: int, comment_parent: int, user_id: int, comment_author: string, comment_author_email: string, comment_author_url: string, comment_date: string, comment_content: string, comment_approved: string}>
     */
    public function comments(int $postId): array;

    /**
     * @return array{count: int, lastDownloadedAt: ?DateTimeImmutable}
     */
    public function sdmDownloadStats(int $postId): array;

    /**
     * NextGEN Gallery's own `ngg_gallery` table — a flat list, no
     * hierarchy of its own (unlike `sdm_categories`/`media_folder`).
     *
     * @return array<int, array{gid: int, name: string, slug: string, path: string, title: string, galdesc: string, author: int}>
     */
    public function nextGenGalleries(): array;

    /**
     * NextGEN Gallery's own `ngg_pictures` table, scoped to one gallery.
     *
     * @return array<int, array{pid: int, filename: string, description: string, alttext: string, imagedate: string, exclude: int}>
     */
    public function nextGenPictures(int $galleryId): array;

    /**
     * Every `_wp_old_slug` value ever recorded for one post/page, oldest
     * first — WordPress appends a new row each time a published post's
     * slug changes, so unlike every other meta key this importer reads
     * (postMeta()'s own single-value convenience), a post/page can
     * genuinely carry more than one.
     *
     * @return array<int, string>
     */
    public function oldSlugs(int $postId): array;

    /**
     * Every distinct `post_type` value present in the source, with its
     * total row count — including a type this importer never explicitly
     * queries (a custom post type from an unsupported plugin). Used only
     * for Compatibility's "report unsupported plugins and custom post
     * types" diagnostic; never drives what actually gets imported.
     *
     * @return array<string, int> post_type => count
     */
    public function postTypeCounts(): array;
}
