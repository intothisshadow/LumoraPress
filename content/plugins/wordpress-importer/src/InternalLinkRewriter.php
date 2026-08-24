<?php

/**
 * HTML-aware rewriting of imported post/page content's own internal <a href> links to point at the corresponding newly-imported post/page (LPP-004).
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
 * Unlike a WordPress menu item — which stores structured metadata
 * naming exactly which post/page/term it points at (see
 * WordPressImportService::resolveMenuItemTarget()) — a plain in-content
 * `<a href="...">` is only ever a URL string, with no structured
 * reference to resolve. Content can't be assumed to use the source
 * site's *current* permalink structure either (a link written years
 * before a structure change keeps whatever it was written with), so
 * this deliberately doesn't try to reconstruct the source's permalink
 * structure and pattern-match against it. Instead it tries three
 * independent, structure-agnostic ways to identify what a link was
 * really pointing at, in order of confidence:
 *
 *  1. A `?p=123` / `?page_id=123` query parameter — WordPress's own
 *     "Plain" permalink style, and also what its shortlink feature
 *     always emits regardless of the site's configured structure.
 *     Unambiguous: the numeric WordPress id is right there.
 *  2. An exact match against the post/page's own `guid` column —
 *     WordPress sets this once at creation and never updates it when
 *     the permalink structure later changes, so it doesn't reflect
 *     *today's* URLs but is still a real, stable identifier a link
 *     could have been copied from.
 *  3. The link's own last non-empty path segment, matched against a
 *     post/page's slug — works for the common pretty-permalink case
 *     (`/%postname%/`-style structures, including WordPress's default
 *     "Day and name"/"Month and name" presets) without needing to know
 *     which structure was actually in use. The one deliberate
 *     imprecision here: a slug collision between an unrelated path
 *     segment and a real post/page slug is possible in principle, so
 *     this is scoped to links pointing at the source site's own domain
 *     (see $sourceHost) to reduce false positives — an external link
 *     that happens to end in a matching slug is never touched.
 *
 * Only post/page targets are resolved — category/tag archive links and
 * author archive links are a deliberate scope boundary for this first
 * pass (see TODO-PLUGINS.md's own note on why) and are left as-is,
 * same as any other link this class doesn't recognize.
 */
final class InternalLinkRewriter
{
    /**
     * @param array<int, string> $postIdToNewUrl wpPostId => new post URL
     * @param array<string, string> $postSlugToNewUrl wp post_name => new post URL
     * @param array<string, string> $postGuidToNewUrl wp guid => new post URL
     * @param array<int, string> $pageIdToNewUrl wpPageId => new page URL
     * @param array<string, string> $pageSlugToNewUrl wp post_name => new page URL
     * @param array<string, string> $pageGuidToNewUrl wp guid => new page URL
     */
    public function __construct(
        private readonly string $sourceHost,
        private readonly array $postIdToNewUrl,
        private readonly array $postSlugToNewUrl,
        private readonly array $postGuidToNewUrl,
        private readonly array $pageIdToNewUrl,
        private readonly array $pageSlugToNewUrl,
        private readonly array $pageGuidToNewUrl,
    ) {
    }

    /**
     * @return array{content: string, changed: bool}
     */
    public function rewrite(string $content): array
    {
        if (trim($content) === '' || !str_contains($content, '<a')) {
            return ['content' => $content, 'changed' => false];
        }

        $dom = new DOMDocument();
        $previousInternalErrors = libxml_use_internal_errors(true);

        // See ContentImageRewriter's identical loadHTML() call for why
        // these flags and the HTML-ENTITIES conversion are used together.
        $loaded = @$dom->loadHTML(
            mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);

        if (!$loaded) {
            return ['content' => $content, 'changed' => false];
        }

        $changed = false;

        foreach (iterator_to_array($dom->getElementsByTagName('a')) as $link) {
            if ($this->rewriteLink($link)) {
                $changed = true;
            }
        }

        if (!$changed) {
            return ['content' => $content, 'changed' => false];
        }

        return ['content' => $dom->saveHTML(), 'changed' => true];
    }

    private function rewriteLink(DOMElement $link): bool
    {
        $href = $link->getAttribute('href');
        $newUrl = $this->resolveNewUrl($href);

        if ($newUrl === null || $newUrl === $href) {
            return false;
        }

        $link->setAttribute('href', $newUrl);

        return true;
    }

    private function resolveNewUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        $parts = parse_url($href);

        if ($parts === false) {
            return null;
        }

        // A relative link (no host at all) is inherently internal — a
        // link with a real host must match the source site's own to be
        // treated as internal, so an external link sharing a query
        // param name or a path segment is never touched.
        $host = $parts['host'] ?? null;

        if ($host !== null && $this->sourceHost !== '' && strcasecmp($host, $this->sourceHost) !== 0) {
            return null;
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            if (isset($query['p']) && is_string($query['p']) && isset($this->postIdToNewUrl[(int) $query['p']])) {
                return $this->postIdToNewUrl[(int) $query['p']];
            }

            if (isset($query['page_id']) && is_string($query['page_id']) && isset($this->pageIdToNewUrl[(int) $query['page_id']])) {
                return $this->pageIdToNewUrl[(int) $query['page_id']];
            }
        }

        if (isset($this->postGuidToNewUrl[$href])) {
            return $this->postGuidToNewUrl[$href];
        }

        if (isset($this->pageGuidToNewUrl[$href])) {
            return $this->pageGuidToNewUrl[$href];
        }

        $path = $parts['path'] ?? '';
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        $slug = rawurldecode(end($segments));

        return $this->postSlugToNewUrl[$slug] ?? $this->pageSlugToNewUrl[$slug] ?? null;
    }
}
