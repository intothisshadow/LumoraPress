<?php

/**
 * The one place a post/page's raw stored content becomes safe, final HTML (LP-015/LP-016).
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Content\HtmlSanitizer;
use LumoraPress\Core\Content\HtmlToMarkdownConverter;
use LumoraPress\Core\Content\MarkdownParser;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Models\ContentFormat;

/**
 * The one place a post/page's raw stored `content` becomes safe, final
 * HTML (LP-015/LP-016) — branches on ContentFormat:
 *
 *   Markdown -> MarkdownParser::toHtml() -> HtmlSanitizer::clean()
 *   Html     -> HtmlSanitizer::clean() directly (hand-typed or TinyMCE
 *               output — both are untrusted client input by the time
 *               they're stored, same as Markdown's generated HTML)
 *   Plain    -> nl2br(escaped text) — the exact pre-LP-015 behaviour,
 *               preserved for rows that predate this column
 *
 * Every branch ends in HtmlSanitizer, so there is exactly one XSS
 * boundary for all three formats rather than one per editor.
 */
final class ContentRenderer
{
    public function __construct(
        private readonly MarkdownParser $markdown,
        private readonly HtmlSanitizer $sanitizer,
        private readonly HookManager $hooks,
        private readonly HtmlToMarkdownConverter $htmlToMarkdown = new HtmlToMarkdownConverter(),
    ) {
    }

    public function render(string $content, ContentFormat $format): string
    {
        $html = match ($format) {
            ContentFormat::Markdown => $this->sanitizer->clean($this->hooks->applyFilters('markdown_html', $this->markdown->toHtml($content), $content)),
            ContentFormat::Html => $this->sanitizer->clean($this->hooks->applyFilters('wysiwyg_html', $content)),
            ContentFormat::Plain => nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        };

        return $this->hooks->applyFilters('content_html', $html, $content, $format);
    }

    /**
     * Best-effort conversion when an author switches a post/page's editor
     * format in the admin UI (LP-016's "Switch between Markdown and
     * WYSIWYG" / "Import existing Markdown" / "Export clean Markdown
     * where possible"). Only meaningfully converts between Markdown and
     * Html — Plain has no structure to convert from/to, so it's always
     * returned unchanged; going TO Plain from either format also returns
     * the source unchanged; deliberately not stripped, since "plain
     * text" here means "stop interpreting formatting," not "discard
     * content" (an author switching Html -> Plain by mistake shouldn't
     * lose visible content). Only ever needs to round-trip
     * HtmlSanitizer::ALLOWED_TAGS's tag set, since that's the only HTML
     * this application ever stores in the first place.
     */
    public function convertFormat(string $content, ContentFormat $from, ContentFormat $to): string
    {
        if ($from === $to || $to === ContentFormat::Plain || $from === ContentFormat::Plain) {
            return $content;
        }

        // $to is never Plain here — guarded above — so only the Html and
        // Markdown conversion directions remain.
        return $to === ContentFormat::Html
            ? $this->sanitizer->clean($this->markdown->toHtml($content))
            : $this->htmlToMarkdown->toMarkdown($this->sanitizer->clean($content));
    }

    /**
     * Plain-text rendering for excerpts/search snippets/OG descriptions —
     * strips the rendered HTML back down to text rather than
     * strip_tags()-ing the raw Markdown/HTML source directly, so a
     * Markdown excerpt never leaks literal "**bold**"/"# heading" syntax
     * (see include/helpers.php's make_excerpt(), which this feeds).
     */
    public function toPlainText(string $content, ContentFormat $format): string
    {
        if ($format === ContentFormat::Plain) {
            return $content;
        }

        return strip_tags($this->render($content, $format));
    }
}
