<?php

/**
 * Auto-embed: expands a bare provider URL alone on its own line into an embedded player, tweet, or post.
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

use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Theme\ScriptEmbeds;

/**
 * Auto-embed: expands a bare provider URL, alone on its own line, into an embedded iframe
 * when a post/page renders — classic WordPress auto-embed behavior. Hooked to
 * ContentRenderer's 'content_html' filter, after HtmlSanitizer::clean(); every tag it emits
 * is a fixed hardcoded-per-provider template, never sanitizer-passed user markup.
 *
 * Uses a fixed, developer-maintained provider allowlist with regex-based ID extraction, not
 * the oEmbed HTTP discovery protocol, to avoid an SSRF surface. No network request is ever
 * made here — Bluesky's id is resolved ahead of time by BlueskyResolverService at save time;
 * matchBluesky() only reads its local cache. Comment content never reaches render().
 */
final class EmbedService
{
    private const OPTION_KEY = 'embed_settings';

    private const DEFAULT_PROVIDERS = ['youtube', 'vimeo', 'soundcloud', 'spotify', 'codepen', 'twitter', 'bluesky'];

    /**
     * Matches a bare URL as the entire content of a paragraph — the shape
     * a Markdown/HTML-format post gets when an author pastes a link on
     * its own line with no other text (MarkdownParser only autolinks a
     * bare URL when it's wrapped in `<...>`; TinyMCE has no 'autolink'
     * plugin enabled — see admin/assets/js/content-editor.js — so a bare
     * paste normally stays as plain text, not an `<a>`). The `<a href>`
     * alternative is a defensive fallback for a URL that did get
     * autolinked some other way.
     */
    private const PARAGRAPH_PATTERN = '#<p>\s*(?:<a\b[^>]*\bhref="([^"]*)"[^>]*>[^<]*</a>|(https?://[^\s<]+))\s*</p>#i';

    /**
     * Matches a bare URL as an entire line in Plain-format content, which
     * has no `<p>` tags at all — ContentRenderer::render() produces
     * `nl2br(htmlspecialchars($content))` for Plain, so lines are
     * delimited by `<br>` (or the very start/end of the string).
     */
    private const LINE_PATTERN = '#(^|<br\s*/?>)\s*(https?://[^\s<]+)\s*(?=<br\s*/?>|$)#i';

    /** @var array{enabled: bool, providers: array<string, bool>, max_width: string}|null */
    private ?array $settingsCache = null;

    public function __construct(
        private readonly PressConfig $config,
        private readonly HookManager $hooks,
        /**
         * Nullable so existing test call sites keep compiling. Null only
         * disables the Bluesky provider's match(); every other provider
         * is unaffected.
         */
        private readonly ?BlueskyResolverService $bluesky = null,
    ) {
    }

    /**
     * @return array{enabled: bool, providers: array<string, bool>, max_width: string}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = $this->config->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $decoded = is_array($decoded) ? $decoded : [];

        $storedProviders = isset($decoded['providers']) && is_array($decoded['providers']) ? $decoded['providers'] : [];
        $providers = [];

        foreach (self::DEFAULT_PROVIDERS as $key) {
            $providers[$key] = !isset($storedProviders[$key]) || (bool) $storedProviders[$key];
        }

        $settings = [
            'enabled' => !isset($decoded['enabled']) || (bool) $decoded['enabled'],
            'providers' => $providers,
            'max_width' => isset($decoded['max_width']) ? trim((string) $decoded['max_width']) : '640px',
        ];

        $this->settingsCache = $settings;

        return $settings;
    }

    /**
     * @param array{enabled: bool, providers: array<string, bool>, max_width: string} $settings
     */
    public function saveSettings(array $settings): void
    {
        $this->config->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'];
    }

    public function providerEnabled(string $key): bool
    {
        return $this->settings()['providers'][$key] ?? false;
    }

    /**
     * Every registered provider, core's seven plus anything a plugin/
     * theme added via the 'embed_providers' filter. `type` defaults to
     * `'iframe'` when omitted; `'blockquote'` is for a provider like
     * Twitter/X with no plain-iframe embed. Not cached: a plugin
     * activated/deactivated mid-session should see its provider change immediately.
     *
     * @return array<int, array{key: string, label: string, match: callable, allow: string, allowfullscreen: bool, aspect: string, type?: string}>
     */
    private function providers(): array
    {
        return $this->hooks->applyFilters('embed_providers', $this->coreProviders());
    }

