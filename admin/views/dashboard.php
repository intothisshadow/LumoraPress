<?php

/**
 * The admin Dashboard screen: an overview of recent activity and quick-glance widgets.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

require __DIR__ . '/partials/editor-layout-save.php';

$version = require LUMORA_ROOT . '/version.php';
$installDirectoryExists = is_dir(LUMORA_ROOT . '/install');
$maintenanceActive = $kernel->maintenance->isActive();

$updateStatus = ['available' => false, 'latest_version' => null, 'changelog_url' => null];

if ($currentUser->can('manage_options')) {
    // Cron-free "scheduled" check (LP-027) — see GitHubReleaseProvider::maybeCheckForUpdates()'s
    // docblock. Throttled internally, so this is safe on every Dashboard load.
    $kernel->githubUpdates->maybeCheckForUpdates();
    $updateStatus = $kernel->githubUpdates->cachedUpdateStatus((string) $version['version']);
}

/*
 * LP-134: reorderable Dashboard widgets. Every id below is a stable
 * identifier a signed-in user's saved widget order can reference —
 * reuses the exact same per-user JSON blob LP-083's Post/Page editor
 * sidebar order already established (UserService::
 * getEditorLayoutPreferences()/updateEditorLayoutPreferences(), screen
 * type 'dashboard' instead of 'post'/'page'; there's no per-widget
 * collapse concept here, so 'collapsed' is always empty for this
 * screen). The actual title/markup for each id still lives inline in
 * the switch() below, same as the editor sidebar's own boxes.
 */
$widgetTitles = [
    'recent_posts' => 'Recent Posts',
    'recent_comments' => 'Recent Comments',
    'quick_draft' => 'Quick Draft',
    'system_information' => 'System Information',
    'popular_downloads' => 'Popular Downloads',
    'update_status' => 'Update Status',
];
$availableWidgetIds = ['recent_posts', 'recent_comments', 'quick_draft', 'system_information'];

if ($currentUser->can('upload_files')) {
    $availableWidgetIds[] = 'popular_downloads';
}

$availableWidgetIds[] = 'update_status';

/*
 * A plugin declares its own dashboard widget id(s) via this filter so
 * its panel can be individually repositioned among the built-in
 * widgets above — see docs/DEVELOPER-APIS.md. The actual widget markup
 * still only ever comes from the existing do_action('dashboard_widgets')
 * call below (unchanged since LP-045/LPP-014); if more than one plugin
 * ever registers an id here at once, their combined output still
 * renders as a single contiguous block, positioned wherever the
 * earliest of their ids sorts in the saved order — independently
 * reordering multiple plugins' widgets from each other would need a
 * bigger change to how the 'dashboard_widgets' action itself works,
 * not something this ticket's single real consumer (Visitor & Post
 * View Statistics) needs.
 */
$pluginWidgetIds = array_values(array_filter((array) apply_filters('dashboard_widget_ids', [], $currentUser), 'is_string'));
$availableWidgetIds = array_merge($availableWidgetIds, $pluginWidgetIds);

$savedDashboardLayout = $kernel->users->getEditorLayoutPreferences($currentUser->id, 'dashboard');
$savedWidgetOrder = array_values(array_intersect($savedDashboardLayout['order'], $availableWidgetIds));
// Saved order first, then any widget not already in it appended at the
// end — covers a first-ever visit and a widget id introduced after a
// user's layout was last saved (mirrors posts/new.php's identical
// $boxOrder merge for the editor sidebar).
$widgetOrder = array_values(array_unique(array_merge($savedWidgetOrder, $availableWidgetIds)));

ob_start();
do_action('dashboard_widgets', $currentUser);
$pluginWidgetsHtml = ob_get_clean();
$pluginWidgetsRendered = false;
?>
<h1 class="lp-admin__title">Dashboard</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($installDirectoryExists): ?>
    <div class="lp-alert lp-alert--error">
        The <code>install/</code> directory still exists on the server. It should be removed
        for security — it can be left over from a fresh install that couldn't delete itself,
        or restored by a manual update. Delete it via FTP/SFTP or your hosting file manager.
    </div>
<?php endif; ?>

