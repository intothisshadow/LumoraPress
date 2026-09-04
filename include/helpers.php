<?php

/**
 * General-purpose output escaping and localization helpers, available everywhere (core, admin, themes, plugins).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Core\ActiveEditorPreference;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\Security\CspNonce;
use LumoraPress\Core\Theme\SiteBranding;
use LumoraPress\Core\Theme\ThemeOptionsBridge;
use LumoraPress\Models\ContentFormat;

if (!function_exists('esc_html')) {
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__')) {
    /**
     * Translates a string. Falls back to the original text until a
     * translation loader registers the "gettext" filter.
     */
    function __(string $text, string $domain = 'lumora-press'): string
    {
        return apply_filters('gettext', $text, $domain);
    }
}

if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'lumora-press'): void
    {
        echo esc_html(__($text, $domain));
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $page = ''): string
    {
        $page = trim($page, '/');
        $base = BasePath::get();

        return $page === '' ? $base . '/admin' : $base . '/admin/' . $page;
    }
}

if (!function_exists('redirect')) {
    /**
     * A single named seam for the Location-header-then-exit pattern used
     * throughout admin views and SiteController. Doesn't make callers
     * testable by itself — PHPUnit still can't intercept a real exit();
     * admin controller actions avoid that via AdminActionResult instead.
     */
    function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('admin_asset_url')) {
    /**
     * Builds a URL to a static file under admin/assets/, honouring the
     * install's base path. Deliberately doesn't go through admin_url()
     * since admin/assets/ is a real directory, not a routed page.
     *
     * Appends `?v={mtime}` when $path resolves to a real file — a
     * cache-busting query string that changes automatically with the
     * file, so a shipped fix doesn't sit invisible in a cached copy.
     */
    function admin_asset_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = BasePath::get() . '/admin/assets';

        if ($path === '') {
            return $base;
        }

        // dirname(__DIR__) rather than LUMORA_ROOT — this file is also
        // loaded directly by the PHP Test Suite's bootstrap, where
        // LUMORA_ROOT isn't defined.
        $absolutePath = dirname(__DIR__) . '/admin/assets/' . $path;
        $mtime = is_file($absolutePath) ? @filemtime($absolutePath) : false;

        return $base . '/' . $path . ($mtime !== false ? '?v=' . $mtime : '');
    }
}

if (!function_exists('core_asset_url')) {
    /**
     * Builds a URL to a static file under the top-level assets/
     * directory — framework-owned JS/CSS a theme's markup depends on but
     * doesn't itself provide. Mirrors admin_asset_url()'s shape,
     * including its cache-busting `?v={mtime}`.
     */
    function core_asset_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = BasePath::get() . '/assets';

        if ($path === '') {
            return $base;
        }

        $absolutePath = dirname(__DIR__) . '/assets/' . $path;
        $mtime = is_file($absolutePath) ? @filemtime($absolutePath) : false;

        return $base . '/' . $path . ($mtime !== false ? '?v=' . $mtime : '');
    }
}

