<?php

/**
 * Converts Markdown source to HTML (LP-015).
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

/**
 * Converts Markdown source to HTML (LP-015). A hand-rolled, dependency-free
 * parser covering the subset of Markdown/GFM listed in TODO.md's LP-015
 * "Markdown Support" checklist — headings, paragraphs, bold, italic,
 * strikethrough, inline code, fenced code blocks, tables, blockquotes,
 * horizontal rules, ordered/unordered/task lists, links, images, and
 * footnotes — rather than a full CommonMark implementation. Lumora Press
 * avoids Composer/npm dependencies (see CLAUDE.md), so this is plain PHP
 * with no vendored library behind it.
 *
 * Deliberately does NOT pass raw inline HTML typed in Markdown source
 * through to the output — a `<script>` typed directly in the Markdown
 * textarea is escaped as literal text, not interpreted as a tag. This is a
 * narrower behaviour than CommonMark (which allows raw HTML), chosen so
 * this class's own output is inherently safe from anything the *author*
 * types; the structural HTML it generates (links, images, table
 * attributes) is still re-checked by HtmlSanitizer before being stored, as
 * defense in depth against a bug in this parser rather than against the
 * author's input.
 *
 * Two-pass design: parseBlocks() walks the source line-by-line into a
 * block tree (headings/paragraphs/lists/etc — the same lightweight
 * technique used by minimal Markdown parsers like Parsedown), then each
 * block's text content runs through parseInline() for span-level markup.
 * Code spans and images are protected behind placeholder tokens before the
 * rest of inline parsing runs, so their contents are never themselves
 * reprocessed as Markdown.
 */
final class MarkdownParser
{
    /** @var array<string, string> */
    private array $placeholders = [];

    private int $placeholderCounter = 0;

    /** @var array<string, string> raw footnote-id => rendered inline HTML */
    private array $footnoteDefinitions = [];

    /** @var array<int, string> footnote ids in first-reference order */
    private array $footnoteOrder = [];

    /** @var array<string, int> slug => count, for de-duplicating heading ids */
    private array $usedHeadingIds = [];

    /** @var array<int, array{level: int, text: string, id: string}> */
    private array $headings = [];

    public function toHtml(string $markdown): string
    {
        $this->placeholders = [];
        $this->placeholderCounter = 0;
        $this->footnoteDefinitions = [];
        $this->footnoteOrder = [];
        $this->usedHeadingIds = [];
        $this->headings = [];

        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);

        [$lines, $this->footnoteDefinitions] = $this->extractFootnoteDefinitions($lines);

        $blocks = $this->parseBlocks($lines);
        $html = $this->renderBlocks($blocks);

        $html = $this->renderTocPlaceholders($html);

        if ($this->footnoteOrder !== []) {
            $html .= $this->renderFootnotes();
        }

