<?php

/**
 * Converts already-sanitized HTML back to Markdown, for switching a post/page between Markdown and WYSIWYG editing (LP-016).
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
 * Converts (already-sanitized) HTML back to Markdown, so a post/page can
 * be switched between Markdown and WYSIWYG editing. Only needs to
 * round-trip HtmlSanitizer::ALLOWED_TAGS's tag set — not a
 * general-purpose HTML-to-Markdown tool.
 */
final class HtmlToMarkdownConverter
{
    public function toMarkdown(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="lp-md-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $root = $dom->getElementById('lp-md-root');

        if ($root === null) {
            return '';
        }

        $markdown = $this->renderChildren($root);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    private function renderChildren(DOMNode $node): string
    {
        $out = '';

        foreach (iterator_to_array($node->childNodes) as $child) {
            $out .= $this->renderNode($child);
        }

        return $out;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return (string) $node->nodeValue;
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        $inner = $this->renderChildren($node);

        return match ($tag) {
            'h1' => "\n# " . trim($inner) . "\n\n",
            'h2' => "\n## " . trim($inner) . "\n\n",
            'h3' => "\n### " . trim($inner) . "\n\n",
            'h4' => "\n#### " . trim($inner) . "\n\n",
            'h5' => "\n##### " . trim($inner) . "\n\n",
            'h6' => "\n###### " . trim($inner) . "\n\n",
            'p', 'div' => "\n" . trim($inner) . "\n\n",
            'br' => "  \n",
            'hr' => "\n---\n\n",
            'strong', 'b' => '**' . trim($inner) . '**',
            'em', 'i' => '*' . trim($inner) . '*',
            'del', 's' => '~~' . trim($inner) . '~~',
            'u' => '++' . trim($inner) . '++',
            'span' => $this->renderSpan($node, $inner),
            'code' => str_contains($inner, "\n") ? $this->renderFencedCode($node, $inner) : '`' . $inner . '`',
            'pre' => $this->renderPre($node),
            'blockquote' => "\n" . $this->prefixLines(trim($inner), '> ') . "\n\n",
            'a' => '[' . trim($inner) . '](' . $node->getAttribute('href') . ')',
            'img' => '![' . $node->getAttribute('alt') . '](' . $node->getAttribute('src') . ')',
            'ul' => "\n" . $this->renderListItems($node, false) . "\n",
            'ol' => "\n" . $this->renderListItems($node, true) . "\n",
            'table' => "\n" . $this->renderTable($node) . "\n",
            'input' => '',
            default => $inner,
        };
    }

    /**
     * A `<span class="has-{color}-color">` round-trips to MarkdownParser's
     * `[text]{.color}` marker; only a recognized FONT_COLORS name
     * converts, otherwise it falls through to plain inner text.
     */
    private function renderSpan(DOMElement $span, string $inner): string
    {
        foreach (explode(' ', $span->getAttribute('class')) as $class) {
            if (str_starts_with($class, 'has-') && str_ends_with($class, '-color')) {
                $color = substr($class, 4, -6);

                if (in_array($color, MarkdownParser::FONT_COLORS, true)) {
                    return '[' . trim($inner) . ']{.' . $color . '}';
                }
            }
        }

        return $inner;
    }

    private function renderPre(DOMElement $pre): string
    {
        $codeChild = null;

        foreach ($pre->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'code') {
                $codeChild = $child;

                break;
            }
        }

        $lang = '';
        $text = $codeChild !== null ? $codeChild->textContent : $pre->textContent;

        if ($codeChild !== null) {
            foreach (explode(' ', $codeChild->getAttribute('class')) as $class) {
                if (str_starts_with($class, 'language-')) {
                    $lang = substr($class, strlen('language-'));
                }
            }
        }

        return "\n```" . $lang . "\n" . rtrim((string) $text, "\n") . "\n```\n\n";
    }

    private function renderFencedCode(DOMElement $code, string $inner): string
    {
        $lang = '';

        foreach (explode(' ', $code->getAttribute('class')) as $class) {
            if (str_starts_with($class, 'language-')) {
                $lang = substr($class, strlen('language-'));
            }
        }

        return "\n```" . $lang . "\n" . trim($inner, "\n") . "\n```\n\n";
    }

    private function renderListItems(DOMElement $list, bool $ordered): string
    {
        $out = '';
        $number = 1;

        foreach ($list->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $checkbox = null;

            foreach ($child->childNodes as $liChild) {
                if ($liChild instanceof DOMElement && strtolower($liChild->tagName) === 'input' && $liChild->getAttribute('type') === 'checkbox') {
                    $checkbox = $liChild;

                    break;
                }
            }

            $prefix = $ordered ? ($number . '. ') : '- ';

            if ($checkbox !== null) {
                $prefix .= $checkbox->hasAttribute('checked') ? '[x] ' : '[ ] ';
            }

            $text = trim($this->renderChildren($child));
            $lines = explode("\n", $text);
            $out .= $prefix . array_shift($lines) . "\n";

            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $out .= '  ' . $line . "\n";
                }
            }

            $number++;
        }

        return $out;
    }

    private function renderTable(DOMElement $table): string
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                    $cells[] = trim($this->renderChildren($cell));
                }
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $header = array_shift($rows);
        $out = '| ' . implode(' | ', $header) . " |\n";
        $out .= '| ' . implode(' | ', array_fill(0, count($header), '---')) . " |\n";

        foreach ($rows as $row) {
            $out .= '| ' . implode(' | ', $row) . " |\n";
        }

        return $out;
    }

    private function prefixLines(string $text, string $prefix): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $prefix . $line,
            explode("\n", $text),
        ));
    }
}