if (!function_exists('render_pagination')) {
    /**
     * Renders a numbered pagination nav for the current path, preserving
     * it while swapping only the "paged" query parameter. Truncates long
     * page ranges to the pages nearest the start, end, and current page,
     * joined by ellipses, rather than a button per page.
     *
     * @param array{page: int, totalPages: int} $pagination
     */
    function render_pagination(array $pagination, string $label = 'Posts pagination'): void
    {
        $current = $pagination['page'];
        $total = $pagination['totalPages'];

        if ($total <= 1) {
            return;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        parse_str((string) parse_url($requestUri, PHP_URL_QUERY), $query);

        $urlForPage = static function (int $page) use ($path, $query): string {
            if ($page > 1) {
                $query['paged'] = $page;
            } else {
                unset($query['paged']);
            }

            return $path . ($query !== [] ? '?' . http_build_query($query) : '');
        };

        // A link when $targetPage is reachable, else a disabled span so
        // the control's position never shifts between pages.
        $renderNav = static function (string $visibleText, string $srText, ?int $targetPage) use ($urlForPage): void {
            $classes = 'lp-pagination__item lp-pagination__item--nav' . ($targetPage === null ? ' is-disabled' : '');
            $inner = esc_html($visibleText) . ($srText !== '' ? '<span class="lp-visually-hidden">' . esc_html($srText) . '</span>' : '');

            echo '<li class="' . esc_attr($classes) . '">';
            echo $targetPage === null
                ? '<span aria-disabled="true">' . $inner . '</span>'
                : '<a href="' . esc_url($urlForPage($targetPage)) . '">' . $inner . '</a>';
            echo '</li>';
        };

        $renderPage = static function (int $page, bool $isCurrent) use ($urlForPage): void {
            echo '<li class="lp-pagination__item' . ($isCurrent ? ' is-current' : '') . '">';
            echo $isCurrent
                ? '<span aria-current="page">' . esc_html((string) $page) . '</span>'
                : '<a href="' . esc_url($urlForPage($page)) . '">' . esc_html((string) $page) . '</a>';
            echo '</li>';
        };

        $renderEllipsis = static function (): void {
            echo '<li class="lp-pagination__item lp-pagination__item--ellipsis"><span aria-hidden="true">&hellip;</span></li>';
        };

        echo '<nav class="lp-pagination" aria-label="' . esc_attr($label) . '"><ul class="lp-pagination__list">';

        $renderNav('First', '', $current > 1 ? 1 : null);
        $renderNav('Prev', ' page', $current > 1 ? $current - 1 : null);

        // Always show the first/last two pages plus one on either side
        // of the current page, joined by ellipses for any wider gap.
        $edge = 2;
        $sibling = 1;
        $lastRendered = 0;

        for ($page = 1; $page <= $total; $page++) {
            $withinStartEdge = $page <= $edge;
            $withinEndEdge = $page > $total - $edge;
            $withinCurrentWindow = $page >= $current - $sibling && $page <= $current + $sibling;

            if (!$withinStartEdge && !$withinEndEdge && !$withinCurrentWindow) {
                continue;
            }

            if ($page - $lastRendered > 1) {
                $renderEllipsis();
            }

            $renderPage($page, $page === $current);
            $lastRendered = $page;
        }

        $renderNav('Next', ' page', $current < $total ? $current + 1 : null);
        $renderNav('Last', '', $current < $total ? $total : null);

        echo '</ul></nav>';
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = BasePath::get();

        return $path === '' ? ($base === '' ? '/' : $base . '/') : $base . '/' . $path;
    }
}

if (!function_exists('format_comment_content')) {
    /**
     * Escapes raw comment text, then auto-links bare http(s) URLs and
     * converts newlines to <br> — comments have no Markdown/HTML support.
     * Escaping happens first, so the matched URL text is already safe
     * HTML-attribute content; do NOT esc_url() it again or "&amp;" would
     * double-encode into "&amp;amp;", corrupting multi-param URLs.
     */
    function format_comment_content(string $raw): string
    {
        $escaped = esc_html($raw);

        $linked = preg_replace_callback(
            '#(https?://[^\s<]+)#i',
            static fn (array $matches): string => '<a href="' . $matches[1] . '" rel="nofollow ugc noopener" target="_blank">' . $matches[1] . '</a>',
            $escaped,
        ) ?? $escaped;

        return nl2br($linked);
    }
}

if (!function_exists('make_excerpt')) {
    /**
     * Builds a plain-text excerpt from raw (possibly HTML) content, for
     * contexts with no stored excerpt (currently RSS/Atom feed items).
     * Truncates to $wordCount words (55 matches classic WordPress).
     * Returns unescaped plain text — callers must esc_html() it.
     */
    function make_excerpt(string $content, int $wordCount = 55): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));

        if ($text === '') {
            return '';
        }

        $words = preg_split('/\s+/', $text) ?: [];

        if (count($words) <= $wordCount) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $wordCount)) . '…';
    }
}

