<?php

/**
 * Lifts the images out of a rendered download description so a card can show them as a carousel.
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

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Pure string-in, string-out: it works on HTML that ContentRenderer has already sanitized, only
 * ever removing nodes and reading attributes, so it needs no sanitizing of its own.
 *
 * @phpstan-type CardImage array{full: string, thumb: string, alt: string, caption: string, width: int, height: int}
 */
final class DescriptionImageExtractor
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];

    /** Anything declared smaller than this is an icon or emoji, which belongs in the running text. */
    private const MIN_SLIDE_PIXELS = 48;

    /**
     * @return array{html: string, images: array<int, CardImage>}
     */
    public function extract(string $html): array
    {
        if (!str_contains($html, '<img')) {
            return ['html' => $html, 'images' => []];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="lp-extract-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $root = $dom->getElementById('lp-extract-root');

        if ($root === null) {
            return ['html' => $html, 'images' => []];
        }

        $images = [];
        $seen = [];

        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }

            $image = $this->imageFrom($img);

            if ($image === null) {
                continue;
            }

            // The same picture often appears twice (a hand-written one and the GitHub gallery's copy).
            if (!isset($seen[$image['full']])) {
                $seen[$image['full']] = true;
                $images[] = $image;
            }

            $this->removeWithEmptiedWrappers($img, $root);
        }

        $this->dropEmptiedHeadings($root, $images !== []);

        return ['html' => $this->innerHtml($dom, $root), 'images' => $images];
    }

    /**
     * Removes a first heading that only repeats the download's own title (a README starts with one).
     */
    public function withoutLeadingTitle(string $html, string $title): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="lp-extract-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $root = $dom->getElementById('lp-extract-root');
        $first = $root !== null ? $this->firstElement($root) : null;

        if ($root === null || $first === null || !preg_match('/^h[1-6]$/', strtolower($first->tagName))) {
            return $html;
        }

        $titleKey = $this->comparable($title);

        // A GitHub import stores the readable form of an identifier-style heading as the title.
        $matches = $titleKey !== '' && (
            $this->comparable($first->textContent) === $titleKey
            || $this->comparable(ReadmeTransformer::readableTitle(trim($first->textContent))) === $titleKey
        );

        if (!$matches) {
            return $html;
        }

        $root->removeChild($first);

        return $this->innerHtml($dom, $root);
    }

    /**
     * @return CardImage|null
     */
    private function imageFrom(DOMElement $img): ?array
    {
        $src = trim($img->getAttribute('src'));

        if ($src === '' || $this->isTiny($img) || in_array('emoji', preg_split('/\s+/', $img->getAttribute('class')) ?: [], true)) {
            return null;
        }

        $parent = $img->parentNode;
        $link = $parent instanceof DOMElement && strtolower($parent->tagName) === 'a' ? $parent : null;
        $full = $src;

        if ($link !== null) {
            $href = trim($link->getAttribute('href'));

            // A badge or picture that links somewhere else is not a screenshot, so it stays in the text.
            if (!$this->looksLikeImage($href)) {
                return null;
            }

            $full = $href;
        }

        $width = $link !== null && ctype_digit($link->getAttribute('data-pswp-width')) ? (int) $link->getAttribute('data-pswp-width') : 0;
        $height = $link !== null && ctype_digit($link->getAttribute('data-pswp-height')) ? (int) $link->getAttribute('data-pswp-height') : 0;

        // The <img>'s own size only describes the picture it shows, so it is used when it is the same file.
        if ($width === 0 && $full === $src) {
            $width = ctype_digit($img->getAttribute('width')) ? (int) $img->getAttribute('width') : 0;
            $height = ctype_digit($img->getAttribute('height')) ? (int) $img->getAttribute('height') : 0;
        }

        $alt = trim($img->getAttribute('alt'));
        $caption = $link !== null ? trim($link->getAttribute('data-pswp-caption')) : '';

        return [
            'full' => $full,
            'thumb' => $src,
            'alt' => $alt,
            'caption' => $caption !== '' ? $caption : $alt,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * An icon or emoji is small both ways. One small dimension alone says nothing: imported content
     * keeps a stale `width="44"` on a tall screenshot.
     */
    private function isTiny(DOMElement $img): bool
    {
        $width = ctype_digit($img->getAttribute('width')) ? (int) $img->getAttribute('width') : 0;
        $height = ctype_digit($img->getAttribute('height')) ? (int) $img->getAttribute('height') : 0;

        return $width > 0 && $width < self::MIN_SLIDE_PIXELS && $height > 0 && $height < self::MIN_SLIDE_PIXELS;
    }

    private function looksLikeImage(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/media/\d+/view$#', $path) === 1) {
            return true;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Removes the image, then each wrapper (link, paragraph, figure) it leaves with nothing in it.
     */
    private function removeWithEmptiedWrappers(DOMNode $node, DOMElement $root): void
    {
        while ($node !== $root && $node->parentNode !== null) {
            $parent = $node->parentNode;
            $parent->removeChild($node);

            if (!$parent instanceof DOMElement || $parent === $root || !$this->isEmptyWrapper($parent)) {
                return;
            }

            $node = $parent;
        }
    }

    private function isEmptyWrapper(DOMElement $element): bool
    {
        if (!in_array(strtolower($element->tagName), ['a', 'p', 'span', 'div', 'figure', 'center', 'em', 'strong'], true)) {
            return false;
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                // A caption goes with its picture; anything else (text, code, a list) keeps the wrapper.
                if (in_array(strtolower($child->tagName), ['br', 'figcaption'], true) || $this->isEmptyWrapper($child)) {
                    continue;
                }

                return false;
            }

            if (trim($child->textContent) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A blank heading, or a heading left with nothing under it before the next heading once its
     * images were lifted out, is just a label for images that are no longer there.
     */
    private function dropEmptiedHeadings(DOMElement $root, bool $liftedImages): void
    {
        foreach (iterator_to_array($root->childNodes) as $node) {
            if (!$node instanceof DOMElement || !preg_match('/^h[1-6]$/', strtolower($node->tagName))) {
                continue;
            }

            // Whitespace from the original markup is not text (a non-breaking space included).
            $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));

            if ($text === '' && $node->getElementsByTagName('img')->length === 0) {
                $root->removeChild($node);

                continue;
            }

            // A heading is only a label for lifted pictures when it was a screenshots heading, or when pictures were lifted at all.
            if (!$liftedImages && preg_match('/^screenshots?$/i', $text) !== 1) {
                continue;
            }

            $next = $node->nextSibling;

            while ($next !== null && !$next instanceof DOMElement && trim($next->textContent) === '') {
                $next = $next->nextSibling;
            }

            if ($next === null || ($next instanceof DOMElement && preg_match('/^h[1-6]$/', strtolower($next->tagName)) === 1)) {
                $root->removeChild($node);
            }
        }
    }

    private function firstElement(DOMElement $root): ?DOMElement
    {
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }

            if (trim($child->textContent) !== '') {
                return null;
            }
        }

        return null;
    }

    private function comparable(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
    }

    private function innerHtml(DOMDocument $dom, DOMElement $root): string
    {
        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= (string) $dom->saveHTML($child);
        }

        return trim($output);
    }
}
