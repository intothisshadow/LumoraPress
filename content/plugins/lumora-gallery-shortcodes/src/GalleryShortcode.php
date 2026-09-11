<?php

/**
 * Renders the `[lumora_gallery_album]`/`[lumora_gallery_newest]` shortcodes against one or more separately-installed Lumora Gallery sites.
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

use LumoraPress\Core\Theme\MediaViewer;

/**
 * Registered on the `content_html` filter, like every other shortcode in this codebase. No
 * role/capability gate — ordinary content-authoring permissions are the only access control.
 *
 * Every thumbnail links to the full-size image and opens it in Lumora Press's own PhotoSwipe
 * lightbox, using the same `data-pswp-*` convention ContentRenderer establishes for local
 * media — the Gallery `images` table's own width/height columns supply the dimensions.
 */
final class GalleryShortcode
{
    private const PATTERN_ALBUM = '/\[lumora_gallery_album([^\]]*)\]/i';
    private const PATTERN_NEWEST = '/\[lumora_gallery_newest([^\]]*)\]/i';

    /**
     * @var array<string, GalleryQueryService|null>
     */
    private array $queryCache = [];

    /**
     * $injectedQuery lets tests exercise rendering against a
     * SQLite-fixture-backed `GalleryQueryService` without a real MySQL
     * connection or persisted plugin settings — mirrors
     * `DownloadsShortcode`'s identical constructor-injection shape, used
     * as the query for every connection slug a test's content
     * references. `$injectedQueriesBySlug` lets a multi-connection test
     * inject a distinct `GalleryQueryService` per slug instead — checked
     * first, ahead of the single `$injectedQuery` fallback. The plugin's
     * own bootstrap constructs this with neither argument, so `query()`
     * lazily connects via `$settings` on first actual use of each slug.
     *
     * @param array<string, GalleryQueryService> $injectedQueriesBySlug
     */
    public function __construct(
        private readonly GallerySettingsService $settings,
        private readonly ?GalleryQueryService $injectedQuery = null,
        private readonly array $injectedQueriesBySlug = [],
    ) {
    }

