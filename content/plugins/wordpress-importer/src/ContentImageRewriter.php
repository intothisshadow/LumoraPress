<?php

/**
 * HTML-aware rewriting of imported WordPress content's own <img>/<a> references to point at the newly-imported local media, and conversion of the [caption] shortcode to a real <figure>/<figcaption> (LPP-004).
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
 * Replaces WordPressImportService's original plain string search/replace
 * over each attachment's full-size guid URL with a real DOM pass — the
 * first pass only ever matched the exact full-size URL, so the far more
 * common case of an inline `<img>` referencing a WordPress-generated
 * derivative filename (`cover-300x200.jpg`) was left pointing at the old
 * site entirely. This class is stateless and has no dependency on the
 * database or any service — it only ever transforms one already-fetched
 * content string using a lookup table the caller already built while
 * importing media, so `WordPressImportService` constructs a fresh
 * instance directly rather than taking it as a constructor dependency.
 */
final class ContentImageRewriter
{
    private const UPLOADS_MARKER = '/wp-content/uploads/';

    /**
     * WordPress's own internal attachment-id class (`wp-image-123`) — the
     * id it encodes is meaningless once the attachment becomes a
     * different Lumora Press media id, so it's dropped rather than kept
     * as dead metadata.
     */
    private const ATTACHMENT_ID_CLASS_PATTERN = '/^wp-image-\d+$/';

    /**
     * WordPress's own built-in size-slug classes (`size-thumbnail`,
     * `size-medium`, `size-large`, `size-full`, and any custom size a
     * theme/plugin registered) — meaningless here since this import
     * never generates the derivative sizes those classes refer to.
     * Alignment classes (`alignleft`/`alignright`/`aligncenter`/
     * `alignnone`) are deliberately *not* stripped or remapped: the
     * default theme's own WYSIWYG editor already outputs and styles
     * those exact class names (see style.css's own "LP-016's image
     * alignment" rule), so they carry over as a real equivalent rather
     * than dead WordPress-only metadata.
     */
    private const SIZE_CLASS_PATTERN = '/^size-[a-z0-9_-]+$/i';

    /**
     * @param array<string, string> $oldRelativePathToNewUrl the exact
     *        `_wp_attached_file` relative path WordPressImportService
     *        recorded for every imported attachment (e.g.
     *        "2020/03/cover.png") mapped to its new Lumora Press media
     *        URL — see WordPressImportService::importMedia()
     * @return array{content: string, warnings: array<int, string>}
     */
    public function rewrite(string $content, array $oldRelativePathToNewUrl): array
    {
        // Cheap string checks, run unconditionally — a post whose only
        // image content is a gallery/tiled-gallery/slideshow construct
        // (no other real <img>/<a> uploads reference anywhere in the
        // same content) must still be flagged, even though there's
        // nothing here for the DOM pass below to actually rewrite.
        $warnings = $this->unsupportedMediaConstructWarnings($content);

        // Unlike the gallery/slideshow constructs above, WordPress core's
        // own [caption] shortcode has a real Lumora Press equivalent (a
        // <figure>/<figcaption>), so it's converted here rather than just
        // flagged — before the UPLOADS_MARKER short-circuit below, since a
        // caption with no real upload-path image inside it (rare, but
        // possible for an externally hosted image) would otherwise skip
        // conversion entirely.
        $content = $this->convertCaptionShortcodes($content);

        if ($oldRelativePathToNewUrl === [] || !str_contains($content, self::UPLOADS_MARKER)) {
            return ['content' => $this->autoParagraph($content), 'warnings' => $warnings];
        }

        $dom = new DOMDocument();
        $previousInternalErrors = libxml_use_internal_errors(true);

        // LIBXML_HTML_NOIMPLIED/_NODEFDTD keep loadHTML() from wrapping
        // the fragment in an implied <html><body> (and a default
        // doctype) that saveHTML() would then also emit back out —
        // real post/page content is a bare fragment, not a full
        // document. mb_convert_encoding() to numeric HTML entities
        // avoids the more common "prepend an <?xml encoding> processing
        // instruction" trick for forcing UTF-8, which otherwise leaks
        // that literal PI into the saved output in fragment mode.
        $loaded = @$dom->loadHTML(
            mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);

        if (!$loaded) {
            return ['content' => $content, 'warnings' => $warnings];
        }

        // getElementsByTagName() returns a live NodeList — collecting it
        // into a plain array first means mutating one <img>'s attributes
        // mid-loop can't ever perturb which nodes the loop still has left
        // to visit.
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

        // srcset/sizes point at WordPress-generated derivative-size
        // files this import never creates — stripped rather than left
        // dangling at the old site, so the browser falls back to the
        // full-size src above (Lumora Press's own thumbnail generation,
        // if any, isn't retrofitted onto already-imported content).
        $image->removeAttribute('srcset');
        $image->removeAttribute('sizes');

        // width/height/title/alt are deliberately left untouched: they
        // describe the image itself, not the old site's URL structure,
        // and stay accurate (a stale width/height just means the
        // full-size image now displays at its old thumbnail's size,
        // which is a legitimate display size, not a broken one).
        $this->stripWordPressOnlyClasses($image);
    }

