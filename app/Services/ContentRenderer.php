<?php

/**
 * The one place a post/page's raw stored content becomes safe, final HTML (LP-015/LP-016).
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
 * HTML (LP-015/LP-016) — branches on ContentFormat:
 *
 *   Markdown -> MarkdownParser::toHtml() -> HtmlSanitizer::clean()
 *   Html     -> HtmlSanitizer::clean() directly (hand-typed or TinyMCE
 *               output — both are untrusted client input by the time
 *               they're stored, same as Markdown's generated HTML)
 *   Plain    -> nl2br(escaped text) — the exact pre-LP-015 behaviour,
 *               preserved for rows that predate this column
 *
 * Every branch ends in HtmlSanitizer, so there is exactly one XSS
 * boundary for all three formats rather than one per editor.
 */
final class ContentRenderer
{
    /**
     * Extensions MediaService actually accepts for images, minus the
     * types it excludes from public display (ico) — matches which files
     * a content `<a href="...">` could plausibly point at directly. Kept
     * here rather than referencing MediaService's own allow-list
     * constant, since that list also covers non-image types this check
     * has no interest in — see LP-076's docblock for why ContentRenderer
     * otherwise stays free of any MediaService dependency.
     */
    private const LIGHTBOXABLE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * LP-079's More tag — the equivalent of WordPress's `<!--more-->` —
     * on raw stored `content`, typed by the author into the Markdown/
     * Plain textarea directly. Detected/split on the *raw* content,
     * before render() ever runs, since HtmlSanitizer strips HTML
     * comments outright (see its cleanNode() — a comment node is neither
     * DOMText nor DOMElement, so it's simply removed) and would destroy
     * this marker before it could ever be found in already-sanitized
     * output. Markdown/Plain content is never sanitized at all (see
     * PostService::sanitizeStoredContent()'s docblock), so the literal
     * text survives there unmodified until this class explicitly looks
     * for it.
     */
    private const MORE_TAG_TEXT_MARKER = '<!--more-->';

    /**
     * The WYSIWYG-format equivalent — an Html-format post's raw content
     * *is* sanitized at save time (PostService::sanitizeStoredContent()),
     * so an HTML comment could never survive being stored in the first
     * place. `<span class="lp-more-tag">...</span>` uses only tag/
     * attribute combinations HtmlSanitizer's own allowlist already
     * permits (see its ALLOWED_TAGS — 'span' => ['class']), so it
     * round-trips through sanitization intact. The pattern matches any
     * inner text (content-editor.js's TinyMCE button inserts a visible
     * "Read More" label so the marker isn't just an invisible empty
     * element while editing) — that text is discarded along with the
     * rest of the match, never rendered. The first alternative also
     * consumes a single wrapping `<p>...</p>` when the marker is its
     * only content — exactly what that same TinyMCE button inserts
     * (`<p><span class="lp-more-tag">...</span></p>`) — so splitting
     * doesn't leave a stray empty paragraph behind; the second
     * alternative is the bare-span fallback for a marker not wrapped
     * that way (e.g. hand-edited HTML).
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

        return $this->hooks->applyFilters('content_html', $html, $content, $format);
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
     * un-rendered source (see the two MORE_TAG_*_MARKER constants'
     * docblocks for why it must happen here rather than after render()).
     * The marker itself is removed, never present in either returned
     * half.
     *
     * With no marker present, the first element is $rawContent
     * unchanged and the second is null — callers use that null/non-null
     * distinction to tell "the whole post, nothing to cut" from "here's
     * the author's chosen cutoff point." See get_the_excerpt()/
     * get_the_content() (include/content-display-functions.php) for the
     * actual callers.
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
     * LP-076: makes every content-embedded image lightbox-capable
     * (PhotoSwipe/LP-031), not just the featured image
     * (`the_post_thumbnail_lightbox()` in include/media-functions.php,
     * the only other place `data-pswp-*` attributes are emitted). Runs
     * on already-sanitized HTML — the attributes added here are never at
     * risk of being an injection vector, so this is a plain second DOM
     * pass rather than something folded into HtmlSanitizer's allowlist.
     *
     * An `<img>` already wrapped in an author-added `<a>` keeps that
     * link untouched unless its href looks like a direct link to an
     * image file — an intentional link to something else (an external
     * page, a different post) is never hijacked into a lightbox trigger.
     * An unwrapped `<img>` is wrapped in a new self-link instead, so a
     * plain inserted image (no "Link To" chosen) still opens a lightbox.
     * A `no-lightbox` class, on either element, opts out entirely.
     *
     * render_content() (include/theme.php) is what actually checks for
     * this method's output and calls MediaViewer::markUsed()/wraps the
     * result in `.lp-gallery` — kept out of this class since
     * ContentRenderer::render() is also used for excerpts/OG descriptions
     * (via toPlainText(), which strips these attributes right back out
     * again) where that side effect would be meaningless.
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

                // LP-075's "Link To: Media File" step can link to a size
                // other than the one the inline <img> displays (e.g. a
                // Thumbnail-size image linked to the Full-size original)
                // — for that case the editor embeds the *linked* file's
                // own real data-pswp-width/height/caption directly (see
                // content-editor.js's TinyMCE insertion), which must win
                // over inferring from the <img>'s own, possibly smaller,
                // display-size attributes below.
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

            // A stable marker present regardless of whether width/height
            // are known — Markdown-authored images never carry width/
            // height at all (Markdown has no attribute syntax), so
            // data-pswp-width alone can't be the "this anchor belongs to
            // a lightbox gallery" signal both render_content() and
            // media-viewer.js's gallery selector need; without this, a
            // Markdown-authored image was silently never lightboxed —
            // its self-link existed, but nothing marked it as such.
            $link->setAttribute('data-pswp-lightbox', '1');

            $width = (int) $img->getAttribute('width');
            $height = (int) $img->getAttribute('height');
            $linkHref = $link->getAttribute('href');

            // The <img>'s own width/height only describe the *linked*
            // file's real dimensions when the link points at the exact
            // same file the <img> displays (the self-link case, or an
            // editor-authored link with matching size) — otherwise (an
            // author- or Markdown-linked thumbnail pointing at a larger
            // original, or no width/height at all, which every
            // Markdown-authored image hits) they're either wrong or
            // absent. PhotoSwipe uses data-pswp-width/height to size the
            // slide *before* the real image finishes loading, not just
            // as a caption hint — a missing or wrong value visibly
            // stretches/distorts the displayed image, it's not cosmetic.
            // resolveImageDimensions() reads the real file directly in
            // either case.
            if ($width <= 0 || $height <= 0 || $linkHref !== $img->getAttribute('src')) {
                $resolved = $this->resolveImageDimensions($linkHref);

                if ($resolved !== null) {
                    [$width, $height] = $resolved;
                }
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

    private function looksLikeImageUrl(string $url): bool
    {
        if ($url === '' || parse_url($url, PHP_URL_HOST) !== null) {
            // No host means relative/root-relative — same-site by
            // construction. A URL with a host is only ever same-site if
            // it matches this install, which isn't worth resolving here;
            // treating any absolute external URL as "not an image link"
            // simply leaves the author's link untouched, the safe default.
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::LIGHTBOXABLE_IMAGE_EXTENSIONS, true);
    }

    /**
     * Reads a same-site image URL's real pixel dimensions directly off
     * disk — only ever called for a URL looksLikeImageUrl() already
     * confirmed is relative/root-relative (never a host), so this is
     * never resolving an arbitrary external address. dirname(__DIR__, 2)
     * rather than the LUMORA_ROOT constant — this class is also
     * exercised by the PHP Test Suite's bootstrap, which never defines
     * that constant (see admin_asset_url()'s identical note in
     * include/helpers.php) — so a self-contained path derived from this
     * file's own location works in both contexts.
     *
     * @return array{0: int, 1: int}|null
     */
    private function resolveImageDimensions(string $url): ?array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = BasePath::stripFrom($path);
        $absolutePath = rtrim(dirname(__DIR__, 2), '/') . '/' . ltrim($path, '/');

        if (!is_file($absolutePath)) {
            return null;
        }

        $dimensions = @getimagesize($absolutePath);

        return is_array($dimensions) ? [(int) $dimensions[0], (int) $dimensions[1]] : null;
    }

    /**
     * Best-effort conversion when an author switches a post/page's editor
     * format in the admin UI (LP-016's "Switch between Markdown and
     * WYSIWYG" / "Import existing Markdown" / "Export clean Markdown
     * where possible"). Only meaningfully converts between Markdown and
     * Html — Plain has no structure to convert from/to, so it's always
     * returned unchanged; going TO Plain from either format also returns
     * the source unchanged; deliberately not stripped, since "plain
     * text" here means "stop interpreting formatting," not "discard
     * content" (an author switching Html -> Plain by mistake shouldn't
     * lose visible content). Only ever needs to round-trip
     * HtmlSanitizer::ALLOWED_TAGS's tag set, since that's the only HTML
     * this application ever stores in the first place.
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
     * strips the rendered HTML back down to text rather than
     * strip_tags()-ing the raw Markdown/HTML source directly, so a
     * Markdown excerpt never leaks literal "**bold**"/"# heading" syntax
     * (see include/helpers.php's make_excerpt(), which this feeds).
     */
    public function toPlainText(string $content, ContentFormat $format): string
    {
        if ($format === ContentFormat::Plain) {
            return $content;
        }

        return strip_tags($this->render($content, $format));
    }
}
