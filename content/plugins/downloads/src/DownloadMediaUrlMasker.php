<?php

/**
 * Rewrites a Download's rendered Description HTML so embedded image URLs point at the masked view endpoint instead of the real upload path.
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

namespace LumoraPress\Plugins\Downloads;

use DOMDocument;
use DOMElement;
use LumoraPress\Services\MediaService;

/**
 * LPP-013: a Download's Description field is free-text content (Markdown/
 * HTML/Plain, LPP-010) rendered through the same shared ContentRenderer
 * every post/page uses — content-editor.js's Insert Image button embeds
 * the real `content/uploads/...` URL directly, with no notion of
 * Downloads-specific masking, so the leak survives ContentRenderer::
 * render() unchanged. Rather than teaching the shared editor about a
 * single plugin's masking needs (it's used by posts/pages too, and this
 * ticket's scope is Downloads only — see LPP-013's own scope decision),
 * this runs as a second, Downloads-only pass over the already-rendered
 * HTML: any `<img src>`/`<a href>` pointing at a real upload path is
 * resolved back to its Media row (MediaService::findByFilePath()) and
 * rewritten to `/media/{id}/view` when one is found. A URL that doesn't
 * match a known Media row (external image, already-masked link, plain
 * text link) is left untouched.
 *
 * Runs after ContentRenderer::addLightboxAttributes() has already
 * self-linked a plain `<img>` (see that method's docblock) and after
 * HtmlSanitizer has already run — this only ever narrows an existing,
 * already-safe `src`/`href` value to a different same-origin URL, never
 * introduces new markup, so it needs no sanitization pass of its own.
 */
final class DownloadMediaUrlMasker
{
    private const MASKABLE_ATTRIBUTES = ['img' => 'src', 'a' => 'href'];

    public function __construct(
        private readonly MediaService $media,
        private readonly string $uploadsUrl,
    ) {
    }

    public function mask(string $html): string
    {
        if (!str_contains($html, $this->uploadsUrlPrefix())) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="lp-download-mask-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('lp-download-mask-root');

        if ($root === null) {
            return $html;
        }

        $changed = false;

        foreach (self::MASKABLE_ATTRIBUTES as $tag => $attribute) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $element) {
                if (!$element instanceof DOMElement || !$element->hasAttribute($attribute)) {
                    continue;
                }

                $masked = $this->maskedUrl($element->getAttribute($attribute));

                if ($masked === null) {
                    continue;
                }

                $element->setAttribute($attribute, $masked);
                $changed = true;
            }
        }

        if (!$changed) {
            return $html;
        }

        $innerHtml = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $innerHtml .= (string) $dom->saveHTML($child);
        }

        return $innerHtml;
    }

    private function maskedUrl(string $url): ?string
    {
        $prefix = $this->uploadsUrlPrefix();

        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $relativePath = substr($url, strlen($prefix));
        $media = $this->media->findByFilePath($relativePath);

        if ($media === null || !str_starts_with((string) $media['mime_type'], 'image/')) {
            return null;
        }

        return site_url('media/' . $media['id'] . '/view');
    }

    private function uploadsUrlPrefix(): string
    {
        return rtrim($this->uploadsUrl, '/') . '/';
    }
}
