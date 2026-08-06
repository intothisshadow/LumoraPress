<?php

/**
 * Front controller: routes every public request through bootstrap.php, or redirects to the installer when no config exists yet.
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

$root = __DIR__;

if (!is_file($root . '/config/config.php')) {
    /*
     * No config yet means PressConfig/BasePath aren't available, so the
     * installer's location has to be derived the same way install/index.php
     * derives its own — from the currently executing script's path — or a
     * subdirectory install redirects to the domain root and 404s.
     */
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    header('Location: ' . $scriptDir . '/install/');
    exit;
}

/** @var \LumoraPress\Core\Kernel $kernel */
$kernel = require $root . '/include/bootstrap.php';

/*
 * Route patterns (Router::get()/post() calls in include/bootstrap.php) are
 * registered without the install's base path prefix (e.g. "/admin", not
 * "/lumorapress/admin"), but $_SERVER['REQUEST_URI'] always carries it on
 * a subdirectory install. Without stripping it here first, every route
 * fails to match on any subdirectory install and every request 404s —
 * see DECISIONS.md's "Subdirectory installs never matched a route".
 */
$requestUri = \LumoraPress\Core\Http\BasePath::stripFrom($_SERVER['REQUEST_URI'] ?? '/');

/*
 * Theme preview (LP-044's optional "Preview theme" action): lets an admin
 * see a not-yet-activated theme rendered on the real front end without
 * touching the site-wide active_theme option, so no other visitor is
 * affected. Gated on the requester actually being logged in with
 * manage_themes — the query param alone must never be enough, since it's
 * otherwise just an anonymous, attacker-controlled input.
 */
$previewSlug = is_string($_GET['lp_preview_theme'] ?? null) ? trim($_GET['lp_preview_theme']) : '';

if ($previewSlug !== '') {
    $previewUser = $kernel->auth->user();

    if ($previewUser !== null && $previewUser->can('manage_themes')) {
        $previewInfo = $kernel->themes->infoFor($previewSlug);

        if ($previewInfo !== null) {
            $kernel->theme->setActiveTheme($previewInfo->slug);
            $kernel->theme->loadFunctions();
            \LumoraPress\Core\Theme\ThemePreview::activate($previewInfo);
        }
    }
}

if ($kernel->maintenance->shouldBlock($requestUri)) {
    $kernel->maintenance->respond();
} elseif (\LumoraPress\Core\Theme\ThemePreview::isActive()) {
    $exitQuery = $_GET;
    unset($exitQuery['lp_preview_theme']);
    $exitPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
    $exitUrl = site_url($exitPath) . ($exitQuery === [] ? '' : '?' . http_build_query($exitQuery));

    ob_start();
    $kernel->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
    $output = ob_get_clean();

    // Previewing a theme always requires a logged-in manage_themes user
    // (see the gate above), so CacheManager::isCacheable() is already
    // false here regardless — applyHeaders() is still called for the
    // explicit `Cache-Control: no-store, private` it sends in that case,
    // rather than leaving this response with no cache header at all.
    $kernel->cache->applyHeaders((string) $output);
    echo \LumoraPress\Core\Theme\ThemePreview::injectBanner((string) $output, $exitUrl);
} elseif ((string) (parse_url($requestUri, PHP_URL_PATH) ?? '') === '/admin' || str_starts_with((string) (parse_url($requestUri, PHP_URL_PATH) ?? ''), '/admin/')) {
    /*
     * LP-037's output-buffer wrapping below is skipped for /admin: that
     * route requires admin/index.php, which already opens its own,
     * never-explicitly-closed ob_start() (see that file's docblock —
     * "flushed automatically at script end"). Nesting a second buffer
     * around it would make ob_get_clean() here pop admin's inner buffer
     * instead of this one, leaving this one dangling. Not a concern for
     * caching anyway — SiteController never marks an admin response
     * cacheable, there's nothing for CacheManager to do here.
     */
    $kernel->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
} else {
    /*
     * LP-037: buffered (rather than letting the router's dispatched
     * action echo straight through, as before) so CacheManager can
     * compute a content-hash ETag and decide on a 304 only after the
     * full response is known — see CacheManager::applyHeaders()'s
     * docblock. Every non-admin route goes through this, not just
     * SiteController's cacheable ones: API/POST/other responses simply
     * never opted in (SiteController::markCacheableForGuests()), so they
     * still get a safe default (applyHeaders() only skips the body for a
     * genuinely cacheable, conditionally-matched request) and any header
     * a route already sent for itself (e.g. SiteController::feed()'s own
     * Cache-Control/ETag) is left alone, never overwritten.
     */
    ob_start();
    $kernel->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
    $output = ob_get_clean();

    if ($kernel->cache->applyHeaders((string) $output)) {
        echo $output;
    }
}
