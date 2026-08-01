<?php

declare(strict_types=1);

use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\Theme\FeaturedImages;
use LumoraPress\Core\Theme\MediaViewer;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use LumoraPress\Models\SearchResult;

/**
 * Featured Image theme API (LP-040) — has_post_thumbnail()/
 * post_thumbnail_url()/the_post_thumbnail()/post_thumbnail_caption(),
 * plus the_post_thumbnail_lightbox() (LP-031), available inside theme
 * template files, mirroring classic WordPress naming. Reads
 * MediaService/ThumbnailService/PressConfig via the FeaturedImages static
 * bridge (see its docblock for why a bridge rather than threading these
 * through every SiteController render() call).
 *
 * Post|Page|SearchResult: SearchResult (LP-031) gained its own
 * featuredImageId mirroring Post/Page's, so search results can show a
 * thumbnail too — all three expose the same featuredImageId/title shape
 * this file's functions need.
 */

if (!function_exists('post_thumbnail_media')) {
    /**
     * Resolves the raw media row for $item's featured image, if any —
     * falls back to the configured default featured image, then null.
     * Internal building block for the rest of this API, but left public
     * since a theme may want the raw row (e.g. file_size, mime_type).
     *
     * @return array<string, mixed>|null
     */
    function post_thumbnail_media(Post|Page|SearchResult $item): ?array
    {
        $media = FeaturedImages::media();

        if ($item->featuredImageId !== null) {
            $found = $media->find($item->featuredImageId);

            if ($found !== null) {
                return $found;
            }
        }

        $defaultId = (int) FeaturedImages::config()->option('default_featured_image_media_id', '');

        return $defaultId > 0 ? $media->find($defaultId) : null;
    }
}

if (!function_exists('post_thumbnail_crop')) {
    /**
     * $item's manual crop rectangle (LP-040), if one applies to $media —
     * only ever the item's own featured image, never the site-wide
     * default fallback image post_thumbnail_media() may have returned
     * instead: the crop was drawn against that one specific image, so
     * applying it to a different image (the default, or a stale value
     * left over after the featured image was changed) could produce a
     * nonsensical result. SearchResult (LP-031) has no crop of its own —
     * only Post/Page do — so it always returns null here.
     *
     * @param array<string, mixed> $media
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    function post_thumbnail_crop(Post|Page|SearchResult $item, array $media): ?array
    {
        if (!($item instanceof Post || $item instanceof Page)) {
            return null;
        }

        if ($item->featuredImageCrop === null || $item->featuredImageId === null) {
            return null;
        }

        return $item->featuredImageId === (int) $media['id'] ? $item->featuredImageCrop : null;
    }
}

if (!function_exists('has_post_thumbnail')) {
    function has_post_thumbnail(Post|Page|SearchResult $item): bool
    {
        return post_thumbnail_media($item) !== null;
    }
}

if (!function_exists('post_thumbnail_url')) {
    /**
     * The public URL for $item's featured image at $size, falling back to
     * the original image if that size wasn't generated (e.g. the source
     * was smaller than the target — LP-001's upscale prevention), then to
     * the configured default featured image, then null. Root-relative by
     * default (matches MediaService::url()'s convention); pass
     * $absolute = true for contexts that need a fully-qualified URL (Open
     * Graph tags, RSS/Atom enclosures — neither can be root-relative).
     *
     * If $item has a manual crop (LP-040) for this exact image, that
     * cropped image is returned instead of $size's named thumbnail — a
     * manual crop always wins over the automatic centered one, regardless
     * of which $size was requested, since there is only ever one manual
     * crop per item (not one per named size).
     */
    function post_thumbnail_url(Post|Page|SearchResult $item, string $size = 'medium', bool $absolute = false): ?string
    {
        $media = post_thumbnail_media($item);

        if ($media === null) {
            return null;
        }

        $crop = post_thumbnail_crop($item, $media);

        if ($crop !== null) {
            $cropped = FeaturedImages::thumbnails()->generateFeaturedCrop($media, $crop);

            if ($cropped !== null) {
                $url = $cropped['url'];

                return $absolute && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')
                    ? SiteUrl::get() . '/' . ltrim($url, '/')
                    : $url;
            }
        }

        $url = FeaturedImages::thumbnails()->url($media, $size) ?? FeaturedImages::media()->url($media);

        return $absolute && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')
            ? SiteUrl::get() . '/' . ltrim($url, '/')
            : $url;
    }
}

if (!function_exists('post_thumbnail_caption')) {
    function post_thumbnail_caption(Post|Page|SearchResult $item): ?string
    {
        $media = post_thumbnail_media($item);

        return $media !== null && ($media['caption'] ?? '') !== '' ? (string) $media['caption'] : null;
    }
}

