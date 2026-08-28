<?php

/**
 * The Visitor & Post View Statistics plugin's main file (LPP-014): plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Visitor & Post View Statistics
 * Plugin URI: https://lumorapress.org/plugins/visitor-stats
 * Description: A local, privacy-respecting page-view counter for the Dashboard, with optional country/referrer/browser/device breakdowns — no third-party analytics service, no cookies/sessions, and no raw IP address, User-Agent string, or full referrer URL ever persisted. Off by default until configured in Settings.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: statistics, dashboard, privacy
 * Requires at least: 0.8.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\VisitorStats;

use LumoraPress\Core\ActiveConfig;
use LumoraPress\Core\ActiveKernel;
use LumoraPress\Models\Post;
use LumoraPress\Models\User;

require_once __DIR__ . '/src/PostViewService.php';
require_once __DIR__ . '/src/ViewStatsService.php';
require_once __DIR__ . '/src/UserAgentParser.php';

/*
 * SiteController::singlePost() fires this on every request (a no-op
 * unless something listens) — this is the only place tracking actually
 * happens. Reads the track_post_views option lazily (via ActiveConfig,
 * not eagerly at plugin-load time — see ActiveKernel's own docblock on
 * why a top-level plugin file can't touch the Kernel until a hook
 * callback actually runs) so activating this plugin never silently
 * starts collecting data before an admin has visited its Settings
 * screen and turned it on.
 */
add_action('single_post_viewed', static function (Post $post, bool $isGuest): void {
    if (!$isGuest || ActiveConfig::instance()->option('track_post_views', '') !== '1') {
        return;
    }

    $kernel = ActiveKernel::instance();
    $tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
    $postViews = new PostViewService($kernel->database, $tablePrefix);
    $viewStats = new ViewStatsService($kernel->database, $tablePrefix);
    $userAgentParser = new UserAgentParser();

    $postViews->recordView($post->id);

    $referrerHost = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    $siteHost = parse_url((string) site_url(), PHP_URL_HOST);
    $viewStats->recordReferrer(
        $referrerHost === null || $referrerHost === '' || $referrerHost === $siteHost ? 'Direct / None' : $referrerHost,
    );

    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $viewStats->recordBrowser($userAgentParser->browser($userAgent));
    $viewStats->recordDevice($userAgentParser->deviceType($userAgent));

    $countryCode = $viewStats->countryForIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($countryCode !== null) {
        $viewStats->recordCountry($countryCode);
    }
});

/*
 * Adds this plugin's own Dashboard panel — a no-op hook
 * (admin/views/dashboard.php) that any plugin could use, not something
 * this plugin owns. Gated on edit_posts, mirroring LP-006's Popular
 * Downloads panel's own upload_files gate.
 */
add_action('dashboard_widgets', static function (User $currentUser): void {
    if (!$currentUser->can('edit_posts') || ActiveConfig::instance()->option('track_post_views', '') !== '1') {
        return;
    }

    $kernel = ActiveKernel::instance();
    $tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
    $postViews = new PostViewService($kernel->database, $tablePrefix);
    $viewStats = new ViewStatsService($kernel->database, $tablePrefix);

    require __DIR__ . '/views/dashboard-widget.php';
});
