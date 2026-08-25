<?php

/**
 * HTML-aware rewriting of imported WordPress content's own <img>/<a> references to point at the newly-imported local media (LPP-004).
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

        if ($oldRelativePathToNewUrl === [] || !str_contains($content, self::UPLOADS_MARKER)) {
            return ['content' => $content, 'warnings' => $warnings];
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
            'content' => $dom->saveHTML(),
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