if (!function_exists('the_post_thumbnail')) {
    /**
     * Echoes an <img> for $item's featured image at $size, with a real
     * srcset/sizes built from whichever thumbnail sizes were actually
     * generated for that image (LP-001's ThumbnailService::thumbnailsFor()) —
     * plus the original as the largest candidate. Does nothing if there is
     * no featured image and no default configured.
     *
     * If $item has a manual crop (LP-040) for this exact image, a single
     * fixed-size <img> for that crop is echoed instead — no srcset, since
     * a manual crop has no responsive variants (see
     * ThumbnailService::generateFeaturedCrop()'s docblock).
     *
     * @param array<string, string> $attrs Extra attributes (e.g. "class")
     *     merged onto the <img> tag, the same "let the theme extend it"
     *     idea as nav_menu()'s $menuClass param.
     */
    function the_post_thumbnail(Post|Page|SearchResult $item, string $size = 'medium', array $attrs = []): void
    {
        $media = post_thumbnail_media($item);

        if ($media === null) {
            return;
        }

        $crop = post_thumbnail_crop($item, $media);
        $cropped = $crop !== null ? FeaturedImages::thumbnails()->generateFeaturedCrop($media, $crop) : null;

        if ($cropped !== null) {
            $alt = ($media['alt_text'] ?? '') !== '' ? (string) $media['alt_text'] : $item->title;

            $attributes = [
                'src' => $cropped['url'],
                'alt' => $alt,
                'loading' => 'lazy',
                'width' => (string) $cropped['width'],
                'height' => (string) $cropped['height'],
                ...$attrs,
            ];

            echo '<img';

            foreach ($attributes as $name => $value) {
                if ($value === '') {
                    continue;
                }

                echo ' ' . esc_attr($name) . '="' . ($name === 'src' ? esc_url($value) : esc_attr($value)) . '"';
            }

            echo '>';

            return;
        }

        $src = post_thumbnail_url($item, $size);

        if ($src === null) {
            return;
        }

        $thumbnails = FeaturedImages::thumbnails();
        $rows = $thumbnails->thumbnailsFor((int) $media['id']);

        $candidates = [];

        foreach ($rows as $row) {
            $candidateUrl = $thumbnails->url($media, (string) $row['size_name']);

            if ($candidateUrl !== null) {
                $candidates[(int) $row['width']] = $candidateUrl;
            }
        }

        if ($media['width'] !== null) {
            $candidates[(int) $media['width']] = FeaturedImages::media()->url($media);
        }

        ksort($candidates);

        $srcset = implode(', ', array_map(
            static fn (int $width, string $url): string => esc_url($url) . ' ' . $width . 'w',
            array_keys($candidates),
            array_values($candidates),
        ));

        $matchedRow = null;

        foreach ($rows as $row) {
            if ($row['size_name'] === $size) {
                $matchedRow = $row;

                break;
            }
        }

        $width = $matchedRow !== null ? (int) $matchedRow['width'] : (int) ($media['width'] ?? 0);
        $height = $matchedRow !== null ? (int) $matchedRow['height'] : (int) ($media['height'] ?? 0);
        $alt = ($media['alt_text'] ?? '') !== '' ? (string) $media['alt_text'] : $item->title;

        $attributes = [
            'src' => $src,
            'srcset' => $srcset,
            'sizes' => $width > 0 ? "(max-width: {$width}px) 100vw, {$width}px" : '100vw',
            'alt' => $alt,
            'loading' => 'lazy',
            ...$attrs,
        ];

        if ($width > 0) {
            $attributes['width'] = (string) $width;
        }

        if ($height > 0) {
            $attributes['height'] = (string) $height;
        }

        echo '<img';

        foreach ($attributes as $name => $value) {
            if ($value === '') {
                continue;
            }

            echo ' ' . esc_attr($name) . '="' . ($name === 'src' || $name === 'srcset' ? esc_url($value) : esc_attr($value)) . '"';
        }

        echo '>';
    }
}

if (!function_exists('the_post_thumbnail_lightbox')) {
    /**
     * Same as the_post_thumbnail(), wrapped in an <a> carrying the
     * data-pswp-* attributes PhotoSwipe (LP-031) needs to open it in a
     * lightbox — width/height/caption of whichever image the link
     * actually points at ($largeSize's generated thumbnail if one
     * exists, else the original), never guessed. Marks the page as
     * needing the PhotoSwipe assets via MediaViewer::markUsed(), so
     * footer.php only loads them when at least one of these is rendered.
     * Does nothing if there is no image (mirrors the_post_thumbnail()'s
     * own no-image case) — no dangling empty <a>.
     *
     * @param array<string, string> $attrs Forwarded to the_post_thumbnail().
     */
    function the_post_thumbnail_lightbox(Post|Page|SearchResult $item, string $size = 'medium', string $largeSize = 'large', array $attrs = []): void
    {
        $media = post_thumbnail_media($item);

        if ($media === null) {
            return;
        }

        $href = post_thumbnail_url($item, $largeSize);

        if ($href === null) {
            return;
        }

        MediaViewer::markUsed();

        // If a manual crop (LP-040) applies, $href above already points
        // at the cropped image — its own dimensions must be used here
        // too, not $largeSize's named-thumbnail row, or the data-pswp-*
        // attributes would describe a different image than the one the
        // link actually opens.
        $crop = post_thumbnail_crop($item, $media);
        $cropped = $crop !== null ? FeaturedImages::thumbnails()->generateFeaturedCrop($media, $crop) : null;

        if ($cropped !== null) {
            $width = $cropped['width'];
            $height = $cropped['height'];
        } else {
            $thumbnails = FeaturedImages::thumbnails();
            $largeRow = null;

            foreach ($thumbnails->thumbnailsFor((int) $media['id']) as $row) {
                if ($row['size_name'] === $largeSize) {
                    $largeRow = $row;

                    break;
                }
            }

            $width = $largeRow !== null ? (int) $largeRow['width'] : (int) ($media['width'] ?? 0);
            $height = $largeRow !== null ? (int) $largeRow['height'] : (int) ($media['height'] ?? 0);
        }
        $caption = post_thumbnail_caption($item) ?? (($media['alt_text'] ?? '') !== '' ? (string) $media['alt_text'] : '');

        echo '<a href="' . esc_url($href) . '"'
            . ($width > 0 ? ' data-pswp-width="' . $width . '"' : '')
            . ($height > 0 ? ' data-pswp-height="' . $height . '"' : '')
            . ($caption !== '' ? ' data-pswp-caption="' . esc_attr($caption) . '"' : '')
            . '>';
        the_post_thumbnail($item, $size, $attrs);
        echo '</a>';
    }
}
