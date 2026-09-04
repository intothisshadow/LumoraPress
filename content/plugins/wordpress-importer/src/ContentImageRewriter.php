<?php

/**
 * HTML-aware rewriting of imported WordPress content's own <img>/<a> references to point at the newly-imported local media, and conversion of the [caption] shortcode to a real <figure>/<figcaption>.
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

use DOMDocument;
use DOMElement;

/**
 * A real DOM pass, not a plain string search/replace over each attachment's
 * full-size guid URL — that would miss the common case of an inline `<img>`
 * referencing a WordPress-generated derivative filename (`cover-300x200.jpg`).
 * Stateless, so WordPressImportService constructs a fresh instance directly.
 */
final class ContentImageRewriter
{
    private const UPLOADS_MARKER = '/wp-content/uploads/';

    // WordPress's attachment-id class (`wp-image-123`) — meaningless once the
    // attachment becomes a different Lumora Press media id, so dropped.
    private const ATTACHMENT_ID_CLASS_PATTERN = '/^wp-image-\d+$/';

    // WordPress's size-slug classes — meaningless since this import never
    // generates those derivative sizes. Alignment classes are kept since the
    // default theme's WYSIWYG editor already outputs/styles the same names.
    private const SIZE_CLASS_PATTERN = '/^size-[a-z0-9_-]+$/i';

    /**
     * @param array<string, string> $oldRelativePathToNewUrl the `_wp_attached_file`
     *        relative path recorded per imported attachment, mapped to its new
     *        Lumora Press media URL — see WordPressImportService::importMedia()
     * @return array{content: string, warnings: array<int, string>}
     */
    public function rewrite(string $content, array $oldRelativePathToNewUrl): array
    {
        // Run unconditionally — a post whose only image content is a
        // gallery/tiled-gallery/slideshow construct must still be flagged,
        // even though the DOM pass below has nothing to rewrite there.
        $warnings = $this->unsupportedMediaConstructWarnings($content);

        // Converted rather than just flagged, before the UPLOADS_MARKER
        // short-circuit — a caption with no real upload-path image inside
        // it would otherwise skip conversion entirely.
        $content = $this->convertCaptionShortcodes($content);

        if ($oldRelativePathToNewUrl === [] || !str_contains($content, self::UPLOADS_MARKER)) {
            return ['content' => $this->autoParagraph($content), 'warnings' => $warnings];
        }

        $dom = new DOMDocument();
        $previousInternalErrors = libxml_use_internal_errors(true);

        // LIBXML_HTML_NOIMPLIED/_NODEFDTD keep loadHTML() from wrapping the
        // fragment in an implied <html><body> that saveHTML() would re-emit.
        // mb_convert_encoding() to numeric entities avoids the usual
        // "prepend an <?xml encoding> PI" trick, which leaks in fragment mode.
        $loaded = @$dom->loadHTML(
            mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);

        if (!$loaded) {
            return ['content' => $content, 'warnings' => $warnings];
        }

        // Collected into an array first since getElementsByTagName() returns
        // a live NodeList — mutating an <img> mid-loop must not skip nodes.
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $image) {
            $this->rewriteImage($image, $oldRelativePathToNewUrl);
        }

        foreach (iterator_to_array($dom->getElementsByTagName('a')) as $link) {
            $this->rewriteLink($link, $oldRelativePathToNewUrl);
        }