if (!function_exists('highlight_terms')) {
    /**
     * Wraps case-insensitive matches of each query word in <mark> tags,
     * for search results. Takes already-esc_html()-escaped text so it's
     * safe to echo directly. Words shorter than 2 characters are skipped
     * to avoid noise; matching against escaped text means a query term
     * inside an HTML entity can highlight part of it — an accepted edge
     * case.
     */
    function highlight_terms(string $escapedText, string $query): string
    {
        $words = array_filter(
            preg_split('/\s+/', trim($query)) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= 2,
        );

        if ($words === []) {
            return $escapedText;
        }

        $pattern = '/(' . implode('|', array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            $words,
        )) . ')/iu';

        return preg_replace($pattern, '<mark class="lp-search-highlight">$1</mark>', $escapedText) ?? $escapedText;
    }
}

if (!function_exists('site_name')) {
    /**
     * The site's display name, reads SiteBranding, set once at
     * bootstrap from the "site_name" option.
     */
    function site_name(): string
    {
        return SiteBranding::siteName();
    }
}

if (!function_exists('site_logo_url')) {
    function site_logo_url(): ?string
    {
        return SiteBranding::logoUrl();
    }
}

if (!function_exists('favicon_url')) {
    function favicon_url(): ?string
    {
        return SiteBranding::faviconUrl();
    }
}

if (!function_exists('site_tagline')) {
    /**
     * The site's tagline/description, e.g. for display under the site
     * name/logo in a theme's header.
     */
    function site_tagline(): string
    {
        return SiteBranding::tagline();
    }
}

if (!function_exists('meta_description')) {
    /**
     * The site-wide fallback meta description, for views with no
     * natural excerpt (homepage, archives) and as the final fallback
     * elsewhere. A single post/page prefers its own excerpt instead.
     */
    function meta_description(): string
    {
        return SiteBranding::metaDescription();
    }
}

if (!function_exists('powered_by_html')) {
    /**
     * Fixed "Powered by Lumora Press" attribution, linked to the project
     * homepage — not a site-configurable option, so every theme calls
     * this instead of writing the link markup itself. Returns raw HTML,
     * safe to echo directly.
     */
    function powered_by_html(): string
    {
        return 'Powered by <a href="' . esc_url('https://coding.unloved-heart.net/scripts/lumorapress') . '" target="_blank" rel="noopener noreferrer">Lumora Press</a>';
    }
}

if (!function_exists('the_date')) {
    /**
     * Formats $date using the admin-configured "date_format" option
     * (default 'F j, Y' — PHP's date() format syntax) rather than a
     * hardcoded format string per template.
     */
    function the_date(\DateTimeInterface $date): string
    {
        return $date->format(SiteBranding::dateFormat());
    }
}

if (!function_exists('the_time')) {
    /**
     * Formats $date using the admin-configured "time_format" option
     * (default 'g:i a').
     */
    function the_time(\DateTimeInterface $date): string
    {
        return $date->format(SiteBranding::timeFormat());
    }
}

if (!function_exists('default_og_image_url')) {
    /**
     * Site-wide fallback Open Graph/Twitter Card image, used by a
     * theme's header when the item being rendered has no featured image
     * of its own (see has_post_thumbnail()/post_thumbnail_url()).
     */
    function default_og_image_url(): ?string
    {
        return SiteBranding::defaultOgImageUrl();
    }
}

if (!function_exists('search_engines_discouraged')) {
    /**
     * Whether the admin has asked search engines not to index this site.
     * Themes use this to decide whether to print a noindex meta tag;
     * /robots.txt itself is generated server-side, not by themes.
     */
    function search_engines_discouraged(): bool
    {
        return SiteBranding::discourageSearchEngines();
    }
}

