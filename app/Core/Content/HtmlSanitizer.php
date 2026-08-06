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
 * Allowlist HTML sanitizer (LP-015/LP-016) — the single XSS boundary for
 * everything ContentRenderer outputs, regardless of whether the HTML came
 * from MarkdownParser's own output, hand-typed "HTML mode" content, or
 * TinyMCE's client-submitted markup. All three are untrusted by the time
 * they reach here (MarkdownParser's output is regenerated from arbitrary
 * author input; raw HTML and TinyMCE output are literally arbitrary
 * client input) — so every allowed tag/attribute below is a deliberate,
 * reviewed choice, not a default.
 *
 * Built on DOMDocument rather than regex stripping: regex-based HTML
 * sanitization is a well-known source of bypasses (malformed/nested tags
 * confuse a regex but not a real parser). Parsing into a DOM, walking it,
 * and re-serializing only the allowed structure is the same approach
 * mature sanitizers (e.g. DOMPurify) use.
 */
final class HtmlSanitizer
{
    /** @var array<string, array<int, string>> tag => allowed attributes */
    private const ALLOWED_TAGS = [
        'p' => [],
        'br' => [],
        'hr' => [],
        'h1' => ['id'], 'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'h5' => ['id'], 'h6' => ['id'],
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
        'a' => ['href', 'title', 'rel', 'target', 'id', 'class', 'aria-label'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
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
