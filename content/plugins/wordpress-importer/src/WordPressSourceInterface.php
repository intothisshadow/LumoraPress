<?php

/**
 * The read-only source contract WordPressImportService drives, implemented by both a direct database connection and a WXR export file.
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
 * Lets `WordPressImportService` drive a live database (`WordPressSource`)
 * or a WXR export file (`WordPressXmlSource`) identically.
 *
 * A WXR export can't carry site options, widgets, or a plugin's custom
 * tables — implementations should return an honest empty/default result
 * for anything their source can't supply rather than guessing, since
 * every caller already treats "nothing found" as a normal case.
 */
interface WordPressSourceInterface
{
    /**
     * True only if the source is actually usable — a reachable database
     * with a `posts` table, or a parseable WXR document with a `<channel>`.
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
     * NextGEN Gallery's own `ngg_gallery` table — a flat list, no hierarchy.
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
     * NextGEN Gallery's own `ngg_album` table, grouping galleries together.
     *
     * @return array<int, array{id: int, name: string, slug: string, galleryIds: array<int, int>}>
     */
    public function nextGenAlbums(): array;

    /**
     * Every `_wp_old_slug` ever recorded for a post, oldest first — unlike
     * other meta keys, a post can carry more than one of these.
     *
     * @return array<int, string>
     */
    public function oldSlugs(int $postId): array;

    /**
     * Every distinct `post_type` present in the source with its row count,
     * including unsupported types — used only for the compatibility report.
     *
     * @return array<string, int> post_type => count
     */
    public function postTypeCounts(): array;
}
