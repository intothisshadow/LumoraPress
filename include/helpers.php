<?php

declare(strict_types=1);

use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\Theme\SiteBranding;

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

if (!function_exists('admin_asset_url')) {
    /**
     * Builds a URL to a static file under admin/assets/ (e.g. its
     * stylesheet), honouring the install's base path — admin/assets/ is a
     * real, directly-reachable directory rather than a routed page, so
     * this deliberately doesn't go through admin_url().
     */
    function admin_asset_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = BasePath::get() . '/admin/assets';

        return $path === '' ? $base : $base . '/' . $path;
    }
}

if (!function_exists('render_pagination')) {
    /**
     * Renders a simple numbered pagination nav for the current path,
     * preserving it while swapping only the "paged" query parameter.
     *
     * @param array{page: int, totalPages: int} $pagination
     */
    function render_pagination(array $pagination, string $label = 'Posts pagination'): void
    {
        if ($pagination['totalPages'] <= 1) {
            return;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        parse_str((string) parse_url($requestUri, PHP_URL_QUERY), $query);

        echo '<nav class="lp-pagination" aria-label="' . esc_attr($label) . '"><ul class="lp-pagination__list">';

        for ($page = 1; $page <= $pagination['totalPages']; $page++) {
            $isCurrent = $page === $pagination['page'];

            if ($page > 1) {
                $query['paged'] = $page;
            } else {
                unset($query['paged']);
            }

            $url = $path . ($query !== [] ? '?' . http_build_query($query) : '');

            echo '<li class="lp-pagination__item' . ($isCurrent ? ' is-current' : '') . '">';
            echo $isCurrent
                ? '<span aria-current="page">' . esc_html((string) $page) . '</span>'
                : '<a href="' . esc_url($url) . '">' . esc_html((string) $page) . '</a>';
            echo '</li>';
        }

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

if (!function_exists('footer_copyright_text')) {
    /**
     * Admin-configured footer copyright text (LP-042 General settings).
     * Empty by default, in which case a theme's own footer keeps
     * rendering its existing auto-generated "© {year} {site name}." line
     * unchanged rather than showing nothing.
     */
    function footer_copyright_text(): string
    {
        return SiteBranding::footerCopyrightText();
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
