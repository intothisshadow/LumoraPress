<?php

/**
 * Allowlist HTML sanitizer (LP-015/LP-016): the single XSS boundary for everything ContentRenderer outputs.
 *
 * @package LumoraPress
 * @subpackage Content
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allowlist HTML sanitizer — the single XSS boundary for everything
 * ContentRenderer outputs (Markdown output, hand-typed HTML, and TinyMCE
 * markup are all untrusted by the time they reach here). Built on
 * DOMDocument rather than regex stripping, since regex-based sanitization
 * is a well-known source of bypasses on malformed/nested tags.
 */
final class HtmlSanitizer
{
    /** @var array<string, array<int, string>> tag => allowed attributes */
    private const ALLOWED_TAGS = [
        // 'class' on 'p'/headings carries the WYSIWYG editor's text-alignment
        // classes — TinyMCE applies these instead of inline styles, since
        // this sanitizer never allows a 'style' attribute (an arbitrary-CSS
        // injection surface).
        'p' => ['class'],
        'br' => [],
        'hr' => [],
        'h1' => ['id', 'class'], 'h2' => ['id', 'class'], 'h3' => ['id', 'class'], 'h4' => ['id', 'class'], 'h5' => ['id', 'class'], 'h6' => ['id', 'class'],
        'strong' => [], 'b' => [],
        'em' => [], 'i' => [],
        'u' => [],
        'del' => [], 's' => [],
        'sup' => ['id'], 'sub' => [],
        'code' => ['class'],
        'pre' => [],
        'blockquote' => [],
        'ul' => ['class'], 'ol' => ['class'], 'li' => ['class', 'id'],
        'input' => ['type', 'disabled', 'checked'],
        // data-pswp-width/height/caption let the editor embed a linked
        // image's real dimensions at authoring time, since a "Link To:
        // Media File" insert can link to a size the inline <img> doesn't show.
        'a' => ['href', 'title', 'rel', 'target', 'id', 'class', 'aria-label', 'data-pswp-width', 'data-pswp-height', 'data-pswp-caption'],
        // 'class' carries the "no-lightbox" opt-out and "size-{name}" display classes.
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'class'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['class', 'scope'], 'td' => ['class'],
        'nav' => ['class', 'aria-label'],
        'section' => ['class'],
        'span' => ['class'],
        'div' => ['class'],
        'figure' => ['class'], 'figcaption' => [],
        'mark' => [],
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Tags a real browser would implicitly close a `<p>` for rather than
     * nest inside it. libxml2's HTML parser doesn't do this, so
     * repairNesting() below fixes the resulting malformed nesting
     * explicitly.
     *
     * @var array<int, string>
     */
    private const BLOCK_LEVEL_TAGS = [
        'p', 'div', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'blockquote', 'pre', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'section', 'nav', 'figure', 'figcaption', 'header', 'footer', 'form',
        'article', 'aside', 'details', 'summary', 'fieldset',
    ];

    /**
     * @param array<string, array<int, string>>|null $allowedTags override the default allowlist (tests only)
     */
    public function __construct(
        private readonly ?array $allowedTags = null,
    ) {
    }

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Wrapped in a root element + forced UTF-8 meta so loadHTML doesn't
        // mis-decode multibyte content or invent a <html><body> wrapper we
        // then need to strip differently across libxml versions.
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="lp-sanitize-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('lp-sanitize-root');

        if ($root === null) {
            return '';
        }

        $this->cleanNode($root, $dom);

        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Fixes invalid block-inside-`<p>` nesting without touching tags or
     * attributes — kept separate from clean()'s allowlist pass because it
     * also runs on content_html filter output (shortcode markup generated
     * after clean() already ran), which must survive verbatim.
     */
    public function repairNesting(string $html): string
    {
        if (!str_contains($html, '<p')) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="lp-repair-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('lp-repair-root');

        if ($root === null) {
            return $html;
        }

        $this->unnestBlocksFromParagraphs($root);
        $this->removeEmptyParagraphs($root);

        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Repeatedly finds a `<p>` containing an illegal block-level
     * descendant and hoists it out — one violation per pass, since
     * hoisting mutates the tree (a `<p>` can end up split into two, each
     * needing its own re-check for further violations).
     */
    private function unnestBlocksFromParagraphs(DOMElement $root): void
    {
        do {
            $hoisted = false;

            foreach (iterator_to_array($root->getElementsByTagName('p')) as $p) {
                if (!$p instanceof DOMElement) {
                    continue;
                }

                $block = $this->findBlockDescendant($p);

                if ($block === null) {
                    continue;
                }

                $this->hoistOutOfParagraph($p, $block);
                $hoisted = true;

                break;
            }
        } while ($hoisted);
    }

    /**
     * A `<div>` closes an already-open `<p>` in libxml2's parser but
     * leaves the now-empty `<p></p>` behind rather than dropping it, so it
     * needs its own cleanup pass.
     */
    private function removeEmptyParagraphs(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('p')) as $p) {
            if ($p instanceof DOMElement && !$p->hasChildNodes() && $p->parentNode !== null) {
                $p->parentNode->removeChild($p);
            }
        }
    }

