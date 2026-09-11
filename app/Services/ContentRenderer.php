<?php

/**
 * The one place a post/page's raw stored content becomes safe, final HTML.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DOMDocument;
use DOMElement;
use LumoraPress\Core\Content\HtmlSanitizer;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Content\HtmlToMarkdownConverter;
use LumoraPress\Core\Content\MarkdownParser;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Models\ContentFormat;

/**
 * The one place a post/page's raw stored `content` becomes safe, final
 * HTML — branches on ContentFormat:
 *
 *   Markdown -> MarkdownParser::toHtml() -> HtmlSanitizer::clean()
 *   Html     -> HtmlSanitizer::clean() directly (hand-typed or TinyMCE
 *               output — both are untrusted client input by the time
 *               they're stored, same as Markdown's generated HTML)
 *   Plain    -> nl2br(escaped text) — the original behaviour, preserved
 *               for rows that predate the other formats
 *
 * Every branch ends in HtmlSanitizer, so there is exactly one XSS
 * boundary for all three formats rather than one per editor.
 */
final class ContentRenderer
{
    /**
     * Extensions MediaService accepts for images, minus ico (excluded
     * from public display). Kept local rather than referencing
     * MediaService's own list, to stay free of that dependency.
     */
    private const LIGHTBOXABLE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * The More tag (WordPress's `<!--more-->` equivalent), typed by the
     * author into the Markdown/Plain textarea directly. Detected on the
     * raw content before render() ever runs, since HtmlSanitizer strips
     * HTML comments outright and would destroy this marker first.
     */
    private const MORE_TAG_TEXT_MARKER = '<!--more-->';

    /**
     * The WYSIWYG-format equivalent — an Html-format post's raw content
     * is sanitized at save time, so an HTML comment marker could never
     * survive. `<span class="lp-more-tag">...</span>` uses only tags
     * HtmlSanitizer's allowlist permits, so it round-trips intact. The
     * first alternative also consumes a wrapping `<p>` so splitting
     * doesn't leave a stray empty paragraph; the second is the bare-span
     * fallback for hand-edited HTML.
     */
    private const MORE_TAG_HTML_MARKER_PATTERN = '#<p[^>]*>\s*<span[^>]*\bclass="lp-more-tag"[^>]*>.*?</span>\s*</p>|<span[^>]*\bclass="lp-more-tag"[^>]*>.*?</span>#is';

    public function __construct(
        private readonly MarkdownParser $markdown,
        private readonly HtmlSanitizer $sanitizer,
        private readonly HookManager $hooks,
        private readonly HtmlToMarkdownConverter $htmlToMarkdown = new HtmlToMarkdownConverter(),
    ) {
    }

    public function render(string $content, ContentFormat $format): string
    {
        $html = match ($format) {
            ContentFormat::Markdown => $this->sanitizer->clean($this->hooks->applyFilters('markdown_html', $this->markdown->toHtml($content), $content)),
            ContentFormat::Html => $this->sanitizer->clean($this->hooks->applyFilters('wysiwyg_html', $content)),
            ContentFormat::Plain => nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        };

        $html = $this->addLightboxAttributes($html);
        $html = $this->hooks->applyFilters('content_html', $html, $content, $format);

        // Runs last, after every shortcode has expanded — a block-level shortcode commonly leaves malformed <p> nesting behind that needs repairing.
        return $this->sanitizer->repairNesting($html);
    }

    /**
     * Whether $rawContent (a post's un-rendered, stored `content`)
     * contains a More tag — see splitAtMoreTag()'s docblock for what
     * that marker looks like per ContentFormat.
     */
    public function hasMoreTag(string $rawContent): bool
    {
        return $this->splitAtMoreTag($rawContent)[1] !== null;
    }

    /**
     * Splits $rawContent at its first More tag, operating on the raw,
     * un-rendered source. The marker itself is removed from both halves.
     *
     * With no marker present, the first element is $rawContent unchanged
     * and the second is null — callers use that distinction to tell
     * "nothing to cut" from "here's the author's chosen cutoff point."
     *
     * @return array{0: string, 1: ?string}
     */
    public function splitAtMoreTag(string $rawContent): array
    {
        $textPosition = strpos($rawContent, self::MORE_TAG_TEXT_MARKER);

        if ($textPosition !== false) {
            return [
                substr($rawContent, 0, $textPosition),
                substr($rawContent, $textPosition + strlen(self::MORE_TAG_TEXT_MARKER)),
            ];
        }

        if (preg_match(self::MORE_TAG_HTML_MARKER_PATTERN, $rawContent, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $matchedText = $matches[0][0];
            $matchedOffset = $matches[0][1];

            return [
                substr($rawContent, 0, $matchedOffset),
                substr($rawContent, $matchedOffset + strlen($matchedText)),
            ];
        }

        return [$rawContent, null];
    }

    /**
     * Makes every content-embedded image lightbox-capable (PhotoSwipe), not just the
     * featured image, as a second DOM pass over already-sanitized HTML. An `<img>` already
     * wrapped in an author-added `<a>` keeps that link untouched unless it looks like a
     * direct image link; an unwrapped `<img>` is wrapped in a new self-link. A `no-lightbox`
     * class on either element opts out.
     */
    private function addLightboxAttributes(string $html): string
    {
        if (!str_contains($html, '<img')) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="lp-lightbox-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('lp-lightbox-root');

        if ($root === null) {
            return $html;
        }

        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            if (!$img instanceof DOMElement || $this->hasNoLightboxClass($img)) {
                continue;
            }

            $parent = $img->parentNode;
            $existingLink = $parent instanceof DOMElement && strtolower($parent->tagName) === 'a' ? $parent : null;

            if ($existingLink !== null) {
                if ($this->hasNoLightboxClass($existingLink) || !$this->looksLikeImageUrl($existingLink->getAttribute('href'))) {
                    continue;
                }

                // "Link To: Media File" can link to a different size than the inline <img> displays; the editor's own embedded dimensions must win over inferring below.
                if ($existingLink->hasAttribute('data-pswp-width')) {
                    continue;
                }

                $link = $existingLink;
            } else {
                $src = $img->getAttribute('src');

                if ($src === '' || $parent === null) {
                    continue;
                }

                $link = $dom->createElement('a');
                $link->setAttribute('href', $src);
                $parent->replaceChild($link, $img);
                $link->appendChild($img);
            }

            // A stable marker regardless of whether width/height are known — Markdown images never carry those attributes, so data-pswp-width alone can't be the gallery signal.
            $link->setAttribute('data-pswp-lightbox', '1');

            $width = (int) $img->getAttribute('width');
            $height = (int) $img->getAttribute('height');
            $linkHref = $link->getAttribute('href');

            // The <img>'s own width/height are only trusted as a last
            // resort — imported WordPress content can leave them stale
            // (rewritten src/href to the full-size original, but old
            // display-size width/height left behind). Resolving the real
            // file directly is a cheap getimagesize() read, and PhotoSwipe
            // needs a correct value to size the slide before the image loads.
            $resolved = $this->resolveImageDimensions($linkHref);

            if ($resolved !== null) {
                [$width, $height] = $resolved;
            }

            if ($width > 0) {
                $link->setAttribute('data-pswp-width', (string) $width);
            }

            if ($height > 0) {
                $link->setAttribute('data-pswp-height', (string) $height);
            }

            $alt = $img->getAttribute('alt');

            if ($alt !== '') {
                $link->setAttribute('data-pswp-caption', $alt);
            }
        }

        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private function hasNoLightboxClass(DOMElement $element): bool
    {
        return in_array('no-lightbox', preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }

    /**
     * An absolute URL (e.g. imported content, or a hand-typed link to a
     * companion site's own media) qualifies exactly like a relative one —
     * only the extension decides. resolveImageDimensions() already fails
     * closed for a host it can't read from local disk, falling back to the
     * <img>'s own width/height, so widening this to absolute URLs needs no
     * change there.
     */
    private function looksLikeImageUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::LIGHTBOXABLE_IMAGE_EXTENSIONS, true);
    }

    /**
     * Reads a same-site image URL's real pixel dimensions directly off
     * disk — only ever called for a URL looksLikeImageUrl() already
     * confirmed is relative/root-relative. dirname(__DIR__, 2) rather
     * than LUMORA_ROOT, since the test suite's bootstrap never defines that constant.
     *
     * HtmlSanitizer::isSafeUrl() allows any relative href through,
     * "../" segments included, so the resolved path is re-checked with
     * realpath() against the project root before ever touching disk —
     * a post body linking to e.g. "../../config/config.php" must fail
     * closed exactly like a genuinely missing file, not read outside
     * the tree.
     *
     * @return array{0: int, 1: int}|null
     */
    private function resolveImageDimensions(string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = BasePath::stripFrom($path);
        $root = rtrim(dirname(__DIR__, 2), '/');
        $absolutePath = $root . '/' . ltrim($path, '/');

        $realRoot = realpath($root);
        $realPath = realpath($absolutePath);

        if ($realRoot === false || $realPath === false || !is_file($realPath)) {
            return null;
        }

        if ($realPath !== $realRoot && !str_starts_with($realPath, $realRoot . '/')) {
            return null;
        }

        $dimensions = @getimagesize($realPath);

        return is_array($dimensions) ? [(int) $dimensions[0], (int) $dimensions[1]] : null;
    }

    /**
     * Best-effort conversion when an author switches a post/page's
     * editor format. Only meaningfully converts between Markdown and
     * Html — Plain has no structure to convert from/to, so any
     * conversion involving Plain returns the source unchanged rather
     * than stripping it (switching to Plain by mistake shouldn't lose
     * visible content).
     */
    public function convertFormat(string $content, ContentFormat $from, ContentFormat $to): string
    {
        if ($from === $to || $to === ContentFormat::Plain || $from === ContentFormat::Plain) {
            return $content;
        }

        // $to is never Plain here — guarded above — so only the Html and
        // Markdown conversion directions remain.
        return $to === ContentFormat::Html
            ? $this->sanitizer->clean($this->markdown->toHtml($content))
            : $this->htmlToMarkdown->toMarkdown($this->sanitizer->clean($content));
    }

    /**
     * Plain-text rendering for excerpts/search snippets/OG descriptions —
     * strips the rendered HTML rather than strip_tags()-ing the raw
     * source directly, so a Markdown excerpt never leaks literal
     * "**bold**"/"# heading" syntax.
     */
    public function toPlainText(string $content, ContentFormat $format): string
    {
        if ($format === ContentFormat::Plain) {
            return $content;
        }

        return strip_tags($this->render($content, $format));
    }
}
