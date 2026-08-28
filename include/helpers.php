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

/**
 * General-purpose output escaping and localization helpers, available
 * everywhere (core, admin, themes, plugins).
 */

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
     * throughout admin views and SiteController (LP-082) — introduced as
     * a proof-of-concept adopted by admin/views/appearance/themes.php
     * first. Does not, by itself, make callers testable: PHPUnit still
     * cannot intercept a real exit(). Admin controller action methods
     * avoid that problem a different way (returning an AdminActionResult
     * instead of redirecting themselves — see
     * LumoraPress\Controllers\Admin\AdminActionResult) and call this only
     * from the thin view, after the fact.
     */
    function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('admin_asset_url')) {
    /**
     * Builds a URL to a static file under admin/assets/ (e.g. its
     * stylesheet), honouring the install's base path — admin/assets/ is a
     * real, directly-reachable directory rather than a routed page, so
     * this deliberately doesn't go through admin_url().
     *
     * Appends `?v={mtime}` when $path resolves to a real file on disk —
     * a cache-busting query string that changes automatically whenever
     * the file itself changes, with no version-number bookkeeping
     * needed. Without this, a CSS/JS fix shipped between releases (via
     * the manual ZIP update, LP-026) can sit invisible in every
     * browser's cache indefinitely, since the URL never changes to tell
     * the browser to re-fetch it — a real bug this exact gap caused
     * (see docs/CHANGELOG.md's entry for this date).
     */
    function admin_asset_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = BasePath::get() . '/admin/assets';

        if ($path === '') {
            return $base;
        }

        // dirname(__DIR__) rather than the LUMORA_ROOT constant — this
        // file is also loaded directly by the PHP Test Suite's bootstrap
        // (without the rest of include/bootstrap.php, which is where
        // LUMORA_ROOT is actually defined), so a self-contained path
        // derived from this file's own location works in both contexts.
        $absolutePath = dirname(__DIR__) . '/admin/assets/' . $path;
        $mtime = is_file($absolutePath) ? @filemtime($absolutePath) : false;

        return $base . '/' . $path . ($mtime !== false ? '?v=' . $mtime : '');
    }
}

if (!function_exists('core_asset_url')) {
    /**
     * Builds a URL to a static file under the top-level assets/ directory
     * — framework-owned JavaScript/CSS a theme's markup depends on but
     * doesn't itself provide (e.g. dynamic-style.js, needed by any widget
     * that emits a data-style-* attribute under this site's CSP). Mirrors
     * admin_asset_url()'s shape exactly, including its cache-busting
     * `?v={mtime}` query string, for a doc-root-level directory instead of
     * admin/assets/ — themes reference this instead of vendoring their own
     * copy of a file that has nothing theme-specific about it.
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
     * it while swapping only the "paged" query parameter. Adds
     * Previous/Next and First/Last controls, and truncates long page
     * ranges to the pages nearest the start, the end, and the current
     * page (joined by ellipses) instead of rendering a button per page —
     * a flat wall of buttons was the original behaviour and became
     * unusable on listings with dozens of pages (LP-100).
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

        // Renders a Previous/Next/First/Last control: a link when $targetPage
        // is reachable, or a disabled span at the start/end of the range so
        // the control's position in the row never shifts between pages.
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

        // Always show the first two and last two pages, plus one page on
        // either side of the current page, joined by ellipses for any gap
        // wider than one page — keeps the row short and scannable no
        // matter how many total pages there are.
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
     * converts newlines to <br> — comments have no Markdown/BBCode/rich
     * text support yet (see TODO.md's LP-012 "Future Enhancements"), just
     * plain text with linked URLs. Escaping happens first, so the regex
     * matches against already-entity-encoded text (e.g. a "&" inside a
     * query string is already "&amp;" by the time the regex sees it) —
     * the matched text is used as-is for both the href and the link text,
     * NOT passed through esc_url() again: it is already valid, safe
     * HTML-attribute content, and re-escaping it would double-encode
     * "&amp;" into "&amp;amp;", corrupting the href of any linked URL
     * with more than one query parameter (a real bug caught while
     * testing this exact feature).
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
     * contexts with no stored excerpt to fall back on (currently: RSS/Atom
     * feed items — see FeedService). Strips tags, collapses whitespace,
     * then truncates to $wordCount words (55 matches the classic WordPress
     * default). Returns unescaped plain text — callers must esc_html() it
     * same as any other output, matching format_comment_content()'s
     * escape-at-the-point-of-output convention.
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
     * for search results (LP-014). Takes already-esc_html()-escaped text
     * and operates on it — same escape-first ordering as
     * format_comment_content()/make_excerpt() callers — so it's safe to
     * echo directly. Words shorter than 2 characters are skipped to avoid
     * highlighting noise. Matching runs against the escaped text rather
     * than the original, so a query term that happens to appear inside an
     * HTML entity (e.g. searching "amp" against text containing "&amp;")
     * can highlight part of the entity — an accepted first-pass edge case.
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
     * The site's display name (LP-034 Branding), reads SiteBranding, set
     * once at bootstrap from the "site_name" option — the installer has
     * always saved it, but nothing read it back until this feature.
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
     * The site's tagline/description (LP-042 General settings), e.g. for
     * display under the site name/logo in a theme's header.
     */
    function site_tagline(): string
    {
        return SiteBranding::tagline();
    }
}

