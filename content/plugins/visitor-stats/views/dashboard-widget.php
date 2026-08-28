<?php

/**
 * The Dashboard panel echoed by the Visitor & Post View Statistics plugin's 'dashboard_widgets' listener (LPP-014).
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

/**
 * @var \LumoraPress\Core\Kernel $kernel
 * @var PostViewService $postViews
 * @var ViewStatsService $viewStats
 */

$totals = $postViews->siteWideTotals();
$mostViewed = $postViews->mostViewed(7, 5);
$topCountries = $viewStats->topCountries(7, 5);
$topReferrers = $viewStats->topReferrers(7, 5);
$topBrowsers = $viewStats->topBrowsers(7, 5);
$topDevices = $viewStats->topDevices(7, 5);
?>
<section class="lp-admin__widget">
    <h2>Site Visitors</h2>
    <ul class="lp-admin__meta-list">
        <li><span>Today</span><span><?= (int) $totals['today'] ?></span></li>
        <li><span>This Week</span><span><?= (int) $totals['this_week'] ?></span></li>
        <li><span>This Month</span><span><?= (int) $totals['this_month'] ?></span></li>
        <li><span>All Time</span><span><?= (int) $totals['all_time'] ?></span></li>
    </ul>

    <h3 class="lp-admin__widget-subheading">Most Viewed (7 Days)</h3>
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

    <?php if ($topCountries !== []): ?>
        <h3 class="lp-admin__widget-subheading">Top Countries (7 Days)</h3>
        <ul class="lp-admin__meta-list">
            <?php foreach ($topCountries as $row): ?>
                <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($topReferrers !== []): ?>
        <h3 class="lp-admin__widget-subheading">Top Referrers (7 Days)</h3>
        <ul class="lp-admin__meta-list">
            <?php foreach ($topReferrers as $row): ?>
                <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($topBrowsers !== []): ?>
        <h3 class="lp-admin__widget-subheading">Browser</h3>
        <ul class="lp-admin__meta-list">
            <?php foreach ($topBrowsers as $row): ?>
                <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($topDevices !== []): ?>
        <h3 class="lp-admin__widget-subheading">Device</h3>
        <ul class="lp-admin__meta-list">
            <?php foreach ($topDevices as $row): ?>
                <li><span><?= esc_html($row['value']) ?></span><span><?= (int) $row['views'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