<?php if ($updateStatus['available']): ?>
    <div class="lp-alert lp-alert--warning">
        Lumora Press <strong><?= esc_html((string) $updateStatus['latest_version']) ?></strong> is available
        (you're running <?= esc_html((string) $version['version']) ?>).
        <a href="<?= esc_url(admin_url('maintenance/updates')) ?>">View Update</a>
    </div>
<?php endif; ?>

<?php if ($maintenanceActive && $currentUser->can('manage_options')): ?>
    <div class="lp-alert lp-alert--warning">
        Maintenance mode is currently <strong>ON</strong> — visitors see the maintenance page
        instead of the site.
        <form method="post" action="<?= esc_url(admin_url('settings/maintenance-mode')) ?>" class="lp-admin__inline-form">
            <?= Csrf::field('maintenance_toggle') ?>
            <input type="hidden" name="form" value="maintenance_toggle">
            <button type="submit" class="lp-button">Turn Off Maintenance Mode</button>
        </form>
    </div>
<?php elseif ($currentUser->can('manage_options')): ?>
    <div class="lp-admin__panel lp-admin__panel--maintenance-toggle">
        <form method="post" action="<?= esc_url(admin_url('settings/maintenance-mode')) ?>" class="lp-admin__inline-form">
            <?= Csrf::field('maintenance_toggle') ?>
            <input type="hidden" name="form" value="maintenance_toggle">
            <button type="submit" class="lp-button">Turn On Maintenance Mode</button>
        </form>
    </div>
<?php endif; ?>

<section class="lp-admin__panel lp-admin__panel--welcome">
    <h2>Welcome, <?= esc_html($currentUser->displayName) ?></h2>
    <p>Sit down. Write. Publish. Here is an overview of your site.</p>
</section>

<div
    class="lp-admin__grid"
    data-lp-sortable-group="dashboard"
    data-lp-sortable-ajax-url="<?= esc_url(admin_url('dashboard')) ?>"
    data-lp-sortable-ajax-csrf="<?= esc_attr(Csrf::token('editor_layout_dashboard')) ?>"
    data-lp-editor-screen-type="dashboard"
>
    <?php foreach ($widgetOrder as $widgetId): ?>
        <?php if (in_array($widgetId, $pluginWidgetIds, true)): ?>
            <?php
            /*
             * Every currently-active plugin's widget markup arrived in
             * one combined buffer (captured above via do_action()), so
             * it's echoed once, at the position of whichever of its
             * declared ids comes first in $widgetOrder — see the
             * $pluginWidgetIds comment above for why a second/third
             * plugin id can't be individually positioned yet. Each
             * plugin's own view is responsible for its own
             * data-lp-sortable-item/data-lp-sortable-id/drag-handle
             * markup (see content/plugins/visitor-stats/views/
             * dashboard-widget.php), not this loop.
             */
            if (!$pluginWidgetsRendered && $pluginWidgetsHtml !== '') {
                echo $pluginWidgetsHtml;
                $pluginWidgetsRendered = true;
            }
            continue;
            ?>
        <?php endif; ?>
        <section class="lp-admin__widget" data-lp-sortable-item data-lp-sortable-id="<?= esc_attr($widgetId) ?>">
            <h2>
                <span class="lp-drag-handle lp-admin__widget-drag" data-lp-drag-handle aria-hidden="true">&#10021;</span>
                <?= esc_html($widgetTitles[$widgetId] ?? $widgetId) ?>
                <span class="lp-admin__widget-move">
                    <button type="button" data-lp-sortable-move="up" aria-label="Move &ldquo;<?= esc_attr($widgetTitles[$widgetId] ?? $widgetId) ?>&rdquo; widget up">&#9650;</button>
                    <button type="button" data-lp-sortable-move="down" aria-label="Move &ldquo;<?= esc_attr($widgetTitles[$widgetId] ?? $widgetId) ?>&rdquo; widget down">&#9660;</button>
                </span>
            </h2>
            <?php switch ($widgetId):
                case 'recent_posts': ?>
                    <?php $recentPosts = $kernel->posts->paginateForAdmin(1, 5)['posts']; ?>
                    <?php if ($recentPosts === []): ?>
                        <p class="lp-admin__widget-placeholder">No posts yet.</p>
                    <?php else: ?>
                        <ul class="lp-admin__meta-list">
                            <?php foreach ($recentPosts as $recentPost): ?>
                                <li>
                                    <span><a href="<?= esc_url(admin_url('posts/new')) ?>?id=<?= (int) $recentPost->id ?>"><?= esc_html($recentPost->title) ?></a></span>
                                    <span class="lp-status-badge lp-status-badge--<?= esc_attr($recentPost->status->value) ?>"><?= esc_html($recentPost->status->label()) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php break;

                case 'recent_comments': ?>
                    <?php $recentComments = $kernel->comments->recentForAdmin(5); ?>
                    <?php if ($recentComments === []): ?>
                        <p class="lp-admin__widget-placeholder">No comments yet.</p>
                    <?php else: ?>
                        <ul class="lp-admin__meta-list">
                            <?php foreach ($recentComments as $row): ?>
                                <li>
                                    <span>
                                        <a href="<?= esc_url(admin_url('comments')) ?>?action=edit&id=<?= (int) $row['comment']->id ?>">
                                            <?= esc_html($row['comment']->guestName) ?> on &ldquo;<?= esc_html($row['contentTitle']) ?>&rdquo;
                                        </a>
                                    </span>
                                    <span class="lp-status-badge lp-status-badge--<?= esc_attr($row['comment']->status->value) ?>"><?= esc_html($row['comment']->status->label()) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php break;

                case 'quick_draft': ?>
                    <form class="lp-admin__quick-draft" method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>">
                        <?= Csrf::field('quick_draft') ?>
                        <input type="hidden" name="form" value="quick_draft">
                        <p class="lp-field">
                            <label for="quick-draft-title">Title</label>
                            <input type="text" id="quick-draft-title" name="title">
                        </p>
                        <p class="lp-field">
                            <label for="quick-draft-content">Content</label>
                            <textarea id="quick-draft-content" name="content" rows="4"></textarea>
                        </p>
                        <button type="submit" class="lp-button lp-button--primary">Save Draft</button>
                    </form>
                <?php break;

                case 'system_information': ?>
                    <ul class="lp-admin__meta-list">
                        <li><span>PHP Version</span><span><?= esc_html(PHP_VERSION) ?></span></li>
                        <li><span>Lumora Press Version</span><span><?= esc_html((string) $version['version']) ?></span></li>
                        <li><span>Active Theme</span><span><?= esc_html($kernel->theme->activeTheme() ?? '—') ?></span></li>
                    </ul>
                <?php break;

                case 'popular_downloads': ?>
                    <?php $popularDownloads = $kernel->mediaStats->mostDownloaded(5); ?>
                    <?php if ($popularDownloads === []): ?>
                        <p class="lp-admin__widget-placeholder">No downloads recorded yet.</p>
                    <?php else: ?>
                        <ul class="lp-admin__meta-list">
                            <?php foreach ($popularDownloads as $downloadItem): ?>
                                <li>
                                    <span><a href="<?= esc_url(admin_url('media/media')) ?>?action=edit&id=<?= (int) $downloadItem['id'] ?>"><?= esc_html((string) $downloadItem['file_name']) ?></a></span>
                                    <span><?= (int) $downloadItem['downloads'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php break;

                case 'update_status': ?>
                    <p>Running Lumora Press <?= esc_html((string) $version['version']) ?>.</p>
                    <?php if ($updateStatus['available']): ?>
                        <p><span class="lp-status-badge lp-status-badge--warning">Update available: <?= esc_html((string) $updateStatus['latest_version']) ?></span></p>
                    <?php elseif ($currentUser->can('manage_options')): ?>
                        <p>You're up to date.</p>
                    <?php endif; ?>
                    <p><a class="lp-button" href="<?= esc_url(admin_url('maintenance/updates')) ?>">Manage Updates</a></p>
                <?php break;
            endswitch; ?>
        </section>
    <?php endforeach; ?>

    <?php if (!$pluginWidgetsRendered && $pluginWidgetsHtml !== ''): ?>
        <?php
        /*
         * A plugin that echoes a dashboard widget without also
         * registering its id via the dashboard_widget_ids filter (an
         * older/third-party plugin written before LP-134) still gets
         * its panel shown — just always last, since there's no
         * declared id to place it by. See docs/DEVELOPER-APIS.md for
         * the up-to-date contract a plugin should follow instead.
         */
        echo $pluginWidgetsHtml;
        ?>
    <?php endif; ?>
</div>