    private function findBlockDescendant(DOMElement $element): ?DOMElement
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->tagName), self::BLOCK_LEVEL_TAGS, true)) {
                return $child;
            }

            $nested = $this->findBlockDescendant($child);

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * Splits $p (and, if $block is nested deeper than a direct child,
     * every inline wrapper between them) around $block, so $block ends
     * up a sibling of $p instead of its descendant — the same structural
     * repair a browser's HTML5 parser performs implicitly.
     */
    private function hoistOutOfParagraph(DOMElement $p, DOMElement $block): void
    {
        while ($block->parentNode !== $p) {
            $parent = $block->parentNode;

            if (!$parent instanceof DOMElement) {
                return;
            }

            $this->splitAroundChild($parent, $block);
        }

        $this->splitAroundChild($p, $block);
    }

    /**
     * Splits $element into "before"/"after" copies around $child, then
     * replaces $element with whichever of [before, child, after] actually
     * has content — an empty before/after is dropped rather than left as
     * a stray empty tag.
     */
    private function splitAroundChild(DOMElement $element, DOMNode $child): void
    {
        $parent = $element->parentNode;
        $dom = $element->ownerDocument;

        if ($parent === null || $dom === null) {
            return;
        }

        $before = $dom->createElement($element->tagName);
        $after = $dom->createElement($element->tagName);

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $before->setAttribute($attribute->nodeName, $attribute->nodeValue ?? '');
            $after->setAttribute($attribute->nodeName, $attribute->nodeValue ?? '');
        }

        $sawChild = false;

        foreach (iterator_to_array($element->childNodes) as $node) {
            if ($node === $child) {
                $sawChild = true;

                continue;
            }

            ($sawChild ? $after : $before)->appendChild($node);
        }

        if ($before->hasChildNodes()) {
            $parent->insertBefore($before, $element);
        }

        $parent->insertBefore($child, $element);

        if ($after->hasChildNodes()) {
            $parent->insertBefore($after, $element);
        }

        $parent->removeChild($element);
    }

    private function cleanNode(DOMNode $node, DOMDocument $dom): void
    {
        $allowedTags = $this->allowedTags ?? self::ALLOWED_TAGS;

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (!$child instanceof DOMElement) {
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->tagName);

            if (!array_key_exists($tag, $allowedTags)) {
                // Unwrap rather than delete: keep the (still-to-be-cleaned)
                // text/children of a disallowed wrapper like a stray <font>
                // or <script>'s surrounding <div> instead of losing content
                // a user actually wrote. <script>/<style> are the one
                // exception — their text content is the payload itself, so
                // unwrapping would still leak it into the page as text.
                if (in_array($tag, ['script', 'style'], true)) {
                    $node->removeChild($child);

                    continue;
                }

                $this->cleanNode($child, $dom);

                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            $this->cleanAttributes($child, $allowedTags[$tag]);
            $this->cleanNode($child, $dom);
        }
    }

    /**
     * @param array<int, string> $allowedAttributes
     */
    private function cleanAttributes(DOMElement $element, array $allowedAttributes): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (str_starts_with($name, 'on') || !in_array($name, $allowedAttributes, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (($name === 'href' || $name === 'src') && !$this->isSafeUrl($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (($name === 'data-pswp-width' || $name === 'data-pswp-height') && !preg_match('/^[1-9][0-9]*$/', $attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'target' && $attribute->nodeValue !== '_blank') {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // A target="_blank" link the author added must always carry
        // rel="noopener noreferrer" — the browser tab-nabbing protection
        // — regardless of whether the author remembered to include it.
        if ($element->tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        // Reject scheme-relative "//host/path" before the general "/"
        // check below would otherwise wave it through — it resolves to an
        // arbitrary external host, exactly what the "/" case is meant to
        // allow only a same-site path to do.
        if (str_starts_with($url, '//')) {
            return false;
        }

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, '.')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '') {
            // No scheme and doesn't start with #, /, or . — a bare
            // relative path (e.g. "page.html").
            return true;
        }

        return in_array($scheme, self::ALLOWED_URL_SCHEMES, true);
    }
}
