<?php

/**
 * Core logic for the bundled Font Awesome plugin (LPP-002): settings, CDN/self-hosted CSS URLs, and icon markup rendering.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\FontAwesome;

use LumoraPress\Core\ActiveConfig;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Theme\ActiveTheme;
use RuntimeException;

/**
 * Core logic for the bundled Font Awesome plugin (LPP-002): resolves
 * settings, builds the CDN/self-hosted CSS URL(s), renders `<i>` markup for
 * both the `[icon]` shortcode and the `lp_icon()` developer helper, and
 * hooks 'content_html' / 'head_assets' / 'csp_directives' to wire itself
 * into the theme rendering pipeline. See font-awesome.php for hook
 * registration; this class is deliberately hook-agnostic so it can be unit
 * tested without booting the full application.
 */
final class FontAwesomeService
{
    private const OPTION_KEY = 'font_awesome_settings';

    public const DEFAULT_VERSION = '6.5.2';

    private const SHORTCODE_PATTERN = '/\[icon\s+([^\]]*)\]/i';

    /** Bundled icon metadata (data/icons.php) the admin icon picker searches. */
    private const ICON_DATA_PATH = __DIR__ . '/../data/icons.php';

    /** Cached, request-independent copy of the same data, rebuilt whenever the source file changes (see loadIconIndex()). */
    private const ICON_CACHE_PATH_SUFFIX = '/storage/cache/font-awesome-icons.json';

    private const ICON_QUERY_PAGE_SIZE = 60;

    private const ALLOWED_SIZES = ['xs', 'sm', 'lg', '1x', '2x', '3x', '4x', '5x', '6x', '7x', '8x', '9x', '10x'];

    private const ALLOWED_ROTATIONS = ['90', '180', '270'];

    private const ALLOWED_FLIPS = ['horizontal', 'vertical', 'both'];

    private const ALLOWED_ANIMATIONS = ['spin', 'spin-pulse', 'spin-reverse', 'pulse', 'beat', 'beat-fade', 'fade', 'bounce', 'shake'];

    /** @var array<string, string> style keyword => Font Awesome 6 class prefix */
    private const ALLOWED_STYLES = [
        'solid' => 'fa-solid',
        'regular' => 'fa-regular',
        'brands' => 'fa-brands',
        'light' => 'fa-light',
        'thin' => 'fa-thin',
        'duotone' => 'fa-duotone',
    ];

    private static ?self $instance = null;

    /**
     * Set by markUsed() when an icon actually renders (via lp_icon(), the
     * shortcode, or an explicit lp_fontawesome_enqueue() call). Not
     * currently used to gate output — see printHeadLinks()'s own docblock
     * for why — kept as request-scoped instrumentation for the admin
     * diagnostics page (LPP-002's deferred "Admin diagnostics page"
     * checklist item).
     */
    private bool $used = false;

    /** @var array<string, array{label: string}> */
    private array $iconPacks = [];

    /**
     * Set once at plugin load time via configurePluginsPath() (font-awesome.php
     * doesn't have constructor-injection access to Kernel's own $pluginsPath —
     * same reason ActiveConfig/ActiveTheme exist as static bridges rather than
     * being passed in directly). Left null in unit tests, where detectConflicts()
     * simply skips the plugin-scan half rather than erroring.
     */
    private ?string $pluginsPath = null;

    /** @var array{enabled: bool, delivery: string, version: string, self_hosted_url: string, compatibility_mode: bool}|null */
    private ?array $settingsCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function registerIconPack(string $key, array $config): void
    {
        $this->iconPacks[$key] = ['label' => (string) ($config['label'] ?? $key)];
    }

    /**
     * @return array<int, string>
     */
    public function iconPackKeys(): array
    {
        return array_keys($this->iconPacks);
    }

    public function configurePluginsPath(string $pluginsPath): void
    {
        $this->pluginsPath = rtrim($pluginsPath, '/');
    }