    /**
     * @return array<int, array{key: string, label: string, match: callable, allow: string, allowfullscreen: bool, aspect: string, type: string}>
     */
    private function coreProviders(): array
    {
        return [
            [
                'key' => 'youtube',
                'label' => 'YouTube',
                'match' => self::matchYouTube(...),
                'allow' => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
                'allowfullscreen' => true,
                'aspect' => '16 / 9',
                'type' => 'iframe',
            ],
            [
                'key' => 'vimeo',
                'label' => 'Vimeo',
                'match' => self::matchVimeo(...),
                'allow' => 'autoplay; fullscreen; picture-in-picture',
                'allowfullscreen' => true,
                'aspect' => '16 / 9',
                'type' => 'iframe',
            ],
            [
                'key' => 'soundcloud',
                'label' => 'SoundCloud',
                'match' => self::matchSoundCloud(...),
                'allow' => 'autoplay',
                'allowfullscreen' => false,
                'aspect' => '',
                'type' => 'iframe',
            ],
            [
                'key' => 'spotify',
                'label' => 'Spotify',
                'match' => self::matchSpotify(...),
                'allow' => 'autoplay; clipboard-write; encrypted-media; picture-in-picture',
                'allowfullscreen' => false,
                'aspect' => '',
                'type' => 'iframe',
            ],
            [
                'key' => 'codepen',
                'label' => 'CodePen',
                'match' => self::matchCodePen(...),
                'allow' => '',
                'allowfullscreen' => true,
                'aspect' => '16 / 9',
                'type' => 'iframe',
            ],
            [
                'key' => 'twitter',
                'label' => 'Twitter/X',
                'match' => self::matchTwitter(...),
                'allow' => '',
                'allowfullscreen' => false,
                'aspect' => '',
                'type' => 'blockquote',
            ],
            [
                'key' => 'bluesky',
                'label' => 'Bluesky',
                'match' => $this->matchBluesky(...),
                'allow' => '',
                'allowfullscreen' => false,
                'aspect' => '',
                'type' => 'blockquote',
            ],
        ];
    }

    /**
     * The 'content_html' filter callback (ContentRenderer::render()).
     * Extra args ($content, $format) are accepted by the hook but unused
     * here — every format's rendered HTML shape is handled uniformly by
     * the two regex patterns above.
     */
    public function render(string $html): string
    {
        if (!$this->isEnabled() || (!str_contains($html, 'http://') && !str_contains($html, 'https://'))) {
            return $html;
        }

        $html = (string) preg_replace_callback(self::PARAGRAPH_PATTERN, function (array $matches): string {
            $url = $matches[1] !== '' ? $matches[1] : $matches[2];

            return $this->buildEmbed($url) ?? $matches[0];
        }, $html);

        $html = (string) preg_replace_callback(self::LINE_PATTERN, function (array $matches): string {
            $embed = $this->buildEmbed($matches[2]);

            return $embed !== null ? $matches[1] . $embed : $matches[0];
        }, $html);

        return $html;
    }

    /**
     * Adds each enabled provider's iframe origin to the frame-src directive so the browser's
     * default same-origin-only CSP doesn't silently drop the embed. Added unconditionally
     * whenever a provider is enabled, since the CSP header is sent before content renders.
     *
     * Twitter/X and Bluesky also widen script-src and frame-src, since each replaces its
     * blockquote with an injected iframe via a hosted script rather than a plain iframe.
     * Twitter/X additionally widens connect-src for syndication.twitter.com.
     *
     * @param array<string, string> $directives
     * @return array<string, string>
     */
    public function filterCsp(array $directives): array
    {
        if (!$this->isEnabled()) {
            return $directives;
        }

        $origins = [
            'youtube' => 'https://www.youtube.com',
            'vimeo' => 'https://player.vimeo.com',
            'soundcloud' => 'https://w.soundcloud.com',
            'spotify' => 'https://open.spotify.com',
            'codepen' => 'https://codepen.io',
        ];

        $enabledOrigins = [];

        foreach ($origins as $key => $origin) {
            if ($this->providerEnabled($key)) {
                $enabledOrigins[] = $origin;
            }
        }

        if ($enabledOrigins !== []) {
            $directives['frame-src'] = ($directives['frame-src'] ?? "'self'") . ' ' . implode(' ', $enabledOrigins);
        }

        if ($this->providerEnabled('twitter')) {
            $directives['script-src'] = ($directives['script-src'] ?? "'self'") . ' https://platform.twitter.com';
            $directives['frame-src'] = ($directives['frame-src'] ?? "'self'") . ' https://platform.twitter.com';
            $directives['connect-src'] = ($directives['connect-src'] ?? "'self'") . ' https://syndication.twitter.com';
        }

        if ($this->providerEnabled('bluesky')) {
            $directives['script-src'] = ($directives['script-src'] ?? "'self'") . ' https://embed.bsky.app';
            $directives['frame-src'] = ($directives['frame-src'] ?? "'self'") . ' https://embed.bsky.app';
        }

        return $directives;
    }