if (!function_exists('meta_description')) {
    /**
     * The site-wide fallback meta description (LP-042 General settings).
     * A single post/page view should prefer its own excerpt/content over
     * this — see header.php's $og_description, which already does that
     * for Open Graph — this exists for views with no natural excerpt
     * (homepage, archives) and as the final fallback everywhere else.
     */
    function meta_description(): string
    {
        return SiteBranding::metaDescription();
    }
}

if (!function_exists('powered_by_html')) {
    /**
     * Fixed "Powered by Lumora Press" attribution, linked to the project
     * homepage — not a site-configurable option (the old admin-editable
     * Footer copyright text setting was removed in favor of this), so
     * every theme calls this instead of writing the link markup itself.
     * Returns raw HTML, safe to echo directly (same "no further
     * escaping" contract as footer_html()).
     */
    function powered_by_html(): string
    {
        return 'Powered by <a href="' . esc_url('https://coding.unloved-heart.net/scripts/lumorapress') . '" target="_blank" rel="noopener noreferrer">Lumora Press</a>.';
    }
}

if (!function_exists('the_date')) {
    /**
     * Formats $date using the admin-configured "date_format" option
     * (LP-042 General settings, default 'F j, Y' — PHP's date() format
     * syntax, same as the hardcoded strings this replaces throughout the
     * default theme) rather than a hardcoded format string per template.
     */
    function the_date(\DateTimeInterface $date): string
    {
        return $date->format(SiteBranding::dateFormat());
    }
}

if (!function_exists('the_time')) {
    /**
     * Formats $date using the admin-configured "time_format" option
     * (LP-042 General settings, default 'g:i a').
     */
    function the_time(\DateTimeInterface $date): string
    {
        return $date->format(SiteBranding::timeFormat());
    }
}

if (!function_exists('default_og_image_url')) {
    /**
     * Site-wide fallback Open Graph/Twitter Card image (LP-042 General
     * settings), used by a theme's header when the item being rendered
     * has no featured image of its own (see LP-040's
     * has_post_thumbnail()/post_thumbnail_url()).
     */
    function default_og_image_url(): ?string
    {
        return SiteBranding::defaultOgImageUrl();
    }
}

if (!function_exists('search_engines_discouraged')) {
    /**
     * Whether the admin has asked search engines not to index this site
     * (LP-046 Reading settings, Settings &rsaquo; Reading &rsaquo; Search
     * Engine Visibility). Themes use this to decide whether to print
     * <meta name="robots" content="noindex,nofollow">; the actual
     * /robots.txt response is generated server-side by
     * SiteController::robotsTxt(), not by themes.
     */
    function search_engines_discouraged(): bool
    {
        return SiteBranding::discourageSearchEngines();
    }
}