if (!function_exists('custom_css')) {
    /**
     * Admin-authored CSS, echoed inside a <style> tag as-is (esc_html()
     * would corrupt it). Only manage_options/manage_themes admins can set
     * this, so stripping a literal "</style" is a defensive measure
     * against markup breakage, not a security boundary.
     */
    function custom_css(): string
    {
        return (string) preg_replace('/<\/style\s*>/i', '', SiteBranding::customCss());
    }
}

if (!function_exists('theme_option')) {
    /**
     * The current value of a registered Theme Option, by key — the
     * admin's saved value or the field's own default. Returns '' for an
     * unknown key rather than throwing, since checking for an
     * unregistered option is a normal, non-error case.
     */
    function theme_option(string $key): string
    {
        return ThemeOptionsBridge::instance()->value($key);
    }
}

if (!function_exists('theme_options_css')) {
    /**
     * Every registered Theme Option with a $cssVariable, rendered as one
     * `:root { --var: value; ... }` block. Echoed in <head>, right after
     * the theme's own stylesheet so the cascade lets these override it.
     */
    function theme_options_css(): string
    {
        return ThemeOptionsBridge::instance()->cssVariables();
    }
}

if (!function_exists('show_site_title')) {
    /**
     * Whether a theme's header should render its site-title/logo block.
     * Not every theme has one to guard — a theme without title/logo
     * markup simply never calls this.
     */
    function show_site_title(): bool
    {
        return theme_option('show_site_title') === '1';
    }
}

if (!function_exists('has_header_image')) {
    /**
     * Whether an administrator has uploaded a header image for the
     * active theme — checks the URL already resolved once at bootstrap
     * (see ThemeOptionsBridge::setHeaderImageUrl()).
     */
    function has_header_image(): bool
    {
        return ThemeOptionsBridge::headerImageUrl() !== null;
    }
}

if (!function_exists('header_image_url')) {
    /**
     * Mirrors site_logo_url()'s pattern above.
     */
    function header_image_url(): ?string
    {
        return ThemeOptionsBridge::headerImageUrl();
    }
}

if (!function_exists('header_height')) {
    /**
     * Only meaningful alongside header_image_url(). The CSS rule flows
     * through theme_options_css() via --lp-header-image-height; this
     * exists for a theme that needs the bare value directly.
     */
    function header_height(): string
    {
        return theme_option('header_height');
    }
}

if (!function_exists('has_welcome_message')) {
    /**
     * Self-guarding, no-op-when-empty check, same convention as
     * is_active_sidebar()/nav_menu(), so a theme can gate its markup
     * around welcome_message() without rendering empty wrapper markup.
     */
    function has_welcome_message(): bool
    {
        return theme_option('welcome_message') !== '';
    }
}

if (!function_exists('welcome_message_placement')) {
    /**
     * 'header' or 'sidebar'. header.php/sidebar.php each check this
     * before calling welcome_message(), so it renders in one place.
     */
    function welcome_message_placement(): string
    {
        return theme_option('welcome_message_placement');
    }
}

if (!function_exists('welcome_message')) {
    /**
     * Renders the admin-authored welcome message through the same
     * render_content() pipeline post/page content uses. No-ops when
     * empty; callers should still guard with has_welcome_message() &&
     * welcome_message_placement() === '...' to avoid double rendering.
     */
    function welcome_message(): void
    {
        $content = theme_option('welcome_message');

        if ($content === '') {
            return;
        }

        $format = ContentFormat::tryFrom(theme_option('welcome_message_format')) ?? ContentFormat::Html;

        echo '<div class="lp-welcome-message">' . render_content($content, $format) . '</div>';
    }
}

if (!function_exists('has_footer_html')) {
    /**
     * See has_welcome_message()'s docblock for the same
     * no-op-when-empty rationale.
     */
    function has_footer_html(): bool
    {
        return theme_option('footer_html') !== '';
    }
}