    private function buildEmbed(string $rawUrl): ?string
    {
        // Entity-decoded before matching/building — the surrounding HTML may have encoded "&" as "&amp;" in the URL's query string.
        $url = html_entity_decode(trim($rawUrl), ENT_QUOTES | ENT_HTML5);

        foreach ($this->providers() as $provider) {
            // Per-provider on/off only applies to the core providers; a plugin-registered provider has no toggle here and is always on.
            $isCore = in_array($provider['key'], self::DEFAULT_PROVIDERS, true);

            if ($isCore && !$this->providerEnabled($provider['key'])) {
                continue;
            }

            $match = ($provider['match'])($url);

            if ($match === null) {
                continue;
            }

            return $this->wrap($provider, $match);
        }

        return null;
    }

    /**
     * @param array{key: string, label: string, allow: string, allowfullscreen: bool, aspect: string, type?: string} $provider
     * @param array{src: string, title: string, atUri?: string, cid?: string} $match
     */
    private function wrap(array $provider, array $match): string
    {
        $settings = $this->settings();
        $style = $settings['max_width'] !== '' ? ' style="max-width:' . esc_attr($settings['max_width']) . '"' : '';
        $src = $match['src'];

        if (($provider['type'] ?? 'iframe') === 'blockquote') {
            // Neither has a plain-iframe embed — each provider's own script (loaded conditionally, see ScriptEmbeds) scans the page and replaces the blockquote with an iframe.
            ScriptEmbeds::markUsed($provider['key']);

            $html = $provider['key'] === 'bluesky'
                ? '<div class="lp-embed lp-embed--bluesky"' . $style . '>'
                    . '<blockquote class="bluesky-embed" data-bluesky-uri="' . esc_attr($match['atUri'] ?? '') . '" data-bluesky-cid="' . esc_attr($match['cid'] ?? '') . '"><a href="' . esc_attr($src) . '"></a></blockquote>'
                    . '</div>'
                : '<div class="lp-embed lp-embed--' . esc_attr($provider['key']) . '"' . $style . '>'
                    . '<blockquote class="twitter-tweet" data-dnt="true"><a href="' . esc_attr($src) . '"></a></blockquote>'
                    . '</div>';

            return (string) $this->hooks->applyFilters('embed_html', $html, $provider['key'], $src);
        }

        $title = $match['title'];
        $allowAttr = $provider['allow'] !== '' ? ' allow="' . esc_attr($provider['allow']) . '"' : '';
        $fullscreenAttr = $provider['allowfullscreen'] ? ' allowfullscreen' : '';

        $html = '<div class="lp-embed lp-embed--' . esc_attr($provider['key']) . '"' . $style . '>'
            . '<div class="lp-embed__frame">'
            . '<iframe src="' . esc_attr($src) . '" title="' . esc_attr($title) . '" loading="lazy"' . $allowAttr . $fullscreenAttr . '></iframe>'
            . '</div></div>';

        return (string) $this->hooks->applyFilters('embed_html', $html, $provider['key'], $src);
    }

    /**
     * @return array{src: string, title: string}|null
     */
    private static function matchYouTube(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            if (preg_match('#^/([A-Za-z0-9_-]{6,15})/?$#', $path, $m) === 1) {
                return ['src' => "https://www.youtube.com/embed/{$m[1]}", 'title' => 'YouTube video'];
            }

            return null;
        }

        if (!in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            return null;
        }

        if ($path === '/watch') {
            parse_str($query, $params);

            if (isset($params['v']) && is_string($params['v']) && preg_match('#^[A-Za-z0-9_-]{6,15}$#', $params['v']) === 1) {
                return ['src' => "https://www.youtube.com/embed/{$params['v']}", 'title' => 'YouTube video'];
            }

            return null;
        }