        return trim($html);
    }

    // ------------------------------------------------------------------
    // Block parsing
    // ------------------------------------------------------------------

    /**
     * @param array<int, string> $lines
     * @return array<int, array{type: string, lines?: array<int,string>, lang?: string, level?: int, items?: array<int, array{text: string, task: ?bool, children: array<int, array<string,mixed>>}>, ordered?: bool, rows?: array<int, array<int,string>>, align?: array<int,string>, header?: array<int,string>}>
     */
    private function parseBlocks(array $lines): array
    {
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;

                continue;
            }

            // [[toc]] marker
            if (preg_match('/^\s*\[\[\s*toc\s*\]\]\s*$/i', $line) === 1) {
                $blocks[] = ['type' => 'toc'];
                $i++;

                continue;
            }

            // Fenced code block
            if (preg_match('/^(\x60{3,}|~{3,})\s*([A-Za-z0-9_+-]*)\s*$/', $line, $m) === 1) {
                $fence = $m[1];
                $lang = $m[2];
                $codeLines = [];
                $i++;

                while ($i < $count && !preg_match('/^' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $lines[$i])) {
                    $codeLines[] = $lines[$i];
                    $i++;
                }

                $i++; // skip closing fence

                $blocks[] = ['type' => 'code', 'lines' => $codeLines, 'lang' => $lang];

                continue;
            }

            // ATX heading — a trailing {.left|center|right|justify}
            // marker (LP-016) sets its alignment; see stripAlignmentMarker()'s
            // docblock for why this is a trailing marker rather than an
            // attribute, the syntax Markdown otherwise has none of.
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m) === 1) {
                [$headingText, $headingAlign] = $this->stripAlignmentMarker($m[2]);
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'lines' => [$headingText], 'align' => $headingAlign];
                $i++;

                continue;
            }

            // Horizontal rule
            if (preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $line) === 1) {
                $blocks[] = ['type' => 'hr'];
                $i++;

                continue;
            }

            // Blockquote
            if (preg_match('/^ {0,3}>\s?(.*)$/', $line) === 1) {
                $quoteLines = [];

                while ($i < $count && preg_match('/^ {0,3}>\s?(.*)$/', $lines[$i], $m) === 1) {
                    $quoteLines[] = $m[1];
                    $i++;
                }

                $blocks[] = ['type' => 'blockquote', 'lines' => $quoteLines];

                continue;
            }

            // Table (header row + delimiter row)
            if (
                str_contains($line, '|')
                && isset($lines[$i + 1])
                && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1]) === 1
                && str_contains($lines[$i + 1], '-')
            ) {
                $header = $this->splitTableRow($line);
                $delimiters = $this->splitTableRow($lines[$i + 1]);
                $align = array_map(static function (string $cell): string {
                    $cell = trim($cell);

                    return match (true) {
                        str_starts_with($cell, ':') && str_ends_with($cell, ':') => 'center',
                        str_ends_with($cell, ':') => 'right',
                        str_starts_with($cell, ':') => 'left',
                        default => '',
                    };
                }, $delimiters);

                $i += 2;
                $rows = [];

                while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = $this->splitTableRow($lines[$i]);
                    $i++;
                }

                $blocks[] = ['type' => 'table', 'header' => $header, 'align' => $align, 'rows' => $rows];

                continue;
            }

            // List (ordered or unordered, including task list items)
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line) === 1) {
                [$listBlock, $consumed] = $this->parseList($lines, $i);
                $blocks[] = $listBlock;
                $i += $consumed;

                continue;
            }

            // Paragraph: gather consecutive non-blank lines that don't start a new block
            $paragraphLines = [$line];
            $i++;

            while (
                $i < $count
                && trim($lines[$i]) !== ''
                && preg_match('/^(#{1,6})\s+/', $lines[$i]) !== 1
                && preg_match('/^ {0,3}>\s?/', $lines[$i]) !== 1
                && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i]) !== 1
                && preg_match('/^(\x60{3,}|~{3,})/', $lines[$i]) !== 1
                && preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $lines[$i]) !== 1
            ) {
                $paragraphLines[] = $lines[$i];
                $i++;
            }

            // The alignment marker (LP-016), if present, is only ever
            // meaningful on the paragraph's own last line — a marker
            // elsewhere is just literal text the author typed.
            $lastLine = $paragraphLines[count($paragraphLines) - 1];
            [$paragraphLines[count($paragraphLines) - 1], $paragraphAlign] = $this->stripAlignmentMarker($lastLine);

            $blocks[] = ['type' => 'paragraph', 'lines' => $paragraphLines, 'align' => $paragraphAlign];
        }

        return $blocks;
    }

    /**
     * Strips a trailing `{.left}`/`{.center}`/`{.right}`/`{.justify}`
     * marker (LP-016) from $text, returning the cleaned text and the
     * matched alignment (or null if there was none). Markdown has no
     * native attribute syntax — this is a minimal, kramdown-inspired
     * convention (a trailing inline attribute list) rather than a
     * project-invented one, kept to the one thing this parser needs it
     * for: text alignment on a heading or paragraph, mirroring the
     * WYSIWYG editor's has-text-align-* classes (see
     * content-editor.js's TinyMCE `formats` config and
     * ContentRenderer/HtmlSanitizer, which never allows a `style`
     * attribute at all).
     *
     * @return array{0: string, 1: ?string}
     */
    private function stripAlignmentMarker(string $text): array
    {
        if (preg_match('/^(.*?)\s*\{\.(left|center|right|justify)\}\s*$/', $text, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [$text, null];
    }

    /**
     * @return array<int, string>
     */
    private function splitTableRow(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');
        $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(static fn (string $cell): string => trim(str_replace('\\|', '|', $cell)), $cells);
    }

    /**
     * Parses a run of list-item lines starting at $start into a nested list
     * block, using indentation to nest sub-lists (an item is nested under
     * the previous item once its marker is indented further). A blank
     * line followed by another list-item line at the same indent
     * continues the same list (a "loose" list, rendered the same as a
     * "tight" one here — no distinct wrapping-<p> behaviour, to keep the
     * renderer simple).
     *
     * @param array<int, string> $lines
     * @return array{0: array{type: string, ordered: bool, items: array<int, array{text: string, task: ?bool, children: array<int, array<string,mixed>>}>}, 1: int}
     */
    private function parseList(array $lines, int $start): array
    {
        $count = count($lines);
        $i = $start;
        $ordered = preg_match('/^\s*\d+[.)]\s+/', $lines[$start]) === 1;

        /** @var array<int, array{indent: int, text: string, task: ?bool, children: array<int, array<string,mixed>>}> $flatItems */
        $flatItems = [];

        while ($i < $count) {
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $m) !== 1) {
                if (trim($lines[$i]) === '' && isset($lines[$i + 1]) && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i + 1]) === 1) {
                    $i++;

                    continue;
                }

                break;
            }

            $indent = strlen($m[1]);
            $text = $m[3];
            $task = null;

            if (preg_match('/^\[( |x|X)\]\s+(.*)$/', $text, $taskMatch) === 1) {
                $task = strtolower($taskMatch[1]) === 'x';
                $text = $taskMatch[2];
            }

            $flatItems[] = ['indent' => $indent, 'text' => $text, 'task' => $task, 'children' => []];
            $i++;

            // Lazy continuation lines belonging to this item (indented
            // further than the marker, not themselves a new list item).
            while ($i < $count && trim($lines[$i]) !== '' && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i]) !== 1 && (strlen($lines[$i]) - strlen(ltrim($lines[$i]))) > $indent) {
                $flatItems[count($flatItems) - 1]['text'] .= ' ' . trim($lines[$i]);
                $i++;
            }
        }

        $items = $this->nestListItems($flatItems, 0, count($flatItems), null)[0];

        return [['type' => 'list', 'ordered' => $ordered, 'items' => $items], $i - $start];
    }

    /**
     * @param array<int, array{indent: int, text: string, task: ?bool, children: array<int, array<string,mixed>>}> $flat
     * @return array{0: array<int, array{text: string, task: ?bool, children: array<int, array<string,mixed>>}>, 1: int}
     */
    private function nestListItems(array $flat, int $index, int $count, ?int $parentIndent): array
    {
        $result = [];

        while ($index < $count) {
            $item = $flat[$index];

            if ($parentIndent !== null && $item['indent'] <= $parentIndent) {
                break;
            }

            $baseIndent = $item['indent'];
            $index++;
            $children = [];

            if ($index < $count && $flat[$index]['indent'] > $baseIndent) {
                [$children, $index] = $this->nestListItems($flat, $index, $count, $baseIndent);
            }

            $result[] = ['text' => $item['text'], 'task' => $item['task'], 'children' => $children];
        }

        return [$result, $index];
    }

    // ------------------------------------------------------------------
    // Block rendering
    // ------------------------------------------------------------------

    /**
     * @phpstan-impure populates $this->footnoteOrder/$this->headings as a
     *     side effect (via renderBlock()'s inline parsing) — without this
     *     tag PHPStan treats those properties as still holding their
     *     toHtml()-reset `[]` value at the point toHtml() checks them
     *     afterward, and flags that check as an always-false comparison.
     * @param array<int, array<string, mixed>> $blocks
     */
    private function renderBlocks(array $blocks): string
    {
        $html = '';

        foreach ($blocks as $block) {
            $html .= $this->renderBlock($block);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderBlock(array $block): string
    {
        return match ($block['type']) {
            'heading' => $this->renderHeading((int) $block['level'], (string) $block['lines'][0], is_string($block['align'] ?? null) ? $block['align'] : null),
            'paragraph' => '<p' . $this->alignmentClassAttr(is_string($block['align'] ?? null) ? $block['align'] : null) . '>' . $this->parseInline($this->joinParagraphLines((array) $block['lines'])) . "</p>\n",
            'code' => $this->renderCodeBlock((array) $block['lines'], (string) $block['lang']),
            'blockquote' => '<blockquote>' . $this->renderBlocks($this->parseBlocks((array) $block['lines'])) . "</blockquote>\n",
            'hr' => "<hr>\n",
            'list' => $this->renderList((array) $block['items'], (bool) $block['ordered']),
            'table' => $this->renderTable((array) $block['header'], (array) $block['align'], (array) $block['rows']),
            'toc' => "\x02TOC\x03\n",
            default => '',
        };
    }

    /**
     * @param array<int, string> $lines
     */
    private function joinParagraphLines(array $lines): string
    {
        $out = '';

        foreach ($lines as $index => $line) {
            $hardBreak = preg_match('/(  |\\\\)$/', $line) === 1;
            $out .= rtrim($line, " \t\\");

            if ($index < count($lines) - 1) {
                $out .= $hardBreak ? "\x04BR\x04" : ' ';
            }
        }

        return $out;
    }

    private function renderHeading(int $level, string $text, ?string $align = null): string
    {
        $inline = $this->parseInline($text);
        $id = $this->uniqueHeadingId($this->slugify($text));
        $this->headings[] = ['level' => $level, 'text' => $text, 'id' => $id];

        return "<h{$level} id=\"{$id}\"{$this->alignmentClassAttr($align)}>{$inline}</h{$level}>\n";
    }

    /**
     * Renders $align (one of 'left'/'center'/'right'/'justify', or null
     * for no marker) as a ` class="has-text-align-{align}"` attribute
     * fragment — the same class convention the WYSIWYG editor's
     * TinyMCE `formats` config applies (content-editor.js), so both
     * editors produce identical, interchangeable output.
     */
    private function alignmentClassAttr(?string $align): string
    {
        return $align !== null ? ' class="has-text-align-' . $align . '"' : '';
    }

    /**
     * @param array<int, string> $lines
     */
    private function renderCodeBlock(array $lines, string $lang): string
    {
        $code = htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $langClass = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"' : '';

        return "<pre><code{$langClass}>{$code}</code></pre>\n";
    }

    /**
     * @param array<int, array{text: string, task: ?bool, children: array<int, array<string,mixed>>}> $items
     */
    private function renderList(array $items, bool $ordered): string
    {
        $tag = $ordered ? 'ol' : 'ul';
        $html = "<{$tag}>\n";

        foreach ($items as $item) {
            $class = $item['task'] !== null ? ' class="lp-task-list-item"' : '';
            $html .= "<li{$class}>";

            if ($item['task'] !== null) {
                $checked = $item['task'] ? ' checked' : '';
                $html .= "<input type=\"checkbox\" disabled{$checked}> ";
            }

            $html .= $this->parseInline($item['text']);

            if ($item['children'] !== []) {
                $childOrdered = false;
                $html .= $this->renderList($item['children'], $childOrdered);
            }

            $html .= "</li>\n";
        }

        return $html . "</{$tag}>\n";
    }

    /**
     * @param array<int, string> $header
     * @param array<int, string> $align
     * @param array<int, array<int, string>> $rows
     */
    private function renderTable(array $header, array $align, array $rows): string
    {
        $alignClass = static fn (int $index): string => isset($align[$index]) && $align[$index] !== ''
            ? ' class="lp-align-' . $align[$index] . '"'
            : '';

        $html = "<table>\n<thead>\n<tr>\n";

        foreach ($header as $index => $cell) {
            $html .= '<th' . $alignClass($index) . '>' . $this->parseInline($cell) . "</th>\n";
        }

        $html .= "</tr>\n</thead>\n<tbody>\n";

        foreach ($rows as $row) {
            $html .= "<tr>\n";

            foreach ($row as $index => $cell) {
                $html .= '<td' . $alignClass($index) . '>' . $this->parseInline($cell) . "</td>\n";
            }

            $html .= "</tr>\n";
        }

        return $html . "</tbody>\n</table>\n";
    }

    private function uniqueHeadingId(string $slug): string
    {
        if ($slug === '') {
            $slug = 'section';
        }

        if (!isset($this->usedHeadingIds[$slug])) {
            $this->usedHeadingIds[$slug] = 1;

            return $slug;
        }

        $this->usedHeadingIds[$slug]++;

        return $slug . '-' . $this->usedHeadingIds[$slug];
    }

    private function slugify(string $text): string
    {
        $text = strtolower($this->stripInlineMarkers($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }

    private function stripInlineMarkers(string $text): string
    {
        return (string) preg_replace(['/[*_`~]/', '/\[([^\]]*)\]\([^)]*\)/'], ['', '$1'], $text);
    }

    /**
     * A flat list of every heading, one <li> per heading with a
     * "lp-toc__level-N" class carrying its original nesting depth —
     * deliberately not a visually-nested <ol><ol>...</ol></ol> tree
     * (correct nested-list HTML requires each child list to live inside
     * its parent <li>, which needs a real stack-based renderer; a flat
     * list avoids that complexity entirely while still linking to every
     * heading, and a theme can style the level classes as indentation if
     * it wants a nested look).
     */
    private function renderTocPlaceholders(string $html): string
    {
        if (!str_contains($html, "\x02TOC\x03") || $this->headings === []) {
            return str_replace("\x02TOC\x03\n", '', $html);
        }

        $toc = '<nav class="lp-toc" aria-label="Table of contents">' . "\n<ol>\n";

        foreach ($this->headings as $heading) {
            $label = htmlspecialchars($this->stripInlineMarkers($heading['text']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $toc .= '<li class="lp-toc__level-' . $heading['level'] . '"><a href="#' . $heading['id'] . '">' . $label . "</a></li>\n";
        }

        $toc .= "</ol>\n</nav>\n";

        return str_replace("\x02TOC\x03\n", $toc, $html);
    }

    // ------------------------------------------------------------------
    // Footnotes
    // ------------------------------------------------------------------

    /**
     * @param array<int, string> $lines
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    private function extractFootnoteDefinitions(array $lines): array
    {
        $definitions = [];
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\[\^([^\]]+)\]:\s?(.*)$/', $line, $m) === 1) {
                $definitions[$m[1]] = $m[2];

                continue;
            }

            $kept[] = $line;
        }

        return [$kept, $definitions];
    }

    private function renderFootnotes(): string
    {
        $html = '<section class="lp-footnotes">' . "\n<ol>\n";

        foreach ($this->footnoteOrder as $id) {
            $content = $this->footnoteDefinitions[$id] ?? '';
            $safeId = $this->slugify($id);
            $html .= '<li id="fn-' . $safeId . '">' . $this->parseInline($content)
                . ' <a href="#fnref-' . $safeId . '" class="lp-footnote-backref" aria-label="Back to reference">&#8617;</a></li>' . "\n";
        }

        return $html . "</ol>\n</section>\n";
    }

    // ------------------------------------------------------------------
    // Inline parsing
    // ------------------------------------------------------------------

    private function parseInline(string $text): string
    {
        $text = $this->protectCodeSpans($text);
        $text = $this->parseImages($text);
        $text = $this->parseLinks($text);
        $text = $this->parseAutolinks($text);
        $text = $this->escapeRemainingHtml($text);
        $text = $this->parseFootnoteReferences($text);
        $text = $this->parseBoldItalic($text);
        $text = $this->parseStrikethrough($text);
        $text = str_replace("\x04BR\x04", "<br>\n", $text);

        return $this->restorePlaceholders($text);
    }

    private function protectCodeSpans(string $text): string
    {
        return (string) preg_replace_callback('/(`+)(.+?)\1/', function (array $m): string {
            $code = htmlspecialchars(trim($m[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return $this->storePlaceholder('<code>' . $code . '</code>');
        }, $text);
    }

    /**
     * One or more trailing `{.alignleft}`/`{.aligncenter}`/`{.alignright}`/
     * `{.no-lightbox}` markers (LP-016/LP-080, mirrors
     * stripAlignmentMarker()'s heading/paragraph syntax) set the image's
     * class list — the same classic-WordPress alignment class names the
     * WYSIWYG editor's Insert Media "Alignment" step applies
     * (content-editor.js), so both editors produce identical,
     * interchangeable output. `alignnone` is deliberately not a marker
     * value: it's the unmarked default, the same "no class needed"
     * convention content/themes/default/style.css already documents.
     *
     * `no-lightbox` opts the image out of ContentRenderer::
     * addLightboxAttributes()'s automatic self-link (LP-080's "Link To:
     * None" fix — that setting used to only control *which* URL an image
     * links to, never whether it links at all, since every unlinked image
     * still got a self-link for the PhotoSwipe lightbox; content-editor.js
     * now appends this marker whenever "Link To: None" is chosen, so
     * "None" finally means no link at all).
     */
    private function parseImages(string $text): string
    {
        return (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(\s*(<[^>]*>|[^\s)]+)(?:\s+"([^"]*)")?\s*\)((?:\{\.(?:alignleft|aligncenter|alignright|no-lightbox)\})+)?/',
            function (array $m): string {
                $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $url = $this->sanitizeUrl(trim($m[2], '<>'));
                $titleAttr = isset($m[3]) && $m[3] !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"' : '';

                $classes = [];

                if (isset($m[4]) && $m[4] !== '' && preg_match_all('/\{\.([a-z-]+)\}/', $m[4], $classMatches) > 0) {
                    $classes = $classMatches[1];
                }

                $classAttr = $classes !== [] ? ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"' : '';

                return $this->storePlaceholder('<img src="' . $url . '" alt="' . $alt . '"' . $titleAttr . $classAttr . ' loading="lazy">');
            },
            $text,
        );
    }

    private function parseLinks(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\(\s*(<[^>]*>|[^\s)]+)(?:\s+"([^"]*)")?\s*\)/',
            function (array $m): string {
                $label = $this->parseBoldItalic($this->parseStrikethrough($this->escapeRemainingHtml($m[1])));
                $url = $this->sanitizeUrl(trim($m[2], '<>'));
                $titleAttr = isset($m[3]) && $m[3] !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"' : '';
                $rel = str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? ' rel="noopener noreferrer"' : '';

                return $this->storePlaceholder('<a href="' . $url . '"' . $titleAttr . $rel . '>' . $this->restorePlaceholders($label) . '</a>');
            },
            $text,
        );
    }

    private function parseAutolinks(string $text): string
    {
        return (string) preg_replace_callback('/<((?:https?):\/\/[^\s>]+)>/i', function (array $m): string {
            $url = $this->sanitizeUrl($m[1]);
            $label = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return $this->storePlaceholder('<a href="' . $url . '" rel="noopener noreferrer">' . $label . '</a>');
        }, $text);
    }

    private function parseFootnoteReferences(string $text): string
    {
        return (string) preg_replace_callback('/\[\^([^\]]+)\]/', function (array $m): string {
            $id = $m[1];

            if (!isset($this->footnoteDefinitions[$id])) {
                return $m[0];
            }

            if (!in_array($id, $this->footnoteOrder, true)) {
                $this->footnoteOrder[] = $id;
            }

            $number = array_search($id, $this->footnoteOrder, true) + 1;
            $safeId = $this->slugify($id);

            return '<sup id="fnref-' . $safeId . '"><a href="#fn-' . $safeId . '">' . $number . '</a></sup>';
        }, $text);
    }

    private function parseBoldItalic(string $text): string
    {
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(?=\S)(.+?)(?<=\S)__/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/s', '<em>$1</em>', $text);

        return $text;
    }

    private function parseStrikethrough(string $text): string
    {
        return (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);
    }

    private function escapeRemainingHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);
    }

    /**
     * Blocks javascript:/data:/vbscript: URIs — anything not http(s),
     * mailto, or a relative/absolute path is dropped to "#". Mirrors the
     * protocol allow-list HtmlSanitizer applies to raw-HTML-mode content,
     * so a link is never a viable script-execution vector either way it
     * was authored.
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        $decoded = strtolower((string) preg_replace('/[\x00-\x1F\s]+/', '', $url));

        // Scheme-relative "//host" resolves to an arbitrary external host
        // — reject before the general "starts with /" allowance below
        // would otherwise wave it through (mirrors HtmlSanitizer's
        // isSafeUrl(), which is the second, authoritative pass every
        // rendered Markdown link also goes through).
        if (str_starts_with($decoded, '//')) {
            return '#';
        }

        if (preg_match('/^(https?:|mailto:|#|\/|\.\.?\/)/i', $decoded) === 1 || !str_contains($decoded, ':')) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '#';
    }

    private function storePlaceholder(string $html): string
    {
        $key = "\x01" . $this->placeholderCounter . "\x01";
        $this->placeholderCounter++;
        $this->placeholders[$key] = $html;

        return $key;
    }

    private function restorePlaceholders(string $text): string
    {
        return strtr($text, $this->placeholders);
    }
}