if (!function_exists('footer_html')) {
    /**
     * A per-theme Theme Option, unrelated to the fixed "Powered by
     * Lumora Press" attribution — additional footer content an admin
     * can add, not a replacement for it.
     */
    function footer_html(): void
    {
        $content = theme_option('footer_html');

        if ($content === '') {
            return;
        }

        $format = ContentFormat::tryFrom(theme_option('footer_html_format')) ?? ContentFormat::Html;

        echo '<div class="lp-footer-html">' . render_content($content, $format) . '</div>';
    }
}

if (!function_exists('csp_style_nonce')) {
    /**
     * This request's Content-Security-Policy nonce — every inline
     * <style> tag a theme emits must carry `nonce="<?= csp_style_nonce() ?>"`
     * or `style-src 'self'` silently drops it. Not needed for inline
     * `style="..."` attributes — nonces only apply to elements.
     */
    function csp_style_nonce(): string
    {
        return CspNonce::value();
    }
}

if (!function_exists('csp_script_nonce')) {
    /**
     * Same nonce as csp_style_nonce(), also added to script-src — every
     * inline <script> a theme or the Custom JavaScript widget emits must
     * carry `nonce="<?= csp_script_nonce() ?>"` or `script-src 'self'`
     * silently drops it.
     */
    function csp_script_nonce(): string
    {
        return CspNonce::value();
    }
}

if (!function_exists('home_url')) {
    /**
     * Like site_url(), but returns an absolute URL (scheme + host +
     * base path) rather than a root-relative one — for contexts where a
     * relative URL won't do (RSS feeds, outbound emails, canonical tags).
     */
    function home_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = SiteUrl::get();

        return $path === '' ? $base : $base . '/' . $path;
    }
}

if (!function_exists('preview_theme_link')) {
    /**
     * Threads an admin's active theme preview through an internal
     * navigation link — see ThemePreview::appendToLink(). Every
     * link-building function a visitor might click routes through this,
     * so theme templates never have to apply it themselves.
     */
    function preview_theme_link(string $url): string
    {
        return \LumoraPress\Core\Theme\ThemePreview::appendToLink($url);
    }
}

