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
            'img' => $this->renderImage($node),
            'figure' => $this->renderFigure($node, $inner),
            'ul' => "\n" . $this->renderListItems($node, false) . "\n",
            'ol' => "\n" . $this->renderListItems($node, true) . "\n",
            'table' => "\n" . $this->renderTable($node) . "\n",
            'input' => '',
            default => $inner,
        };
    }

    /**
     * An image keeps its alignment/no-lightbox classes as MarkdownParser's
     * trailing `{.marker}`s; a caption rides in the title with `{.caption}`.
     * $align overrides the image's own alignment (a captioned figure's).
     */
    private function renderImage(DOMElement $img, ?string $caption = null, ?string $align = null): string
    {
        $classes = preg_split('/\s+/', $img->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $alignments = ['alignleft', 'aligncenter', 'alignright'];
        $markers = $align !== null ? [$align] : array_values(array_intersect($classes, $alignments));

        if (in_array('no-lightbox', $classes, true)) {
            $markers[] = 'no-lightbox';
        }

        $title = '';

        if ($caption !== null) {
            // A straight quote would end the Markdown title early.
            $title = ' "' . str_replace('"', "\u{201D}", $caption) . '"';
            $markers[] = 'caption';
        }

        return '![' . $img->getAttribute('alt') . '](' . $img->getAttribute('src') . $title . ')'
            . implode('', array_map(static fn (string $marker): string => '{.' . $marker . '}', $markers))
            . $this->sizeMarkers($img);
    }

    /**
     * Only an explicit size becomes a `{width=…}` / `{height=…}` marker: the
     * data-style-* copy, or a percentage attribute. The natural pixel width
     * and height an editor adds to every image are not a choice.
     */
    private function sizeMarkers(DOMElement $img): string
    {
        $markers = '';

        foreach (['width', 'height'] as $dimension) {
            $spec = $img->getAttribute('data-style-' . $dimension);

            if ($spec === '' || $spec === 'auto') {
                $attribute = $img->getAttribute($dimension);
                $spec = preg_match('/^[1-9][0-9]{0,3}%$/', $attribute) === 1 ? $attribute : '';
            }

            if (preg_match('/^[1-9][0-9]{0,3}(?:px|%)$/', $spec) === 1) {
                $markers .= '{' . $dimension . '=' . $spec . '}';
            }
        }

        return $markers;
    }

    /**
     * A captioned `<figure class="lp-caption">` (from either editor or the
     * WordPress Importer) becomes a standalone captioned image paragraph;
     * any other figure just keeps its contents.
     */
    private function renderFigure(DOMElement $figure, string $inner): string
    {
        $classes = preg_split('/\s+/', $figure->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $img = $figure->getElementsByTagName('img')->item(0);
        $captionElement = $figure->getElementsByTagName('figcaption')->item(0);

        if (!in_array('lp-caption', $classes, true) || !$img instanceof DOMElement || $captionElement === null) {
            return $inner;
        }

        $caption = trim((string) preg_replace('/\s+/u', ' ', $captionElement->textContent));

        if ($caption === '') {
            return $inner;
        }

        $align = array_values(array_intersect($classes, ['alignleft', 'aligncenter', 'alignright']))[0] ?? null;
        $image = $this->renderImage($img, $caption, $align);
        $link = $img->parentNode;

        if ($link instanceof DOMElement && strtolower($link->tagName) === 'a') {
            $image = '[' . $image . '](' . $link->getAttribute('href') . ')';
        }

        return "\n" . $image . "\n\n";
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
