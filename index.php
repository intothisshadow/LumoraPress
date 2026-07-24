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

if ($kernel->maintenance->shouldBlock($requestUri)) {
    $kernel->maintenance->respond();
} else {
    $kernel->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
}