    private function rewriteLink(DOMElement $link, array $oldRelativePathToNewUrl): void
    {
        // WordPress's own "link to media file" / "link to attachment
        // page" image options both point an <a href> at some form of the
        // same upload path — only rewritten when it resolves to a real
        // imported attachment; an attachment-page-style URL that doesn't
        // match anything here is left as-is (already pointing at the old
        // site regardless of what this import does).
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
     * Matches both an attachment's exact original path (a "link to media
     * file"/plain embed, or the featured-image-style full-size src) and
     * that same path with a WordPress-generated `-{width}x{height}`
     * size suffix stripped (the far more common case for an inline
     * `<img src>`, which almost always references a specific derivative
     * size rather than the full original).
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

    /**
     * WordPress's own `[caption id="attachment_108" align="alignnone"
     * width="350"]<img .../> Caption text[/caption]` shortcode (the
     * classic editor's output whenever an inserted image has a caption)
     * has no analog in Lumora Press's own content model, so left
     * unconverted it shows as literal bracket text around (and after) the
     * image — found on a real production import (xenacentral.com).
     * Converted here into `<figure class="lp-caption {align}"><img
     * .../><figcaption>Caption text</figcaption></figure>` — `align`
     * (`alignleft`/`alignright`/`aligncenter`/`alignnone`) carries over
     * as a real class the same way ContentImageRewriter already keeps it
     * on a plain `<img>` (see stripWordPressOnlyClasses()'s own
     * docblock); `content/themes/default/style.css` has the matching
     * `figure.lp-caption.align*` rules. The `id`/`width` attributes are
     * dropped — `id="attachment_108"` is a WordPress-internal reference
     * meaningless once the attachment becomes a different Lumora Press
     * media id (same reasoning `ATTACHMENT_ID_CLASS_PATTERN` already
     * applies to the `wp-image-123` class), and `width` describes the
     * old site's generated derivative size, not this image's real one.
     *
     * Runs as a plain string transform *before* the DOM pass in
     * rewrite() — DOMDocument would otherwise just see `[caption ...]`/
     * `[/caption]` as ordinary text nodes either side of a real `<img>`
     * element and pass them through untouched, so this has to happen
     * first, on the raw string, for the DOM pass to ever see a real
     * `<figure>` wrapper to work with.
     */
    /**
     * A block-level HTML tag `autoParagraph()` never wraps in a `<p>` —
     * it's either already its own real block (a list, table, heading,
     * etc.) or, for `<figure>`/`<pre>`/`<script>`/`<style>`, content
     * where inserting `<br />` line breaks or nesting a `<p>` around it
     * would actually break it. `<p>` itself is included so re-running
     * this against content some *other* pass already paragraphed
     * (e.g. a page that mixes real `<p>` blocks with loose plain-text
     * ones) never double-wraps the parts that already have it.
     */
    private const BLOCK_LEVEL_TAG_PATTERN = '/^<(?:p|div|blockquote|ul|ol|li|table|thead|tbody|tfoot|tr|td|th|form|fieldset|h[1-6]|pre|script|style|select|address|hr|aside|article|section|header|footer|nav|figure|figcaption|video|audio|iframe)\b/i';

    /**
     * Classic WordPress never stores a real `<p>`-wrapped post_content —
     * `wpautop()` (WordPress core, `wp-includes/formatting.php`) applies
     * that formatting as a *display*-time filter on `the_content`, not
     * before saving, so the raw content this importer reads is exactly
     * what the author typed: paragraphs separated by a blank line, a
     * single line break meant as a soft `<br>`, with no block markup
     * around any of it. Lumora Press's own `ContentRenderer` applies no
     * equivalent filter for HTML-format content (its own WYSIWYG editor
     * always saves real `<p>` tags to begin with, so there was never a
     * need for one) — left alone, every such paragraph/line break is
     * silently lost, collapsing the whole post into one run-on block.
     * Found on a real production import (xenacentral.com), affecting any
     * post/page authored without explicit HTML paragraph tags — likely
     * a large share of both WordPress-sourced sites already imported
     * this same session, not just the one page that surfaced it.
     *
     * A deliberately simplified port, not a byte-for-byte reimplementation
     * of `wpautop()`'s own considerably more involved regex (shortcode
     * un-autop rules, `<pre>` protection during the split itself, etc.)
     * — this only needs to be "correct for real migrated content," a
     * one-time import step, not a hardened rendering filter run on every
     * page load the way WordPress's own version is.
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
                // Already real block markup (or a shortcode-produced
                // <figure> from convertCaptionShortcodes(), which runs
                // before this) — left exactly as-is, no <br> conversion,
                // since a single newline *inside* e.g. a <ul> is just
                // insignificant whitespace between <li> elements, not a
                // meaningful line break the way it is in loose text.
                $paragraphs[] = $block;

                continue;
            }

            $paragraphs[] = '<p>' . (preg_replace('/\n/', "<br />\n", $block) ?? $block) . '</p>';
        }

        return implode("\n\n", $paragraphs);
    }

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

                // The shortcode's inner content is always the <img> tag
                // itself, optionally followed by plain caption text (which
                // may itself contain simple inline HTML, e.g. a link) —
                // never the other way around, per WordPress's own
                // img_caption_shortcode() implementation.
                if (preg_match('/^(<img\b[^>]*>)\s*(.*)$/s', $inner, $partsMatch) === 1) {
                    $imageTag = $partsMatch[1];
                    $captionText = trim($partsMatch[2]);
                } else {
                    // No <img> found (e.g. a caption wrapping something
                    // other than an image) — pass the inner content
                    // through unchanged rather than guessing at a split.
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
     * WordPress's classic `[gallery]` shortcode, the block editor's
     * `wp-block-gallery` markup, Jetpack's Tiled Gallery block
     * (`tiled-gallery` — its own shortcode form, `[gallery
     * type="rectangular"]`, is already caught by the plain `[gallery`
     * check above, since it still starts with that same literal text),
     * and Jetpack's `[slideshow]` shortcode all represent a set of
     * images through something other than a single `<img src>` this
     * class can resolve — each flagged once per affected item (the
     * caller prefixes this with the post/page's own identity, matching
     * flagUnsupportedShortcodes()'s existing convention) rather than
     * silently leaving them pointing at nothing.
     *
     * Deliberately *not* checked here: a CSS `background-image: url(...)`
     * referencing `/wp-content/uploads/` (e.g. a Cover block) — real
     * WordPress Cover block output commonly emits *both* a background-
     * image style *and* a real `<img>` fallback for the same image, so
     * flagging every background-image occurrence would warn on an
     * image that was often already correctly resolved via the `<img>`
     * pass above, not genuinely left broken. None of these four
     * (including the two above) were found in the real production
     * database this ticket's other work was verified against — this
     * list is scoped from well-documented, stable WordPress/Jetpack
     * markup conventions, not confirmed production data, unlike a
     * private plugin's own database schema (see e.g.
     * WordPressSource::nextGenAlbums()'s own docblock for why that
     * distinction matters here).
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
