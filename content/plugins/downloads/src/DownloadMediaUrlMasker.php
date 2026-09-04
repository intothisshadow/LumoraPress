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
 * The shared editor embeds real `content/uploads/...` URLs with no notion
 * of Downloads-specific masking, so this runs as a second, Downloads-only
 * pass over the rendered HTML, rewriting known upload URLs to `/media/{id}/view`.
 * Runs after HtmlSanitizer — it only narrows an existing safe URL, so it
 * needs no sanitization pass of its own.
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
