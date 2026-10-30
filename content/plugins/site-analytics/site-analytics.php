<?php

/**
 * The Site Analytics plugin's main file: plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.26.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Site Analytics
 * Plugin URI: https://lumorapress.org/plugins/site-analytics
 * Description: Adds Plausible, Fathom, Matomo or Google Analytics 4 to your site by picking a provider and entering its ID, with no code to paste. Only the chosen provider's hosts are allowed through the Content-Security-Policy, and signed-in users and Do Not Track visitors are skipped. Also outputs Google Search Console and Bing verification tags.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: analytics, statistics, privacy
 * Requires at least: 0.25.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\SiteAnalytics;

use LumoraPress\Core\ActiveKernel;
use LumoraPress\Core\Http\BasePath;

require_once __DIR__ . '/src/SiteAnalyticsService.php';

$siteAnalytics = SiteAnalyticsService::instance();

// Reads the signed-in state lazily: a plugin's top-level file runs before
// the Kernel exists, so only a hook callback may touch it.
add_action('head_assets', static function () use ($siteAnalytics): void {
    echo $siteAnalytics->verificationTags();

    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $base = BasePath::get();
    $relativePath = $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

    if ($siteAnalytics->shouldTrack(ActiveKernel::instance()->auth->check(), is_maintenance_page(), $relativePath)) {
        echo $siteAnalytics->tag($siteAnalytics->loaderUrl());
    }
});

add_filter('csp_directives', static fn (array $directives): array => $siteAnalytics->filterCsp($directives));

add_filter('plugin_action_links_site-analytics', static fn (array $links): array => [
    ...$links,
    ['label' => 'Settings', 'url' => admin_url('settings/analytics')],
]);
