<?php

/**
 * The admin Visitor Stats > Stats screen, provided by the bundled Visitor & Post View Statistics plugin (LPP-014).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Plugins\VisitorStats\PostViewService;
use LumoraPress\Plugins\VisitorStats\ViewStatsService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
$postViews = new PostViewService($kernel->database, $tablePrefix);
$viewStats = new ViewStatsService($kernel->database, $tablePrefix);

$trackPostViews = $kernel->config->option('track_post_views', '0') === '1';

// A plain link-driven ?range= toggle rather than a form/JS control — the
// whole page is just a re-render of these queries for a different $range,
// so a GET link is simpler and works with no JavaScript.
$allowedRanges = [7, 30, 90];
$range = (int) ($_GET['range'] ?? 30);

if (!in_array($range, $allowedRanges, true)) {
    $range = 30;
}

$limit = 10;
$totals = $trackPostViews ? $postViews->siteWideTotals() : ['today' => 0, 'this_week' => 0, 'this_month' => 0, 'all_time' => 0];
$dailyTotals = $trackPostViews ? $postViews->dailyTotals($range) : [];
$mostViewed = $trackPostViews ? $postViews->mostViewed($range, $limit) : [];
$topReferrers = $trackPostViews ? $viewStats->topReferrers($range, $limit) : [];
$topCountries = $trackPostViews ? $viewStats->topCountries($range, $limit) : [];
$topBrowsers = $trackPostViews ? $viewStats->topBrowsers($range, $limit) : [];
$topDevices = $trackPostViews ? $viewStats->topDevices($range, $limit) : [];

$maxDailyViews = max(1, ...array_map(static fn (array $row): int => $row['views'], $dailyTotals !== [] ? $dailyTotals : [['views' => 0]]));
?>
<h1 class="lp-admin__title">Visitor Stats</h1>

<?php if (!$trackPostViews): ?>
    <div class="lp-alert lp-alert--warning">
        Tracking is currently off, so there's no view data to show yet.
        Turn on "Track post views" on the
        <a href="<?= esc_url(admin_url('visitor-stats/settings')) ?>">Settings</a> screen to start collecting it.
    </div>
<?php endif; ?>

<div class="lp-stats__cards">
    <div class="lp-stats__card">
        <span class="lp-stats__card-value"><?= (int) $totals['today'] ?></span>
        <span class="lp-stats__card-label">Views Today</span>
    </div>
    <div class="lp-stats__card">
        <span class="lp-stats__card-value"><?= (int) $totals['this_week'] ?></span>
        <span class="lp-stats__card-label">Last 7 Days</span>
    </div>
    <div class="lp-stats__card">
        <span class="lp-stats__card-value"><?= (int) $totals['this_month'] ?></span>
        <span class="lp-stats__card-label">Last 30 Days</span>
    </div>
    <div class="lp-stats__card">
        <span class="lp-stats__card-value"><?= (int) $totals['all_time'] ?></span>
        <span class="lp-stats__card-label">All-Time Views</span>
    </div>
</div>

<div class="lp-stats__range-toggle">
    <?php foreach ($allowedRanges as $rangeOption): ?>
        <a
            class="lp-stats__range-toggle-option<?= $rangeOption === $range ? ' is-active' : '' ?>"
            href="<?= esc_url(admin_url('visitor-stats/stats')) ?>?range=<?= (int) $rangeOption ?>"
        ><?= (int) $rangeOption ?> Days</a>
    <?php endforeach; ?>
</div>

<section class="lp-admin__panel">
    <h2>Views Over Time</h2>
    <?php if ($dailyTotals === []): ?>
        <p class="lp-admin__widget-placeholder">No views recorded yet.</p>
    <?php else: ?>
        <div class="lp-stats__chart">
            <?php foreach ($dailyTotals as $day): ?>
                <div class="lp-stats__bar-row">
                    <span class="lp-stats__bar-label"><?= esc_html(date('M j', strtotime($day['date']))) ?></span>
                    <span class="lp-stats__bar-track">
                        <span class="lp-stats__bar-fill" data-style-width="<?= (int) round($day['views'] / $maxDailyViews * 100) ?>%"></span>
                    </span>
                    <span class="lp-stats__bar-value"><?= (int) $day['views'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="lp-admin__grid">
    <section class="lp-admin__panel">
        <h2>Top Posts</h2>
        <?php if ($mostViewed === []): ?>
            <p class="lp-admin__widget-placeholder">No views recorded yet.</p>
        <?php else: ?>
            <ul class="lp-admin__meta-list">
                <?php foreach ($mostViewed as $row): ?>
                    <?php $viewedPost = $kernel->posts->findById($row['post_id']); ?>
                    <?php if ($viewedPost === null) {
                        continue;
                    } ?>
                    <li>
                        <span><a href="<?= esc_url(admin_url('posts/new')) ?>?id=<?= (int) $viewedPost->id ?>"><?= esc_html($viewedPost->title) ?></a></span>
                        <span><?= (int) $row['views'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="lp-admin__panel">
        <h2>Top Referrers</h2>
        <?php if ($topReferrers === []): ?>
            <p class="lp-admin__widget-placeholder">No referrer data yet.</p>
        <?php else: ?>
            <ul class="lp-admin__meta-list">
                <?php foreach ($topReferrers as $row): ?>
                    <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="lp-admin__panel">
        <h2>Top Countries</h2>
        <?php if ($topCountries === []): ?>
            <p class="lp-admin__widget-placeholder">No country data yet — import GeoLite2 data on <a href="<?= esc_url(admin_url('visitor-stats/settings')) ?>">Settings</a> to enable this.</p>
        <?php else: ?>
            <ul class="lp-admin__meta-list">
                <?php foreach ($topCountries as $row): ?>
                    <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="lp-admin__grid">
    <section class="lp-admin__panel">
        <h2>Browser</h2>
        <?php if ($topBrowsers === []): ?>
            <p class="lp-admin__widget-placeholder">No browser data yet.</p>
        <?php else: ?>
            <ul class="lp-admin__meta-list">
                <?php foreach ($topBrowsers as $row): ?>
                    <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="lp-admin__panel">
        <h2>Device</h2>
        <?php if ($topDevices === []): ?>
            <p class="lp-admin__widget-placeholder">No device data yet.</p>
        <?php else: ?>
            <ul class="lp-admin__meta-list">
                <?php foreach ($topDevices as $row): ?>
                    <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<p class="lp-field__hint">
    This page shows aggregate, day-level counts only — the same privacy
    boundary the rest of this plugin holds to throughout. There's no
    live "who's online right now" view here, since that would require
    tracking individual visitor sessions, which this plugin deliberately
    never does.
</p>
