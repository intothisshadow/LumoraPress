<?php

/**
 * HTML-aware rewriting of imported post/page content's own internal <a href> links to point at the corresponding newly-imported post/page.
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
 * A plain in-content `<a href>` is only a URL string with no structured
 * reference to resolve, and content can't be assumed to use the source
 * site's current permalink structure. This tries three structure-agnostic
 * ways to identify a link's target, in order of confidence:
 *
 *  1. A `?p=123` / `?page_id=123` query parameter — unambiguous.
 *  2. An exact match against the post/page's own `guid` column.
 *  3. The link's last path segment matched against a post/page slug —
 *     scoped to the source site's own domain (see $sourceHost) to reduce
 *     false positives from an unrelated but similarly-named path.
 *
 * Only post/page targets are resolved; category/tag/author archive links
 * are a deliberate scope boundary and are left as-is.
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
