<?php

/**
 * Renders the `[lumora_gallery_album]`/`[lumora_gallery_newest]` shortcodes against a separately-installed Lumora Gallery site.
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
     * $injectedQuery lets tests exercise rendering against a
     * SQLite-fixture-backed `GalleryQueryService` without a real MySQL
     * connection or persisted plugin settings — mirrors
     * `DownloadsShortcode`'s identical constructor-injection shape. The
     * plugin's own bootstrap constructs this with no second argument, so
     * `query()` lazily connects via `$settings` on first actual use.
     */
    public function __construct(
        private readonly GallerySettingsService $settings,
        private readonly ?GalleryQueryService $injectedQuery = null,
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
     * @param array<string, string> $attributes
     */
    private function renderAlbum(array $attributes): string
    {
        $query = $this->query();

        if ($query === null) {
            return '';
        }

        $albumIds = $this->parseIntList($attributes['album_id'] ?? '');
        $folder = trim($attributes['folder'] ?? '');

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

            return $this->renderGallery($images, $album);
        }

        if (count($albumIds) > 1) {
            $images = ($attributes['count'] ?? '') !== ''
                ? $query->newestInAlbums($albumIds, (int) $attributes['count'])
                : $query->imagesForAlbums($albumIds);

            if ($images === []) {
                return '';
            }

            return $this->renderGallery($images, null);
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

        return $this->renderGallery($images, $album);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderNewest(array $attributes): string
    {
        $query = $this->query();

        if ($query === null) {
            return '';
        }

        $count = ($attributes['count'] ?? '') !== '' ? (int) $attributes['count'] : 10;
        $images = $query->newestAcrossGallery($count);

        if ($images === []) {
            return '';
        }

        // No single album to link to — every image here can belong to a
        // different one — so the "View" link below points at the
        // Gallery site's own base URL instead of one album's page.
        return $this->renderGallery($images, null);
    }

    /**
     * @param array<int, array{id: int, filename: string, title: string, width: int, height: int, albumId?: int, albumFolder?: string}> $images
     * @param array{id: int, folder: string, title: string}|null $album
     */
    private function renderGallery(array $images, ?array $album): string
    {
        MediaViewer::markUsed();
        $baseUrl = rtrim($this->settings->settings()['base_url'], '/');

        $html = '<div class="lp-gallery-shortcode">';

        if ($album !== null) {
            $html .= '<h3 class="lp-gallery-shortcode__title">' . esc_html($album['title'] !== '' ? $album['title'] : $album['folder']) . '</h3>';
        }

        $html .= '<ul class="lp-gallery-shortcode__items">';

        foreach ($images as $image) {
            $folder = $album['folder'] ?? (string) ($image['albumFolder'] ?? '');
            $html .= '<li class="lp-gallery-shortcode__item">' . $this->renderThumbnail($image, $folder, $baseUrl) . '</li>';
        }

        $html .= '</ul>';

        $viewUrl = $album !== null
            ? $baseUrl . '/album.php?album=' . $album['id']
            : $baseUrl . '/';

        $viewLabel = $album !== null ? 'View album' : 'View gallery';
        $html .= '<a class="lp-gallery-shortcode__view-link" href="' . esc_url($viewUrl) . '">' . esc_html($viewLabel) . ' &rarr;</a>';

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

    private function query(): ?GalleryQueryService
    {
        if ($this->injectedQuery !== null) {
            return $this->injectedQuery;
        }

        $database = $this->settings->connect();

        return $database !== null ? new GalleryQueryService($database, $this->settings->settings()['table_prefix']) : null;
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
