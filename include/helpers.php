<?php

declare(strict_types=1);

use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Http\SiteUrl;

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