        if (preg_match('#^/shorts/([A-Za-z0-9_-]{6,15})/?$#', $path, $m) === 1) {
            return ['src' => "https://www.youtube.com/embed/{$m[1]}", 'title' => 'YouTube video'];
        }

        return null;
    }

    /**
     * @return array{src: string, title: string}|null
     */
    private static function matchVimeo(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('#^/(\d+)/?$#', $path, $m) === 1) {
            return ['src' => "https://player.vimeo.com/video/{$m[1]}", 'title' => 'Vimeo video'];
        }

        if ($host === 'player.vimeo.com' && preg_match('#^/video/(\d+)/?$#', $path, $m) === 1) {
            return ['src' => "https://player.vimeo.com/video/{$m[1]}", 'title' => 'Vimeo video'];
        }

        return null;
    }

    /**
     * @return array{src: string, title: string}|null
     */
    private static function matchSoundCloud(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (!in_array($host, ['soundcloud.com', 'www.soundcloud.com', 'm.soundcloud.com'], true)) {
            return null;
        }

        if (preg_match('#^/[\w-]+/[\w-]+#', $path) !== 1) {
            return null;
        }

        $embedUrl = 'https://w.soundcloud.com/player/?url=' . rawurlencode($url)
            . '&color=%23ff5500&auto_play=false&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=true';

        return ['src' => $embedUrl, 'title' => 'SoundCloud audio'];
    }

    /**
     * @return array{src: string, title: string}|null
     */
    private static function matchSpotify(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host !== 'open.spotify.com') {
            return null;
        }

        if (preg_match('#^/(track|album|playlist|episode|show)/([A-Za-z0-9]+)/?$#', $path, $m) !== 1) {
            return null;
        }

        return [
            'src' => "https://open.spotify.com/embed/{$m[1]}/{$m[2]}",
            'title' => 'Spotify ' . $m[1],
        ];
    }

    /**
     * @return array{src: string, title: string}|null
     */
    private static function matchCodePen(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (!in_array($host, ['codepen.io', 'www.codepen.io'], true)) {
            return null;
        }

        if (preg_match('#^/([\w-]+)/(?:pen|full|details)/([\w-]+)/?$#', $path, $m) !== 1) {
            return null;
        }

        return [
            'src' => "https://codepen.io/{$m[1]}/embed/{$m[2]}?default-tab=result",
            'title' => 'CodePen embed',
        ];
    }

    /**
     * Extracts a tweet's author handle and status id and re-normalizes
     * onto twitter.com regardless of which host was pasted, for
     * predictable generated markup. Unlike SoundCloud, the raw pasted
     * URL is never re-embedded verbatim.
     *
     * @return array{src: string, title: string}|null
     */
    private static function matchTwitter(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (!in_array($host, ['twitter.com', 'www.twitter.com', 'mobile.twitter.com', 'x.com', 'www.x.com'], true)) {
            return null;
        }

        if (preg_match('#^/(\w{1,15})/status/(\d+)#', $path, $m) !== 1) {
            return null;
        }

        return [
            'src' => "https://twitter.com/{$m[1]}/status/{$m[2]}",
            'title' => 'Tweet',
        ];
    }

    /**
     * Unlike every other match* method, this is an instance method — it
     * needs $this->bluesky to look up the post's AT-URI/CID, which can't
     * be derived from the URL alone. This is a pure local cache read,
     * resolved ahead of time at save time, never a network call during
     * render(). An unresolved URL returns null, left as a plain link.
     *
     * @return array{src: string, title: string, atUri: string, cid: string}|null
     */
    private function matchBluesky(string $url): ?array
    {
        if ($this->bluesky === null) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (!in_array($host, ['bsky.app', 'www.bsky.app'], true)) {
            return null;
        }

        if (preg_match('#^/profile/([\w.\-:%]+)/post/([A-Za-z0-9]+)#', $path, $m) !== 1) {
            return null;
        }

        $handle = $m[1];
        $rkey = $m[2];
        $resolved = $this->bluesky->cached($handle, $rkey);

        if ($resolved === null) {
            return null;
        }

        return [
            'src' => "https://bsky.app/profile/{$handle}/post/{$rkey}",
            'title' => 'Bluesky post',
            'atUri' => $resolved['atUri'],
            'cid' => $resolved['cid'],
        ];
    }
}