if (!function_exists('custom_css')) {
    /**
     * Admin-authored CSS (LP-034), meant to be echoed inside a <style>
     * tag as-is — it's CSS, not HTML, so esc_html() would corrupt it.
     * Only manage_options/manage_themes administrators can set this (the
     * same trust level arbitrary theme/plugin PHP already assumes), so no
     * sanitization beyond stripping a literal "</style" is needed — that
     * strip is a defensive measure against accidental markup breakage,
     * not a security boundary.
     */
    function custom_css(): string
    {
        return (string) preg_replace('/<\/style\s*>/i', '', SiteBranding::customCss());
    }
}

if (!function_exists('theme_option')) {
    /**
     * The current value of a registered Theme Option (LP-034), by key —
     * either the administrator's saved value or the field's own default
     * if it was never set. Returns '' for an unknown key rather than
     * throwing, since a theme checking for an option a plugin hasn't
     * registered yet (or that a different theme registered) is a normal,
     * non-error case.
     */
    function theme_option(string $key): string
    {
        return ThemeOptionsBridge::instance()->value($key);
    }
}

if (!function_exists('theme_options_css')) {
    /**
     * Every registered Theme Option with a $cssVariable, rendered as one
     * `:root { --var: value; ... }` block — meant to be echoed inside a
     * <style> tag in <head>, immediately after the theme's own stylesheet
     * link so the cascade lets these values override it. See
     * ThemeOptions::cssVariables() for exactly which fields are included.
     */
    function theme_options_css(): string
    {
        return ThemeOptionsBridge::instance()->cssVariables();
    }
}

if (!function_exists('show_site_title')) {
    /**
     * LP-123 Header section. Whether a theme's header should render its
     * site-title/logo block. Not every theme has one to guard in the
     * first place — xena-theme's header bar carries no title/logo markup
     * at all by design (its bundled banner image replaces it), so that
     * theme's header.php simply never calls this.
     */
    function show_site_title(): bool
    {
        return theme_option('show_site_title') === '1';
    }
}

if (!function_exists('has_header_image')) {
    /**
     * LP-123 Header section. Whether an administrator has uploaded a
     * header image for the active theme — resolved once at bootstrap
     * (see ThemeOptionsBridge::setHeaderImageUrl()'s docblock for why),
     * so this just checks the already-resolved URL rather than the raw
     * media ID.
     */
    function has_header_image(): bool
    {
        return ThemeOptionsBridge::headerImageUrl() !== null;
    }
}

if (!function_exists('header_image_url')) {
    /**
     * LP-123 Header section — mirrors site_logo_url()'s pattern above.
     */
    function header_image_url(): ?string
    {
        return ThemeOptionsBridge::headerImageUrl();
    }
}

if (!function_exists('header_height')) {
    /**
     * LP-123 Header section. Only meaningful alongside header_image_url()
     * — flows through the header_height field's own $cssVariable
     * (--lp-header-image-height) via theme_options_css() for the actual
     * CSS rule, this helper exists only for a theme that needs the bare
     * value directly (e.g. an inline width/height attribute).
     */
    function header_height(): string
    {
        return theme_option('header_height');
    }
}

if (!function_exists('has_welcome_message')) {
    /**
     * LP-123 Welcome Message section. Self-guarding, no-op-when-empty
     * check — same convention as is_active_sidebar()/nav_menu() — so a
     * theme can gate its own markup around welcome_message() without
     * that call ever rendering empty wrapper markup.
     */
    function has_welcome_message(): bool
    {
        return theme_option('welcome_message') !== '';
    }
}

if (!function_exists('welcome_message_placement')) {
    /**
     * LP-123 Welcome Message section — 'header' or 'sidebar'. A theme's
     * header.php/sidebar.php each check this before calling
     * welcome_message(), so the message renders in exactly one place.
     */
    function welcome_message_placement(): string
    {
        return theme_option('welcome_message_placement');
    }
}