    public function render(string $html): string
    {
        if (str_contains($html, '[lumora_gallery_album')) {
            $html = preg_replace_callback(
                self::PATTERN_ALBUM,
                fn (array $matches): string => $this->renderAlbum($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '[lumora_gallery_newest')) {
            $html = preg_replace_callback(
                self::PATTERN_NEWEST,
                fn (array $matches): string => $this->renderNewest($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        return $html;
    }

    /**
     * Four variants, checked in order:
     *
     * 1. `image_id` — one or more specific images (comma-separated).
     *    `album_id`/`folder` are optional here — an image id is already
     *    globally unique, so they're only used to pick which album the
     *    "View album" link below points at when given; without them,
     *    the first resolved image's own album is used instead. When
     *    `album_id` carries more than one id, only the first is used for
     *    this link — combining `image_id` with a multi-album `album_id`
     *    is not a meaningful pairing.
     * 2. `album_id` with more than one comma-separated id — every image
     *    (or, with `count`, the newest N) across all of those albums
     *    combined, newest-across-the-gallery-style with no single album
     *    to link to. `folder` is ignored in this variant, matching how
     *    `folder` is already secondary to `album_id` in the single-album
     *    variant below.
     * 3. `count` (with a single `album_id`/`folder`) — the newest N
     *    images in that one album.
     * 4. `album_id`/`folder` alone (single) — every image in that album.
     *
     * `no_album_info="1"` applies on top of any of these: the title and
     * "View album"/"View gallery" link are dropped, leaving just the bare
     * thumbnails, each still linking to its own full-size image.
     *
     * `gallery="{slug}"` picks which configured Gallery connection this
     * instance reads from — omitted, it resolves to whichever connection
     * is marked default, so a single-Gallery site never needs it. An
     * unknown slug renders nothing, the same as any other unresolvable
     * shortcode. Every `album_id`/`image_id` in one shortcode instance is
     * always resolved against that one connection — combining ids from
     * more than one Gallery installation in a single shortcode isn't
     * supported; use separate shortcode instances instead.
     *
     * @param array<string, string> $attributes
     */
    private function renderAlbum(array $attributes): string
    {
        $gallerySlug = $this->resolveGallerySlug($attributes);

        if ($gallerySlug === null) {
            return '';
        }

        $query = $this->query($gallerySlug);

        if ($query === null) {
            return '';
        }

        $albumIds = $this->parseIntList($attributes['album_id'] ?? '');
        $folder = trim($attributes['folder'] ?? '');
        $showAlbumInfo = ($attributes['no_album_info'] ?? '') !== '1';
        $baseUrl = rtrim($this->settings->connection($gallerySlug)['base_url'], '/');

        if (($attributes['image_id'] ?? '') !== '') {
            $imageIds = $this->parseIntList($attributes['image_id']);
            $images = $query->imagesByIds($imageIds);

            if ($images === []) {
                return '';
            }

            $album = ($albumIds !== [] || $folder !== '') ? $query->findAlbum($albumIds[0] ?? null, $folder !== '' ? $folder : null) : null;

            if ($album === null) {
                $album = $query->findAlbumForImage($images[0]['id']);
            }

            return $this->renderGallery($images, $album, $showAlbumInfo, $baseUrl);
        }

        if (count($albumIds) > 1) {
            $images = ($attributes['count'] ?? '') !== ''
                ? $query->newestInAlbums($albumIds, (int) $attributes['count'])
                : $query->imagesForAlbums($albumIds);

            if ($images === []) {
                return '';
            }

            return $this->renderGallery($images, null, $showAlbumInfo, $baseUrl);
        }

        $album = ($albumIds !== [] || $folder !== '') ? $query->findAlbum($albumIds[0] ?? null, $folder !== '' ? $folder : null) : null;

        if ($album === null) {
            return '';
        }

        if (($attributes['count'] ?? '') !== '') {
            $images = $query->newestInAlbum($album['id'], (int) $attributes['count']);
        } else {
            $images = $query->imagesForAlbum($album['id']);
        }

        if ($images === []) {
            return '';
        }

        return $this->renderGallery($images, $album, $showAlbumInfo, $baseUrl);
    }

    /**
     * `no_album_info="1"` drops the "View gallery" link below the
     * thumbnails, the same as it drops `[lumora_gallery_album]`'s own
     * title/"View album" link — see renderAlbum()'s docblock. `gallery`
     * picks the connection the same way renderAlbum() does.
     *
     * @param array<string, string> $attributes
     */
    private function renderNewest(array $attributes): string
    {
        $gallerySlug = $this->resolveGallerySlug($attributes);

        if ($gallerySlug === null) {
            return '';
        }

        $query = $this->query($gallerySlug);

        if ($query === null) {
            return '';
        }

        $count = ($attributes['count'] ?? '') !== '' ? (int) $attributes['count'] : 10;
        $images = $query->newestAcrossGallery($count);

        if ($images === []) {
            return '';
        }

        $baseUrl = rtrim($this->settings->connection($gallerySlug)['base_url'], '/');

        // No single album to link to — every image here can belong to a
        // different one — so the "View" link below points at the
        // Gallery site's own base URL instead of one album's page.
        return $this->renderGallery($images, null, ($attributes['no_album_info'] ?? '') !== '1', $baseUrl);
    }

    /**
     * @param array<int, array{id: int, filename: string, title: string, width: int, height: int, albumId?: int, albumFolder?: string}> $images
     * @param array{id: int, folder: string, title: string}|null $album
     */
    private function renderGallery(array $images, ?array $album, bool $showAlbumInfo, string $baseUrl): string
    {
        MediaViewer::markUsed();

        $html = '<div class="lp-gallery-shortcode">';

        if ($showAlbumInfo && $album !== null) {
            $html .= '<h3 class="lp-gallery-shortcode__title">' . esc_html($album['title'] !== '' ? $album['title'] : $album['folder']) . '</h3>';
        }

        $html .= '<ul class="lp-gallery-shortcode__items">';

        foreach ($images as $image) {
            $folder = $album['folder'] ?? (string) ($image['albumFolder'] ?? '');
            $html .= '<li class="lp-gallery-shortcode__item">' . $this->renderThumbnail($image, $folder, $baseUrl) . '</li>';
        }

        $html .= '</ul>';

        if ($showAlbumInfo) {
            $viewUrl = $album !== null
                ? $baseUrl . '/album.php?album=' . $album['id']
                : $baseUrl . '/';

            $viewLabel = $album !== null ? 'View album' : 'View gallery';
            $html .= '<a class="lp-gallery-shortcode__view-link" href="' . esc_url($viewUrl) . '">' . esc_html($viewLabel) . ' &rarr;</a>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array{id: int, filename: string, title: string, width: int, height: int} $image
     */
    private function renderThumbnail(array $image, string $folder, string $baseUrl): string
    {
        $fullUrl = $this->albumFileUrl($baseUrl, $folder, $image['filename']);
        $thumbUrl = $this->albumFileUrl($baseUrl, $folder, 'thumb_' . $image['filename']);
        $alt = $image['title'] !== '' ? $image['title'] : $image['filename'];

        $img = '<img class="lp-gallery-shortcode__thumb" src="' . esc_url($thumbUrl) . '" alt="' . esc_html($alt) . '" loading="lazy">';

        return '<a class="lp-gallery-shortcode__link" href="' . esc_url($fullUrl) . '"'
            . ($image['width'] > 0 ? ' data-pswp-width="' . $image['width'] . '"' : '')
            . ($image['height'] > 0 ? ' data-pswp-height="' . $image['height'] . '"' : '')
            . ' data-pswp-caption="' . esc_html($alt) . '">' . $img . '</a>';
    }

    /**
     * Percent-encodes each path segment individually (a folder can be
     * nested, e.g. "xena/season1") the same way Lumora Gallery's own
     * `lumora_album_url()` builds this exact URL shape.
     */
    private function albumFileUrl(string $baseUrl, string $folder, string $filename): string
    {
        $encodedFolder = implode('/', array_map('rawurlencode', explode('/', $folder)));

        return $baseUrl . '/albums/' . $encodedFolder . '/' . rawurlencode($filename);
    }

    /**
     * `gallery="{slug}"`, or the configured default connection when omitted. Null when neither
     * resolves to a real connection (an unknown slug, or no connection configured at all).
     *
     * @param array<string, string> $attributes
     */
    private function resolveGallerySlug(array $attributes): ?string
    {
        $slug = trim($attributes['gallery'] ?? '');

        if ($slug === '') {
            $slug = $this->settings->defaultConnection();
        }

        return $slug !== null && $slug !== '' && $this->settings->connection($slug) !== null ? $slug : null;
    }

    /**
     * One connection at a time — a page can embed shortcodes from more
     * than one Gallery installation, so each resolved slug's connection
     * is opened (and cached) independently within this one render() call.
     */
    private function query(string $slug): ?GalleryQueryService
    {
        if (array_key_exists($slug, $this->injectedQueriesBySlug)) {
            return $this->injectedQueriesBySlug[$slug];
        }

        if ($this->injectedQuery !== null) {
            return $this->injectedQuery;
        }

        if (array_key_exists($slug, $this->queryCache)) {
            return $this->queryCache[$slug];
        }

        $database = $this->settings->connect($slug);
        $connection = $this->settings->connection($slug);
        $query = $database !== null && $connection !== null ? new GalleryQueryService($database, $connection['table_prefix']) : null;
        $this->queryCache[$slug] = $query;

        return $query;
    }

    /**
     * @return array<int, int>
     */
    private function parseIntList(string $raw): array
    {
        $ids = [];

        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);

            if ($piece !== '' && ctype_digit($piece)) {
                $ids[] = (int) $piece;
            }
        }

        return $ids;
    }

    /**
     * @param string $rawAttributes e.g. ` album_id="12" count="5"`
     * @return array<string, string>
     */
    private function parseAttributes(string $rawAttributes): array
    {
        $attributes = [];

        if (preg_match_all('/([a-zA-Z_]+)="([^"]*)"/', $rawAttributes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2];
            }
        }

        return $attributes;
    }
}
