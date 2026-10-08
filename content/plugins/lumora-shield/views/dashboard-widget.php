<?php

/**
 * The Dashboard panel echoed by the Lumora Shield plugin's 'dashboard_widgets' listener.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

/**
 * @var array{totals: array{today: int, this_week: int, this_month: int}, topIps: array<int, array{ipAddress: string, count: int}>, pendingComments: int} $report
 */
?>
<section class="lp-admin__widget" data-lp-sortable-item data-lp-sortable-id="lumora_shield">
    <h2>
        <span class="lp-drag-handle lp-admin__widget-drag" data-lp-drag-handle aria-hidden="true">&#10021;</span>
        Spam Protection
        <span class="lp-admin__widget-move">
            <button type="button" data-lp-sortable-move="up" aria-label="Move &ldquo;Spam Protection&rdquo; widget up">&#9650;</button>
            <button type="button" data-lp-sortable-move="down" aria-label="Move &ldquo;Spam Protection&rdquo; widget down">&#9660;</button>
        </span>
    </h2>
    <h3 class="lp-admin__widget-subheading">Flagged by Lumora Shield</h3>
    <ul class="lp-admin__meta-list">
        <li><span>Today</span><span><?= (int) $report['totals']['today'] ?></span></li>
        <li><span>This Week</span><span><?= (int) $report['totals']['this_week'] ?></span></li>
        <li><span>This Month</span><span><?= (int) $report['totals']['this_month'] ?></span></li>
    </ul>
    <p class="lp-field__hint">Counts only reach back as far as the log retention period.</p>

    <h3 class="lp-admin__widget-subheading">Awaiting Moderation</h3>
    <ul class="lp-admin__meta-list">
        <li>
            <span><a href="<?= esc_url(admin_url('comments')) ?>?status=pending">Pending comments</a></span>
            <span><?= (int) $report['pendingComments'] ?></span>
        </li>
    </ul>

    <h3 class="lp-admin__widget-subheading">Top Flagged IPs (30 Days)</h3>
    <?php if ($report['topIps'] === []): ?>
        <p class="lp-admin__widget-placeholder">Nothing flagged yet.</p>
    <?php else: ?>
        <ul class="lp-admin__meta-list">
            <?php foreach ($report['topIps'] as $row): ?>
                <li><span><code><?= esc_html($row['ipAddress']) ?></code></span><span><?= (int) $row['count'] ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a href="<?= esc_url(admin_url('maintenance/logs') . '#spam-events') ?>">View the spam log &rsaquo;</a></p>
</section>