if (!function_exists('welcome_message')) {
    /**
     * LP-123 Welcome Message section. Renders the admin-authored welcome
     * message through the same render_content() pipeline post/page
     * content already uses (Markdown/HTML/Plain, per its companion
     * welcome_message_format field) — no-ops when empty, same convention
     * as custom_css()/nav_menu(). Callers should still guard with
     * has_welcome_message() && welcome_message_placement() === '...'
     * before calling, so the message isn't rendered in both possible
     * placements.
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
     * LP-123 Footer section. See has_welcome_message()'s docblock for the
     * same no-op-when-empty rationale.
     */
    function has_footer_html(): bool
    {
        return theme_option('footer_html') !== '';
    }
}

if (!function_exists('footer_html')) {
    /**
     * LP-123 Footer section. A per-theme Theme Option, unrelated to the
     * fixed "Powered by Lumora Press" attribution (powered_by_html()) —
     * this is additional footer content an admin can add, not a
     * replacement for it. See welcome_message()'s docblock for the
     * render_content() pipeline this shares.
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
     * or the default `style-src 'self'` policy silently drops it in the
     * browser (see CspNonce's own docblock for why this is so easy to
     * miss). Not needed for inline `style="..."` attributes — CSP nonces
     * only apply to <style>/<script> elements, not attributes.
     */
    function csp_style_nonce(): string
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

if (!function_exists('site_origin')) {
    /**
     * This site's own origin (scheme://host[:port], no path) — the same
     * "scheme://host[:port] only" shape TrustedImageOrigins::parse()
     * expects, for recognizing when an embedded image is already
     * same-origin and therefore already covered by the CSP's own 'self'
     * (no need to add it to the trusted-origins list at all).
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
     * The current request's canonical URL (LP-022) — self-referencing,
     * the simplest correct default for a site with no query-string-driven
     * content variants other than pagination (?paged=) and a search query
     * (?q=). Every other query parameter (tracking params like utm_*, the
     * one-time ?comment=posted flash flag, ...) is deliberately dropped:
     * none of them represent a genuinely different version of the page
     * that search engines should index separately.
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

/*
 * LPP-002 (Font Awesome) developer API. Plain hook-based facades rather
 * than a static bridge to a plugin class: the Font Awesome plugin may not
 * be installed or active, and add_filter()/apply_filters()/do_action()
 * are always safe to call whether or not anything is listening (they fall
 * through to the given default), so a theme/plugin calling these never
 * needs a function_exists() or "is this plugin active" guard of its own.
 */

if (!function_exists('lp_fontawesome_enabled')) {
    function lp_fontawesome_enabled(): bool
    {
        return (bool) apply_filters('lp_fontawesome_enabled', false);
    }
}

if (!function_exists('lp_fontawesome_enqueue')) {
    /**
     * Signals that the current page needs Font Awesome's stylesheet — call
     * this from a theme's header.php (or anywhere before it) when the
     * theme's own markup uses `lp_icon()`/hand-written `fa-*` classes
     * outside the `[icon]` shortcode, so the Font Awesome plugin's
     * (deferred) per-request diagnostics can attribute the load to it. Has
     * no effect on whether the stylesheet actually loads — see
     * FontAwesomeService::printHeadLinks()'s docblock for why that's
     * gated purely on the plugin's "enabled" setting.
     */
    function lp_fontawesome_enqueue(): void
    {
        do_action('lp_fontawesome_enqueue');
    }
}

if (!function_exists('lp_icon')) {
    /**
     * Renders one icon as `<i>` markup (e.g. `lp_icon('camera', ['label'
     * => 'Camera'])`). Returns '' if no icon plugin is active/enabled —
     * always safe to call unconditionally from theme markup. See
     * FontAwesomeService::icon() for the full $args shape (style, size,
     * rotate, flip, animation, color, class, label).
     *
     * @param array<string, mixed> $args
     */
    function lp_icon(string $name, array $args = []): string
    {
        return (string) apply_filters('lp_icon', '', $name, $args);
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

/*
 * LP-066/LP-067 (Default Editor). See EditorPreferenceService's own
 * docblock for the site-default/per-user/lock-toggle precedence these
 * wrap — a plain static-bridge facade, the same shape as theme_option()/
 * csp_style_nonce() above, since a theme/plugin has no constructor-
 * injection route to Kernel-wired services of its own.
 */

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
