<?php

/**
 * Renders the `[lumora_folder_gallery]` shortcode as a row of thumbnails for every image in a Media folder.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

/**
 * Lets an author insert a whole Media folder into post/page content as
 * one row of thumbnails, instead of inserting each image
 * individually via the existing Insert Image flow. Registered on the
 * `content_html` filter (see include/bootstrap.php), the same hook
 * Font Awesome's `[icon]` shortcode and the Downloads plugin's
 * `[lumora_downloads]` both use — modeled directly on
 * `content/plugins/downloads/src/DownloadsShortcode.php`'s pattern
 * class, but as a real core service constructed with injected
 * dependencies rather than a plugin's own config.php-opening fallback
 * (a plugin's load-time code can't reach $kernel; core code always
 * can — see DEVELOPER-APIS.md's "Plugin main files load before Kernel
 * exists").
 *
 * Deliberately resolves the folder's contents at render time, not a
 * static list of image ids frozen at insert time — an image added to
 * or removed from the folder later is reflected automatically, and a
 * deleted folder/image just means fewer (or zero) thumbnails next
 * render rather than a stale, broken reference needing manual cleanup.
 */
final class FolderGalleryShortcode
{
    private const PATTERN = '/\[lumora_folder_gallery([^\]]*)\]/i';

    /**
     * The maximum number of images rendered per gallery — a folder
     * gallery is meant for a curated grouping of related images (see
     * this class's own docblock), not a substitute for browsing the
     * full Media Library, so an unbounded query per shortcode per
     * request is never appropriate.
     */
    private const MAX_IMAGES = 200;

    public function __construct(
        private readonly FolderService $folders,
        private readonly MediaService $media,
        private readonly ThumbnailService $thumbnails,
    ) {
    }

    public function render(string $html): string
    {
        if (!str_contains($html, '[lumora_folder_gallery')) {
            return $html;
        }

        $result = preg_replace_callback(
            self::PATTERN,
            fn (array $matches): string => $this->renderOne($this->parseAttributes($matches[1])),
            $html,
        );

        return $result ?? $html;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderOne(array $attributes): string
    {
        $folderId = (int) ($attributes['folder_id'] ?? 0);

        if ($folderId <= 0) {
            return '';
        }

        $folder = $this->folders->findById($folderId);

        if ($folder === null) {
            return '';
        }

        $link = $attributes['link'] ?? 'none';

        if (!in_array($link, ['none', 'full'], true)) {
            $link = 'none';
        }

        $size = $attributes['size'] ?? 'small';

        if (!in_array($size, ['small', 'medium', 'large', 'full'], true)) {
            $size = 'small';
        }

        $items = $this->media->query(['folderIds' => [$folder->id], 'type' => 'image'], self::MAX_IMAGES)['items'];

        if ($items === []) {
            return '';
        }

        return $this->renderGallery($items, $link, $size);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function renderGallery(array $items, string $link, string $size): string
    {
        $html = '<div class="lp-folder-gallery"><ul class="lp-folder-gallery__items">';

        foreach ($items as $item) {
            $html .= '<li class="lp-folder-gallery__item">' . $this->renderThumbnail($item, $link, $size) . '</li>';
        }

        $html .= '</ul></div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderThumbnail(array $item, string $link, string $size): string
    {
        $resolved = $this->resolveImage($item, $size);
        $alt = (string) ($item['alt_text'] ?? '');
        $dimensionAttrs = ($resolved['width'] > 0 ? ' width="' . $resolved['width'] . '"' : '')
            . ($resolved['height'] > 0 ? ' height="' . $resolved['height'] . '"' : '');

        // A size class per the *requested* size, plus is-cropped only
        // when $resolved actually is that size's exact crop box (a real
        // generated thumbnail, not a same-or-smaller original fallen
        // back to) — style.css only forces a fixed square box for the
        // combination of both, so an undersized original renders at its
        // own real (smaller) dimensions instead of being upscaled to
        // fill a box it was never actually resized into. See
        // resolveImage()'s own docblock.
        $sizeClass = 'lp-folder-gallery__thumb--' . $size . ($resolved['isCropped'] ? ' is-cropped' : '');

        if ($link === 'full') {
            $fullUrl = $this->media->url($item);
            $fullWidth = (int) ($item['width'] ?? 0);
            $fullHeight = (int) ($item['height'] ?? 0);

            $img = '<img class="lp-folder-gallery__thumb ' . $sizeClass . '" src="' . esc_url($resolved['url']) . '" alt="' . esc_html($alt) . '"' . $dimensionAttrs . ' loading="lazy">';

            return '<a class="lp-folder-gallery__link" href="' . esc_url($fullUrl) . '"'
                . ($fullWidth > 0 ? ' data-pswp-width="' . $fullWidth . '"' : '')
                . ($fullHeight > 0 ? ' data-pswp-height="' . $fullHeight . '"' : '')
                . ' data-pswp-caption="' . esc_html($alt) . '">' . $img . '</a>';
        }

        // "Link To: None" opts the thumbnail out of
        // ContentRenderer::addLightboxAttributes()'s automatic
        // self-link — without the no-lightbox class, any bare <img>
        // still gets wrapped in a lightbox anchor pointing at its own
        // (small, cropped) src, the same behavior Insert Image's own
        // "Link To: None" already avoids this exact way.
        return '<img class="lp-folder-gallery__thumb ' . $sizeClass . ' no-lightbox" src="' . esc_url($resolved['url']) . '" alt="' . esc_html($alt) . '"' . $dimensionAttrs . ' loading="lazy">';
    }

    /**
     * Resolves $size to a real URL and real, never-upscaled dimensions —
     * ThumbnailService never generates a thumbnail larger than its
     * source (generateOne() skips a size the source is already smaller
     * than in both dimensions), so a size that was never actually
     * generated falls back to the original file and its own real
     * width/height, exactly the way the Insert Image picker's own
     * gridThumbnailUrl()/sizes map already does client-side. `isCropped`
     * is true only when a real thumbnail row exists for $size *and*
     * that size's configured mode is 'crop' (only "Thumbnail"/small by
     * default) — style.css uses it to decide whether forcing a fixed
     * square box is actually correct for what's being rendered.
     *
     * @param array<string, mixed> $item
     * @return array{url: string, width: int, height: int, isCropped: bool}
     */
    private function resolveImage(array $item, string $size): array
    {
        $fallback = [
            'url' => $this->media->url($item),
            'width' => (int) ($item['width'] ?? 0),
            'height' => (int) ($item['height'] ?? 0),
            'isCropped' => false,
        ];

        if ($size === 'full') {
            return $fallback;
        }

        $url = $this->thumbnails->url($item, $size);

        if ($url === null) {
            return $fallback;
        }

        $thumbnailRow = null;

        foreach ($this->thumbnails->thumbnailsFor((int) $item['id']) as $row) {
            if ($row['size_name'] === $size) {
                $thumbnailRow = $row;

                break;
            }
        }

        if ($thumbnailRow === null) {
            return $fallback;
        }

        return [
            'url' => $url,
            'width' => (int) $thumbnailRow['width'],
            'height' => (int) $thumbnailRow['height'],
            'isCropped' => ($this->thumbnails->sizes()[$size]['mode'] ?? '') === 'crop',
        ];
    }

    /**
     * @param string $rawAttributes e.g. ` folder_id="12" link="full"`
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