    /**
     * Heuristic duplicate-loading check (LPP-002's "detect duplicate Font
     * Awesome instances" checklist item): scans the active theme's own
     * template/stylesheet files and every other active plugin's main file
     * for a hardcoded Font Awesome reference this plugin doesn't already
     * know about, so enabling this plugin on top of a theme/plugin that
     * bundles its own copy is at least visible on the diagnostics page
     * rather than silently loading two copies. Detection only — this
     * plugin has no way to suppress a theme's own hardcoded `<link>` (see
     * printHeadLinks()'s own docblock for why it can't gate on content
     * inspection), so "prevent duplicate loading" stays a human action:
     * remove the theme/plugin's own reference once flagged here.
     *
     * @return array<int, array{source: string, file: string}>
     */
    public function detectConflicts(): array
    {
        $conflicts = [];

        $themePath = null;
        $themeLabel = 'active theme';

        try {
            $themePath = ActiveTheme::instance()->themePath();
            $themeLabel = ActiveTheme::instance()->activeTheme() ?? $themeLabel;
        } catch (RuntimeException) {
            // No theme bound to this request (e.g. a CLI/test context) — nothing to scan.
        }

        if ($themePath !== null) {
            foreach (['header.php', 'footer.php', 'functions.php', 'style.css'] as $file) {
                if ($this->fileMentionsFontAwesome($themePath . '/' . $file)) {
                    $conflicts[] = ['source' => "Theme: {$themeLabel}", 'file' => $file];
                }
            }
        }

        if ($this->pluginsPath !== null) {
            foreach ($this->activePluginSlugs() as $slug) {
                if ($slug === 'font-awesome') {
                    continue;
                }

                $mainFile = $this->pluginsPath . '/' . $slug . '/' . $slug . '.php';

                if ($this->fileMentionsFontAwesome($mainFile)) {
                    $conflicts[] = ['source' => "Plugin: {$slug}", 'file' => $slug . '.php'];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return array<int, string>
     */
    private function activePluginSlugs(): array
    {
        $stored = ActiveConfig::instance()->option('active_plugins', '[]');
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    private function fileMentionsFontAwesome(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        return $contents !== false && preg_match('/font[\s\-]?awesome/i', $this->stripComments($contents)) === 1;
    }

    /**
     * Strips /* ... *\/ block comments before scanning (LP-128): this
     * codebase's own docblock convention cross-references other plugins
     * by name (e.g. Downloads/Contact Forms/WordPress Importer's headers
     * noting they share the 'content_html' hook pattern Font Awesome's
     * [icon] shortcode uses), which otherwise flags as a false "duplicate
     * loading" conflict even though no Font Awesome asset is ever loaded.
     * Deliberately does not also strip `//` line comments — a genuine CDN
     * URL (https://...) contains its own "//" and would be silently
     * truncated by a naive line-comment stripper, turning a real conflict
     * into a false negative.
     */
    private function stripComments(string $contents): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
    }

    /**
     * Icon-picker search (LPP-002's "html/visual (TinyMCE) icon picker" /
     * "Easy MDE icon picker" checklist items): filters the bundled/cached
     * icon metadata by name/label/keyword, case-insensitively. Called
     * directly by both the TinyMCE and Markdown editors' own picker
     * AJAX sub-action (see queryIconsForPicker()) so there's exactly one
     * search implementation for both.
     *
     * @return array{items: array<int, array{name: string, label: string, category: string, style: string}>, total: int}
     */
    public function searchIcons(string $term, int $limit = self::ICON_QUERY_PAGE_SIZE, int $offset = 0): array
    {
        $term = strtolower(trim($term));
        $icons = $this->loadIconIndex();

        if ($term !== '') {
            $icons = array_values(array_filter($icons, static function (array $icon) use ($term): bool {
                if (str_contains(strtolower($icon['name']), $term) || str_contains(strtolower($icon['label']), $term)) {
                    return true;
                }

                foreach ($icon['keywords'] as $keyword) {
                    if (str_contains(strtolower($keyword), $term)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return [
            'items' => array_slice($icons, max(0, $offset), max(0, $limit)),
            'total' => count($icons),
        ];
    }

    /**
     * The icon picker's AJAX sub-action, dispatched identically from
     * posts/new.php, pages/new.php, and downloads/add-new.php's own
     * `$_POST['form']` matches (mirroring PostsController::
     * queryMediaForPicker()'s contract exactly: echoes JSON directly,
     * hands back a fresh single-use CSRF token every response since the
     * picker re-fires this on every search keystroke/page change within
     * one dialog session). No capability check beyond CSRF verification
     * — a read-only icon-name lookup, the same low-risk-utility gating
     * convertContent() already uses for its own no-side-effect sub-action.
     *
     * @param array<string, mixed> $post
     */
    public function queryIconsForPicker(array $post, ?string $csrfToken): void
    {
        if (!Csrf::verify('font_awesome_icon_query', $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not permitted.']);

            return;
        }

        $term = trim((string) ($post['term'] ?? ''));
        $page = max(1, (int) ($post['page'] ?? 1));

        $result = $this->searchIcons($term, self::ICON_QUERY_PAGE_SIZE, ($page - 1) * self::ICON_QUERY_PAGE_SIZE);

        echo json_encode([
            'items' => $result['items'],
            'total' => $result['total'],
            'csrfToken' => Csrf::token('font_awesome_icon_query'),
        ]);
    }

    /**
     * Loads the bundled icon metadata (LPP-002's "cache icon metadata for
     * faster admin searches" checklist item), caching the parsed result
     * to a JSON file under storage/cache/ so repeat searches within (and
     * across) requests don't re-`require` and re-normalize data/icons.php
     * every time — the same write-to-temp-then-rename pattern
     * UpdateProgress uses, invalidated automatically by comparing the
     * source file's own mtime rather than needing a manual "clear cache"
     * step. Degrades to reading the bundled file directly, uncached, when
     * $pluginsPath isn't configured (e.g. unit tests) rather than erroring.
     *
     * @return array<int, array{name: string, label: string, category: string, keywords: array<int, string>, style: string}>
     */
    private function loadIconIndex(): array
    {
        $sourceMTime = is_file(self::ICON_DATA_PATH) ? (int) @filemtime(self::ICON_DATA_PATH) : 0;
        $cachePath = $this->iconCachePath();

        if ($cachePath !== null) {
            $cached = $this->readIconCache($cachePath);

            if ($cached !== null && ($cached['sourceMTime'] ?? null) === $sourceMTime) {
                return $cached['icons'];
            }
        }

        $icons = $this->normalizeIcons(is_file(self::ICON_DATA_PATH) ? (require self::ICON_DATA_PATH) : []);

        if ($cachePath !== null) {
            $this->writeIconCache($cachePath, $sourceMTime, $icons);
        }

        return $icons;
    }

    /**
     * @param array<int, array{name: string, label: string, category: string, keywords: array<int, string>, style?: string}> $rawIcons
     * @return array<int, array{name: string, label: string, category: string, keywords: array<int, string>, style: string}>
     */
    private function normalizeIcons(array $rawIcons): array
    {
        return array_map(static fn (array $icon): array => [
            'name' => (string) $icon['name'],
            'label' => (string) $icon['label'],
            'category' => (string) $icon['category'],
            'keywords' => array_values(array_map('strval', $icon['keywords'])),
            'style' => (string) ($icon['style'] ?? 'solid'),
        ], $rawIcons);
    }

    private function iconCachePath(): ?string
    {
        if ($this->pluginsPath === null) {
            return null;
        }

        // $this->pluginsPath is {installRoot}/content/plugins (see
        // configurePluginsPath()'s own docblock) — two levels up recovers
        // the install root storage/ lives under.
        return dirname($this->pluginsPath, 2) . self::ICON_CACHE_PATH_SUFFIX;
    }

    /**
     * @return array{sourceMTime: int, icons: array<int, array{name: string, label: string, category: string, keywords: array<int, string>, style: string}>}|null
     */
    private function readIconCache(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $decoded = $contents !== false ? json_decode($contents, true) : null;

        if (!is_array($decoded) || !isset($decoded['sourceMTime'], $decoded['icons']) || !is_array($decoded['icons'])) {
            return null;
        }

        return ['sourceMTime' => (int) $decoded['sourceMTime'], 'icons' => $decoded['icons']];
    }

    /**
     * @param array<int, array{name: string, label: string, category: string, keywords: array<int, string>, style: string}> $icons
     */
    private function writeIconCache(string $path, int $sourceMTime, array $icons): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $encoded = json_encode(['sourceMTime' => $sourceMTime, 'icons' => $icons]);

        if ($encoded === false) {
            return;
        }

        // Write-then-rename (rather than a direct file_put_contents()) so a
        // concurrent reader never sees a half-written cache file — rename()
        // is atomic on the same filesystem, the same reasoning
        // UpdateProgress::write() documents in detail.
        $tmpPath = $path . '.tmp-' . uniqid('', true);

        if (@file_put_contents($tmpPath, $encoded) !== false) {
            @rename($tmpPath, $path);
        }
    }

    /**
     * @return array{enabled: bool, delivery: string, version: string, self_hosted_url: string, compatibility_mode: bool}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        $defaults = [
            'enabled' => false,
            'delivery' => 'cdn',
            'version' => self::DEFAULT_VERSION,
            'self_hosted_url' => '',
            'compatibility_mode' => false,
        ];

        $settings = array_merge($defaults, is_array($decoded) ? $decoded : []);

        $settings = [
            'enabled' => (bool) $settings['enabled'],
            'delivery' => $settings['delivery'] === 'self_hosted' ? 'self_hosted' : 'cdn',
            'version' => trim((string) $settings['version']) !== '' ? trim((string) $settings['version']) : self::DEFAULT_VERSION,
            'self_hosted_url' => trim((string) $settings['self_hosted_url']),
            'compatibility_mode' => (bool) $settings['compatibility_mode'],
        ];

        $this->settingsCache = $settings;

        return $settings;
    }

    /**
     * @param array{enabled: bool, delivery: string, version: string, self_hosted_url: string, compatibility_mode: bool} $settings
     */
    public function saveSettings(array $settings): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'];
    }

    public function markUsed(): void
    {
        $this->used = true;
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    /**
     * The CSS URL(s) to load for the current settings — one for the base
     * stylesheet, plus a second v4-shims stylesheet when Compatibility Mode
     * is on (restores the old `fa fa-camera`-style class names FA4-era
     * themes/copy-pasted snippets use, alongside FA6's own `fa-solid
     * fa-camera` classes).
     *
     * @return array<int, string>
     */
    public function cssUrls(): array
    {
        $settings = $this->settings();

        if ($settings['delivery'] === 'self_hosted') {
            if ($settings['self_hosted_url'] === '') {
                return [];
            }

            $urls = [$settings['self_hosted_url']];

            if ($settings['compatibility_mode']) {
                $shim = preg_replace('/\/all(\.min)?\.css$/', '/v4-shims$1.css', $settings['self_hosted_url']);
                $urls[] = $shim ?? $settings['self_hosted_url'];
            }

            return $urls;
        }

        $version = $settings['version'];
        $urls = ["https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@{$version}/css/all.min.css"];

        if ($settings['compatibility_mode']) {
            $urls[] = "https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@{$version}/css/v4-shims.min.css";
        }

        return $urls;
    }

    /**
     * Echoes one `<link rel="stylesheet">` per cssUrls() entry, hooked to
     * 'head_assets' (fired from header.php just before `</head>`).
     *
     * Gated on isEnabled() only, not isUsed(): the theme's header renders
     * before the post/page body does (get_header() -> content -> footer,
     * a strict single pass with no lookahead), so by the time an `[icon]`
     * shortcode or lp_icon() call inside the content actually runs,
     * `</head>` has already been emitted — gating on isUsed() here would
     * silently drop the stylesheet for exactly the shortcode/helper this
     * plugin exists for. The "enabled" toggle (off by default) is already
     * the real per-site opt-in the ticket's "load only if required" goal
     * asks for; per-request lazy loading based on whether the current
     * page's content actually uses an icon is deferred to the diagnostics
     * page phase, which can pre-scan content before headers are sent.
     */
    public function printHeadLinks(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        foreach ($this->cssUrls() as $url) {
            echo '<link rel="stylesheet" href="' . esc_url($url) . '">' . "\n";
        }
    }

    /**
     * Adds the configured self-hosted origin to style-src/font-src so the
     * browser doesn't silently drop it under this project's same-origin-only
     * default CSP (see docs/THIRD-PARTY.md) — a no-op for CDN delivery,
     * since cdn.jsdelivr.net is already allow-listed in include/bootstrap.php.
     *
     * @param array<string, string> $directives
     * @return array<string, string>
     */
    public function filterCsp(array $directives): array
    {
        $settings = $this->settings();

        if ($settings['delivery'] !== 'self_hosted' || $settings['self_hosted_url'] === '') {
            return $directives;
        }

        $scheme = parse_url($settings['self_hosted_url'], PHP_URL_SCHEME);
        $host = parse_url($settings['self_hosted_url'], PHP_URL_HOST);

        if (!is_string($scheme) || !is_string($host) || $host === '') {
            return $directives;
        }

        $origin = $scheme . '://' . $host;

        if (isset($directives['style-src'])) {
            $directives['style-src'] .= ' ' . $origin;
        }

        if (isset($directives['font-src'])) {
            $directives['font-src'] .= ' ' . $origin;
        }

        return $directives;
    }

    /**
     * Builds one icon's `<i>` markup. $args accepts: style (solid/regular/
     * brands/light/thin/duotone, default solid), size (fa-xs..fa-10x),
     * rotate (90/180/270), flip (horizontal/vertical/both), animation
     * (spin/pulse/beat/...), color (hex or CSS color keyword, rendered as
     * an inline style — a dynamic per-call value, exactly the case
     * CLAUDE.md's inline-style exception is for), class (extra
     * space-separated classes), and label (accessible name — when given,
     * the icon gets role="img" aria-label="..."; when omitted, it's marked
     * aria-hidden="true" as purely decorative, per the ticket's
     * accessibility requirement).
     *
     * @param array<string, mixed> $args
     */
    public function icon(string $name, array $args = []): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $style = isset($args['style']) && is_string($args['style']) && isset(self::ALLOWED_STYLES[$args['style']])
            ? self::ALLOWED_STYLES[$args['style']]
            : 'fa-solid';

        $classes = [$style, 'fa-' . $this->sanitizeSlug($name)];

        if (isset($args['size']) && is_string($args['size']) && in_array($args['size'], self::ALLOWED_SIZES, true)) {
            $classes[] = 'fa-' . $args['size'];
        }

        if (isset($args['rotate']) && in_array((string) $args['rotate'], self::ALLOWED_ROTATIONS, true)) {
            $classes[] = 'fa-rotate-' . (string) $args['rotate'];
        }

        if (isset($args['flip']) && is_string($args['flip']) && in_array($args['flip'], self::ALLOWED_FLIPS, true)) {
            $classes[] = 'fa-flip-' . $args['flip'];
        }

        if (isset($args['animation']) && is_string($args['animation']) && in_array($args['animation'], self::ALLOWED_ANIMATIONS, true)) {
            $classes[] = 'fa-' . $args['animation'];
        }

        if (isset($args['class']) && is_string($args['class']) && trim($args['class']) !== '') {
            $extra = trim((string) preg_replace('/[^a-zA-Z0-9_\- ]/', '', $args['class']));

            if ($extra !== '') {
                $classes[] = $extra;
            }
        }

        $styleAttr = '';

        if (isset($args['color']) && is_string($args['color']) && $this->isSafeColor($args['color'])) {
            $styleAttr = ' style="color:' . esc_attr(trim($args['color'])) . '"';
        }

        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';
        $ariaAttr = $label !== '' ? ' role="img" aria-label="' . esc_attr($label) . '"' : ' aria-hidden="true"';

        $this->markUsed();

        return '<i class="' . esc_attr(implode(' ', $classes)) . '"' . $ariaAttr . $styleAttr . '></i>';
    }

    /**
     * Replaces every `[icon name="..."]` occurrence in already-sanitized
     * HTML with icon() markup — hooked to 'content_html', which fires
     * after HtmlSanitizer::clean() (see ContentRenderer::render()), so the
     * injected `<i class="...">` never has to survive the tag/attribute
     * allowlist. Deliberately not run when the plugin is disabled: an
     * unrendered `[icon ...]` literal is a clearer signal to the author
     * that the feature needs enabling than a styleless icon box would be.
     */
    public function renderShortcodes(string $html): string
    {
        if (!$this->isEnabled()) {
            return $html;
        }

        $result = preg_replace_callback(self::SHORTCODE_PATTERN, function (array $matches): string {
            $attributes = $this->parseShortcodeAttributes($matches[1]);
            $name = isset($attributes['name']) ? $attributes['name'] : '';

            if ($name === '') {
                return $matches[0];
            }

            unset($attributes['name']);

            return $this->icon($name, $attributes);
        }, $html);

        return $result ?? $html;
    }

    /**
     * @return array<string, string>
     */
    private function parseShortcodeAttributes(string $raw): array
    {
        $attributes = [];

        preg_match_all(
            '/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"([^"]*)"|([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*\'([^\']*)\'/',
            $raw,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            if ($match[1] !== '') {
                $attributes[strtolower($match[1])] = $match[2];
            } else {
                $attributes[strtolower($match[3])] = $match[4];
            }
        }

        return $attributes;
    }

    private function sanitizeSlug(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9\-]+/', '-', $name));

        return trim($slug, '-');
    }

    private function isSafeColor(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]{3,20}$/', trim($color)) === 1;
    }
}
