<?php

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

    echo \LumoraPress\Core\Theme\ThemePreview::injectBanner((string) $output, $exitUrl);
} else {
    $kernel->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
}
