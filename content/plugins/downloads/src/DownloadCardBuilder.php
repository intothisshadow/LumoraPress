<?php

/**
 * Builds the card every public download list shows for each download.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use Closure;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Theme\MediaViewer;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Services\ContentRenderer;

/**
 * One builder for every list shortcode, so their cards cannot drift apart. The `.lp-downloads-list__*`
 * classes stay on the same elements as before, so a theme's existing rules keep applying.
 *
 * @phpstan-type CardImage array{full: string, thumb: string, alt: string, caption: string, width: int, height: int}
 * @phpstan-type CardContent array{html: string, slides: array<int, CardImage>, thumbnail: CardImage|null, descriptionImages: array<int, CardImage>}
 */
final class DownloadCardBuilder
{
    /** A description with more text than this gets a keyboard-reachable scroll region. */
    private const LONG_DESCRIPTION_CHARACTERS = 350;

    private static bool $scriptUsed = false;

    /**
     * @param Closure(): ?DownloadMediaUrlMasker $maskerResolver Called only when a description actually
     *     contains an upload URL, because building a masker can mean opening a database connection.
     */
    public function __construct(
        private readonly DownloadService $downloads,
        private readonly ContentRenderer $content,
        private readonly Closure $maskerResolver,
        private readonly DescriptionImageExtractor $extractor = new DescriptionImageExtractor(),
    ) {
    }

    /**
     * Whether a card this request has several images or a description, so the footer only loads the
     * script (carousel buttons, scroll-region focus) on pages that need it.
     */
    public static function scriptUsed(): bool
    {
        return self::$scriptUsed;
    }

    public static function resetScriptUsed(): void
    {
        self::$scriptUsed = false;
    }

    /**
     * Everything a card needs from each download: its description with the images and a repeated
     * title taken out, and the images in carousel order (the thumbnail first, then those found in
     * the description, each picture once). Thumbnails are looked up for the whole list at once.
     *
     * @param array<int, Download> $downloads
     * @return array<int, CardContent> keyed by download id
     */
    public function forDownloads(array $downloads): array
    {
        $thumbnails = $this->downloads->thumbnailImages($downloads);
        $result = [];

        foreach ($downloads as $download) {
            $html = $download->description !== '' ? $this->descriptionHtml($download->description, $download->descriptionFormat) : '';
            $extracted = $this->extractor->extract($this->extractor->withoutLeadingTitle($html, $download->title));
            $thumbnail = $thumbnails[$download->id] ?? null;
            $slides = [];
            $seen = [];

            foreach ($thumbnail !== null ? [$thumbnail, ...$extracted['images']] : $extracted['images'] as $image) {
                if (!isset($seen[$image['full']])) {
                    $seen[$image['full']] = true;
                    $slides[] = $image;
                }
            }

            $result[$download->id] = [
                'html' => $extracted['html'],
                'slides' => $slides,
                'thumbnail' => $thumbnail,
                'descriptionImages' => $extracted['images'],
            ];
        }

        return $result;
    }

    /**
     * The content of a card for a file that has no Download row (one filed in a Media folder): plain
     * escaped text and no images.
     *
     * @return CardContent
     */
    public function plainContent(string $escapedDescriptionHtml): array
    {
        return ['html' => $escapedDescriptionHtml, 'slides' => [], 'thumbnail' => null, 'descriptionImages' => []];
    }

    public function descriptionHtml(string $description, ContentFormat $format): string
    {
        $rendered = $this->content->render($description, $format);
        $prefix = rtrim(BasePath::get() . '/content/uploads', '/') . '/';

        // Rewrites an embedded image URL that still points at the real upload path.
        if (str_contains($rendered, $prefix)) {
            $masker = ($this->maskerResolver)();

            if ($masker !== null) {
                $rendered = $masker->mask($rendered);
            }
        }

        return $rendered;
    }

    /**
     * @param CardContent $content
     */
    public function render(string $title, string $url, ?string $size, array $content, int $headingLevel): string
    {
        $level = max(2, min(6, $headingLevel));
        $slides = $content['slides'];

        $html = '<li class="lp-downloads-list__item lp-download-card' . ($slides !== [] ? ' lp-download-card--has-media' : '') . '">';
        $html .= '<h' . $level . ' class="lp-download-card__title">' . esc_html($title) . '</h' . $level . '>';
        $html .= $this->mediaHtml($title, $slides);
        $html .= '<div class="lp-download-card__body">';
        $html .= '<p class="lp-download-card__meta">';
        $html .= '<a class="lp-downloads-list__link lp-download-card__link" href="' . esc_url($url) . '" aria-label="' . esc_attr('Download ' . $title) . '">Download</a>';

        if ($size !== null) {
            $html .= ' <span class="lp-downloads-list__size lp-download-card__size">(' . esc_html($size) . ')</span>';
        }

        $html .= '</p>';
        $html .= $this->descriptionBlock($title, $content['html']);

        return $html . '</div></li>';
    }

    /**
     * @param array<int, CardImage> $slides
     */
    private function mediaHtml(string $title, array $slides): string
    {
        if ($slides === []) {
            return '';
        }

        $count = count($slides);
        MediaViewer::markUsed();

        if ($count > 1) {
            self::$scriptUsed = true;
        }

        // lp-gallery makes each card its own lightbox group; data-lp-carousel is what the script finds.
        $html = '<div class="lp-download-card__media lp-gallery" data-lp-carousel role="region" aria-roledescription="carousel" aria-label="' . esc_attr('Images for ' . $title) . '">';
        $html .= '<div class="lp-download-card__carousel" data-lp-carousel-track' . ($count > 1 ? ' tabindex="0"' : '') . '>';

        foreach ($slides as $index => $slide) {
            $html .= '<div class="lp-download-card__slide" data-lp-carousel-slide role="group" aria-roledescription="slide" aria-label="' . ($index + 1) . ' of ' . $count . '">';
            $html .= '<a href="' . esc_url($slide['full']) . '" data-pswp-lightbox="1"'
                . ($slide['width'] > 0 ? ' data-pswp-width="' . $slide['width'] . '"' : '')
                . ($slide['height'] > 0 ? ' data-pswp-height="' . $slide['height'] . '"' : '')
                . ($slide['caption'] !== '' ? ' data-pswp-caption="' . esc_attr($slide['caption']) . '"' : '')
                . '>';
            $html .= '<img src="' . esc_url($slide['thumb']) . '" alt="' . esc_attr($slide['alt']) . '" loading="lazy" decoding="async">';
            $html .= '</a></div>';
        }

        return $html . '</div></div>';
    }

    private function descriptionBlock(string $title, string $descriptionHtml): string
    {
        if ($descriptionHtml === '') {
            return '';
        }

        self::$scriptUsed = true;
        $long = mb_strlen(trim(strip_tags($descriptionHtml))) > self::LONG_DESCRIPTION_CHARACTERS;

        // A scrollable area has to be reachable without a mouse; the script trims this to what really overflows.
        return '<div class="lp-downloads-list__description lp-download-card__description" data-lp-scroll-region'
            . ($long ? ' tabindex="0" role="region" aria-label="' . esc_attr('Description of ' . $title) . '"' : '')
            . '>' . $descriptionHtml . '</div>';
    }
}