        return [
            'content' => $this->autoParagraph($dom->saveHTML()),
            'warnings' => $warnings,
        ];
    }

    private function rewriteImage(DOMElement $image, array $oldRelativePathToNewUrl): void
    {
        $newUrl = $this->resolveNewUrl($image->getAttribute('src'), $oldRelativePathToNewUrl);

        if ($newUrl === null) {
            return;
        }

        $image->setAttribute('src', $newUrl);

        // srcset/sizes point at derivative-size files this import never
        // creates — stripped so the browser falls back to the full-size src.
        $image->removeAttribute('srcset');
        $image->removeAttribute('sizes');

        // width/height/title/alt are left untouched — they describe the
        // image itself, not the old site's URL structure.
        $this->stripWordPressOnlyClasses($image);
    }

    private function rewriteLink(DOMElement $link, array $oldRelativePathToNewUrl): void
    {
        // Only rewritten when it resolves to a real imported attachment;
        // an attachment-page-style URL that doesn't match is left as-is.
        $newUrl = $this->resolveNewUrl($link->getAttribute('href'), $oldRelativePathToNewUrl);

        if ($newUrl !== null) {
            $link->setAttribute('href', $newUrl);
        }
    }

    private function stripWordPressOnlyClasses(DOMElement $image): void
    {
        $classAttribute = $image->getAttribute('class');

        if ($classAttribute === '') {
            return;
        }

        $kept = array_values(array_filter(
            preg_split('/\s+/', trim($classAttribute)) ?: [],
            static fn (string $class): bool => $class !== ''
                && preg_match(self::ATTACHMENT_ID_CLASS_PATTERN, $class) !== 1
                && preg_match(self::SIZE_CLASS_PATTERN, $class) !== 1,
        ));

        if ($kept === []) {
            $image->removeAttribute('class');

            return;
        }

        $image->setAttribute('class', implode(' ', $kept));
    }

    /**
     * Matches an attachment's exact original path, and also that path with
     * a `-{width}x{height}` size suffix stripped — the common case for an
     * inline `<img src>`, which usually references a derivative size.
     *
     * @param array<string, string> $oldRelativePathToNewUrl
     */
    private function resolveNewUrl(string $url, array $oldRelativePathToNewUrl): ?string
    {
        if ($url === '' || !str_contains($url, self::UPLOADS_MARKER)) {
            return null;
        }

        $relativePath = substr(strstr($url, self::UPLOADS_MARKER), strlen(self::UPLOADS_MARKER));

        if (isset($oldRelativePathToNewUrl[$relativePath])) {
            return $oldRelativePathToNewUrl[$relativePath];
        }

        $withoutSizeSuffix = preg_replace('/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $relativePath);

        if ($withoutSizeSuffix !== null && $withoutSizeSuffix !== $relativePath && isset($oldRelativePathToNewUrl[$withoutSizeSuffix])) {
            return $oldRelativePathToNewUrl[$withoutSizeSuffix];
        }

        return null;
    }

    // A block-level tag autoParagraph() never wraps in <p> — it's already a
    // real block, or (figure/pre/script/style) content <br>/<p> would break.
    // <p> itself is included so re-running this never double-wraps content.
    private const BLOCK_LEVEL_TAG_PATTERN = '/^<(?:p|div|blockquote|ul|ol|li|table|thead|tbody|tfoot|tr|td|th|form|fieldset|h[1-6]|pre|script|style|select|address|hr|aside|article|section|header|footer|nav|figure|figcaption|video|audio|iframe)\b/i';

    /**
     * Classic WordPress stores post_content with no `<p>` wrapping —
     * `wpautop()` applies that at display time, not before saving — so this
     * reformats it the way Lumora Press's own ContentRenderer expects.
     * A deliberately simplified port of `wpautop()`, correct enough for a
     * one-time import rather than a hardened per-page-load filter.
     */
    private function autoParagraph(string $content): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $content));

        if ($normalized === '') {
            return $content;
        }

        $blocks = preg_split('/\n[ \t]*\n/', $normalized) ?: [$normalized];
        $paragraphs = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (preg_match(self::BLOCK_LEVEL_TAG_PATTERN, $block) === 1) {
                // Already real block markup — left as-is, no <br> conversion.
                $paragraphs[] = $block;

                continue;
            }

            $paragraphs[] = '<p>' . (preg_replace('/\n/', "<br />\n", $block) ?? $block) . '</p>';
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * WordPress's `[caption]` shortcode has no Lumora Press analog, so it's
     * converted to `<figure class="lp-caption {align}"><img>...<figcaption>`
     * (see default theme's matching `figure.lp-caption.align*` rules) —
     * as a plain string transform before the DOM pass, since DOMDocument
     * would otherwise see the brackets as ordinary text around the `<img>`.
     */
    private function convertCaptionShortcodes(string $content): string
    {
        return preg_replace_callback(
            '/\[caption\b([^\]]*)\](.*?)\[\/caption\]/s',
            static function (array $matches): string {
                $align = 'alignnone';

                if (preg_match('/\balign="([a-z]+)"/', $matches[1], $alignMatch) === 1) {
                    $align = $alignMatch[1];
                }

                $inner = trim($matches[2]);

                // The inner content is always the <img> tag, optionally
                // followed by plain caption text — never the reverse.
                if (preg_match('/^(<img\b[^>]*>)\s*(.*)$/s', $inner, $partsMatch) === 1) {
                    $imageTag = $partsMatch[1];
                    $captionText = trim($partsMatch[2]);
                } else {
                    // No <img> found — pass the content through unchanged rather than guessing.
                    $imageTag = $inner;
                    $captionText = '';
                }

                $figcaption = $captionText !== '' ? '<figcaption>' . $captionText . '</figcaption>' : '';

                return '<figure class="lp-caption ' . $align . '">' . $imageTag . $figcaption . '</figure>';
            },
            $content,
        ) ?? $content;
    }

    /**
     * `[gallery]`, `wp-block-gallery`, Jetpack's Tiled Gallery, and
     * `[slideshow]` all represent images through something other than a
     * single `<img src>` this class can resolve, so each is flagged.
     *
     * Not checked: a CSS `background-image: url(...)` (e.g. a Cover block) —
     * that markup commonly also emits a real `<img>` fallback already
     * resolved by the pass above, so flagging it would be a false positive.
     *
     * @return array<int, string>
     */
    private function unsupportedMediaConstructWarnings(string $originalContent): array
    {
        $warnings = [];

        if (preg_match('/\[gallery\b/', $originalContent) === 1 || str_contains($originalContent, 'wp-block-gallery')) {
            $warnings[] = 'still contains a WordPress image gallery (shortcode or block) — only plain <img> references are rewritten, so the gallery images may still point at the old site.';
        }

        if (str_contains($originalContent, 'tiled-gallery')) {
            $warnings[] = 'still contains a Jetpack Tiled Gallery block — only plain <img> references are rewritten, so its images may still point at the old site.';
        }

        if (preg_match('/\[slideshow\b/', $originalContent) === 1) {
            $warnings[] = 'still contains a Jetpack [slideshow] shortcode — only plain <img> references are rewritten, so its images may still point at the old site.';
        }

        return $warnings;
    }
}