if (!function_exists('site_origin')) {
    /**
     * This site's own origin (scheme://host[:port], no path) — the shape
     * TrustedImageOrigins::parse() expects, for recognizing when an
     * embedded image is already covered by the CSP's own 'self'.
     */
    function site_origin(): string
    {
        $parts = parse_url(home_url());

        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}

if (!function_exists('canonical_url')) {
    /**
     * The current request's canonical URL — self-referencing, keeping
     * only pagination (?paged=) and search query (?q=). Every other
     * query parameter (tracking params, flash flags) is deliberately
     * dropped since none represent a distinct indexable page.
     */
    function canonical_url(): string
    {
        $requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
        $path = BasePath::stripFrom($requestPath);

        $query = [];

        if (isset($_GET['paged'])) {
            $query['paged'] = $_GET['paged'];
        }

        if (isset($_GET['q'])) {
            $query['q'] = $_GET['q'];
        }

        $url = home_url(ltrim($path, '/'));

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }
}

// Font Awesome developer API. Hook-based facades rather than a static
// bridge, since the plugin may not be installed — apply_filters()/
// do_action() are always safe to call, falling through to the default.

if (!function_exists('lp_fontawesome_enabled')) {
    function lp_fontawesome_enabled(): bool
    {
        return (bool) apply_filters('lp_fontawesome_enabled', false);
    }
}

if (!function_exists('lp_fontawesome_enqueue')) {
    /**
     * Signals that the current page needs Font Awesome's stylesheet —
     * call from a theme's header.php when markup uses `lp_icon()`/
     * hand-written `fa-*` classes outside the `[icon]` shortcode. Has no
     * effect on whether the stylesheet actually loads (gated on the
     * plugin's "enabled" setting).
     */
    function lp_fontawesome_enqueue(): void
    {
        do_action('lp_fontawesome_enqueue');
    }
}

if (!function_exists('lp_icon')) {
    /**
     * Renders one icon as `<i>` markup. Returns '' if no icon plugin is
     * active/enabled — safe to call unconditionally from theme markup.
     * See FontAwesomeService::icon() for the full $args shape.
     *
     * @param array<string, mixed> $args
     */
    function lp_icon(string $name, array $args = []): string
    {
        return (string) apply_filters('lp_icon', '', $name, $args);
    }
}

// Emoji Picker developer API — mirrors the Font Awesome hook-based
// facade pattern above.

if (!function_exists('lp_emoji_picker_enabled')) {
    function lp_emoji_picker_enabled(): bool
    {
        return (bool) apply_filters('lp_emoji_picker_enabled', false);
    }
}

if (!function_exists('lp_emoji_picker_editor_enabled')) {
    /**
     * Whether the picker button should render for the given editor
     * ('wysiwyg' or 'markdown') — see EmojiPickerService::editorEnabled().
     */
    function lp_emoji_picker_editor_enabled(string $editor): bool
    {
        return (bool) apply_filters('lp_emoji_picker_editor_enabled', false, $editor);
    }
}

if (!function_exists('lp_emoji_picker_data')) {
    /**
     * The picker's own rendering data (dataset, default category, recent-
     * list limit), used by editor-hosting admin views to build their
     * data-emoji-* attributes without a direct service reference. Falls
     * back to a small in-memory default when nothing is listening, so a
     * stale request just after plugin deactivation degrades gracefully.
     *
     * @return array{dataset: array<int, array{emoji: string, name: string, category: string, keywords: array<int, string>}>, defaultCategory: string, recentLimit: int}
     */
    function lp_emoji_picker_data(): array
    {
        /** @var array{dataset: array<int, array{emoji: string, name: string, category: string, keywords: array<int, string>}>, defaultCategory: string, recentLimit: int} */
        return apply_filters('lp_emoji_picker_data', ['dataset' => [], 'defaultCategory' => '', 'recentLimit' => 24]);
    }
}

if (!function_exists('lp_emoji_picker_button')) {
    /**
     * Renders a self-contained "Insert Emoji" trigger button + its
     * bundled dataset, for use outside the default editor toolbars.
     * Returns '' if the plugin isn't active/enabled. content-editor.js
     * wires up every `[data-lp-emoji-picker-trigger]`, inserting into
     * the nearest `<textarea>` in the same `<form>` unless
     * `$args['target']` names a different selector.
     *
     * @param array<string, mixed> $args 'label' (button text) and/or
     *     'target' (a CSS selector for the insertion target)
     */
    function lp_emoji_picker_button(array $args = []): string
    {
        return (string) apply_filters('lp_emoji_picker_trigger_html', '', $args);
    }
}

if (!function_exists('lp_register_icon_pack')) {
    /**
     * Lets a theme/plugin register an additional icon pack (e.g. a custom
     * SVG set) alongside Font Awesome's own "fa" pack.
     *
     * @param array<string, mixed> $config
     */
    function lp_register_icon_pack(string $key, array $config): void
    {
        do_action('lp_register_icon_pack', $key, $config);
    }
}

// See EditorPreferenceService's own docblock for the precedence these
// wrap — a static-bridge facade, same shape as theme_option() above.

if (!function_exists('registered_editors')) {
    /**
     * @return array<int, array{value: string, label: string}>
     */
    function registered_editors(): array
    {
        return ActiveEditorPreference::instance()->registeredEditors();
    }
}

if (!function_exists('get_default_editor')) {
    function get_default_editor(): ContentFormat
    {
        return ActiveEditorPreference::instance()->defaultEditor();
    }
}

if (!function_exists('get_active_editor')) {
    function get_active_editor(?int $userId): ContentFormat
    {
        return ActiveEditorPreference::instance()->activeEditor($userId);
    }
}
