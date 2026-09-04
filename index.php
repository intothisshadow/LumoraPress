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
    // PressConfig/BasePath aren't available without a config file, so the
    // installer's location is derived from the executing script's path,
    // same as install/index.php does for itself.
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    header('Location: ' . $scriptDir . '/install/');
    exit;
}

/** @var \LumoraPress\Core\Kernel $kernel */
$kernel = require $root . '/include/bootstrap.php';

// Route patterns are registered without the install's base path prefix
// (e.g. "/admin", not "/lumorapress/admin"), but REQUEST_URI carries it
// on a subdirectory install, so it's stripped here before matching.
$requestUri = \LumoraPress\Core\Http\BasePath::stripFrom($_SERVER['REQUEST_URI'] ?? '/');

// Theme preview renders a not-yet-activated theme without touching the
// site-wide active_theme option. Gated on manage_themes — the query
// param alone is attacker-controlled and must never be trusted.
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

    // applyHeaders() is still called here (though isCacheable() is always
    // false for a preview) for its explicit `Cache-Control: no-store`.
    $kernel->cache->applyHeaders((string) $output);
    echo \LumoraPress\Core\Theme\ThemePreview::injectBanner((string) $output, $exitUrl);
} elseif ((string) (parse_url($requestUri, PHP_URL_PATH) ?? '') === '/admin' || str_starts_with((string) (parse_url($requestUri, PHP_URL_PATH) ?? ''), '/admin/')) {
    // /admin skips the output-buffer wrapping below: admin/index.php opens
    // its own ob_start() (flushed at script end), and nesting a second
    // buffer here would pop the wrong one via ob_get_clean().
    $kernel->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
} else {
    // Buffered so CacheManager can compute a content-hash ETag and decide
    // on a 304 only once the full response is known.
    ob_start();
    $kernel->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
    $output = ob_get_clean();

    if ($kernel->cache->applyHeaders((string) $output)) {
        echo $output;
    }
}
