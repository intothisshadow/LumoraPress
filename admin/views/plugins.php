<?php

/**
 * The admin Plugins screen: activate, deactivate, install, and remove plugins.
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

use LumoraPress\Controllers\Admin\PluginsController;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\UpdateStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// The Grid/List view-mode toggle persists via a fire-and-forget JSON
// sub-action — the toggle itself switches instantly client-side; this
// just remembers the choice for next time.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'set_list_view') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    $requestedMode = ($_POST['mode'] ?? '') === 'list' ? 'list' : 'grid';

    if (!Csrf::verify('set_list_view', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);
        exit;
    }

    $kernel->users->setListViewMode($currentUser->id, 'plugins', $requestedMode);
    echo json_encode(['csrfToken' => Csrf::token('set_list_view')]);
    exit;
}

// Plugin Browser: a two-step install flow (stage -> confirm/cancel) so
// an upload colliding with an already-installed plugin can be reviewed
// and either replaced or cancelled.
//
// POST handling lives in PluginsController; this view dispatches to it
// and turns the AdminActionResult into a redirect or an $error string —
// same pattern LP-082 established for Themes/Posts/Pages/Categories. The
// Grid/List view-mode toggle above is the one JSON sub-action left
// inline, mirroring CategoriesController's own precedent.
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$pendingInstall = null;

// Bundled plugin updates reuse Maintenance > Updates' staged pipeline
// (download + validate, confirm, then a fetch()-driven continue loop via
// update-continue.js), scoped to a single plugin directory. Mirrors that
// page's handlers, including its no-JS redirect fallback.
if (($_GET['ajax'] ?? null) === 'progress') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    echo json_encode($kernel->updateProgress->read());
    exit;
}

$pluginUpdateForms = ['plugin_update_check', 'plugin_update_download', 'plugin_update_install', 'continue_plugin_update', 'plugin_update_cancel'];
$pluginUpdateSummary = null;
$pluginUpdateStageLabels = [
    'backup_files' => 'Backing up files…',
    'backup_database' => 'Backing up database…',
    'apply_files' => 'Replacing plugin files…',
    'clear_cache' => 'Clearing caches…',
    'cleanup' => 'Finishing up…',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($form, $pluginUpdateForms, true)) {
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $isAjaxContinueRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $respondJson = static function (array $payload): never {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    };

    if ($form === 'plugin_update_check' && Csrf::verify('plugin_update_check', $csrfToken)) {
        try {
            if ($kernel->githubUpdates->checkNow() === null) {
                $error = 'Could not reach GitHub, or the configured repository has no releases yet. Check the repository setting under Maintenance > Updates and try again.';
            } else {
                redirect(admin_url('plugins') . '?plugin_updates_checked=1');
            }
        } catch (\Throwable $exception) {
            $error = 'Could not check for updates: ' . $exception->getMessage();
        }
    } elseif ($form === 'plugin_update_download') {
        $slug = is_string($_POST['slug'] ?? null) ? $_POST['slug'] : '';
        $origin = in_array($_POST['origin'] ?? null, ['row', 'card', 'details'], true) ? $_POST['origin'] : 'row';

        if (!Csrf::verify('plugin_update_' . $origin . '_' . $slug, $csrfToken)) {
            $error = 'Your session expired. Please try again.';
        } elseif (!$kernel->updates->isBundledPlugin($slug)) {
            $error = 'Only plugins bundled with Lumora Press can be updated this way.';
        } else {
            $downloadPath = null;

            $kernel->updateProgress->reset('plugin_download', [
                ['key' => 'download', 'label' => 'Downloading plugin package'],
                ['key' => 'validate', 'label' => 'Validating package'],
                ['key' => 'compatibility', 'label' => 'Checking compatibility'],
            ]);

            // Releases the session lock before the network download so the progress poller isn't queued behind it.
            session_write_close();

            try {
                $downloadDir = rtrim(LUMORA_ROOT, '/') . '/storage/updates/downloads';

                if (!is_dir($downloadDir) && !mkdir($downloadDir, 0755, true) && !is_dir($downloadDir)) {
                    throw new \RuntimeException('Unable to prepare the downloads directory.');
                }

                $downloadPath = $downloadDir . '/' . bin2hex(random_bytes(16)) . '.zip';

                $kernel->updateProgress->stage('download');
                $kernel->githubUpdates->downloadPluginRelease($slug, $downloadPath);

                $pluginUpdateSummary = $kernel->updates->checkPluginPackage($slug, $downloadPath, $currentUser->id);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                $kernel->updateProgress->complete(false, $error);
            } finally {
                if ($downloadPath !== null && is_file($downloadPath)) {
                    unlink($downloadPath);
                }
            }

            session_start();
        }
    } elseif ($form === 'plugin_update_install' && Csrf::verify('plugin_update_install', $csrfToken)) {
        $installToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

        $kernel->updateProgress->reset('plugin_install', [
            ['key' => 'backup_files', 'label' => 'Backing up files'],
            ['key' => 'backup_database', 'label' => 'Backing up database'],
            ['key' => 'apply_files', 'label' => 'Replacing plugin files'],
            ['key' => 'clear_cache', 'label' => 'Clearing caches'],
            ['key' => 'cleanup', 'label' => 'Finishing up'],
        ]);

        session_write_close();

        try {
            $begin = $kernel->updates->beginInstall($installToken, $currentUser->id);
            redirect(admin_url('plugins') . '?plugin_update_token=' . urlencode($begin['token']));
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            $kernel->updateProgress->complete(false, $error);
            session_start();
        }
    } elseif ($form === 'continue_plugin_update' && Csrf::verify('plugin_update_continue', $csrfToken)) {
        $installToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

        try {
            $result = $kernel->updates->continueInstall($installToken);

            if ($result['done']) {
                $redirectUrl = admin_url('plugins') . '?' . http_build_query([
                    'plugin_updated' => 1,
                    'status' => $result['status']->value,
                    'message' => $result['message'],
                ]);

                if ($isAjaxContinueRequest) {
                    $respondJson(['done' => true, 'redirect' => $redirectUrl]);
                }

                redirect($redirectUrl);
            }

            if ($isAjaxContinueRequest) {
                $respondJson([
                    'done' => false,
                    'stage' => $result['stage'],
                    'stage_label' => $pluginUpdateStageLabels[$result['stage']] ?? $result['stage'],
                    'database_progress' => $result['database_progress'] ?? null,
                    'csrf_token' => Csrf::token('plugin_update_continue'),
                ]);
            }

            redirect(admin_url('plugins') . '?plugin_update_token=' . urlencode($installToken));
        } catch (\Throwable $exception) {
            $redirectUrl = admin_url('plugins') . '?plugin_update_error=' . urlencode($exception->getMessage());

            if ($isAjaxContinueRequest) {
                $respondJson(['done' => true, 'redirect' => $redirectUrl]);
            }

            redirect($redirectUrl);
        }
    } elseif ($form === 'plugin_update_cancel' && Csrf::verify('plugin_update_cancel', $csrfToken)) {
        try {
            $kernel->updates->cancel(is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
        } catch (\Throwable $exception) {
            // Nothing to clean up, or an already-expired token — safe to ignore.
        }

        redirect(admin_url('plugins'));
    } else {
        $error = 'Your session expired. Please try again.';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $form !== '' && $form !== 'set_list_view' && !in_array($form, $pluginUpdateForms, true)) {
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $controller = new PluginsController($kernel->pluginRegistry, $kernel->pluginInstaller, $kernel->config);

    $result = match ($form) {
        'activate_plugin' => $controller->activatePlugin($_POST, $csrfToken),
        'deactivate_plugin' => $controller->deactivatePlugin($_POST, $csrfToken),
        'delete_plugin' => $controller->deletePlugin($_POST, $csrfToken),
        'bulk_plugin_action' => $controller->bulkPluginAction($_POST, $csrfToken),
        'install_plugin' => $controller->installPlugin($_FILES, $csrfToken),
        'confirm_install_plugin' => $controller->confirmInstallPlugin($_POST, $csrfToken),
        'cancel_install_plugin' => $controller->cancelInstallPlugin($_POST, $csrfToken),
        default => null,
    };

    if ($result !== null) {
        if ($result->redirectUrl !== null) {
            redirect($result->redirectUrl);
        }

        $error = $result->errorMessage;
    }
}

$pendingToken = is_string($_GET['pending'] ?? null) ? $_GET['pending'] : null;

if ($pendingToken !== null) {
    try {
        $pendingInstall = $kernel->pluginInstaller->inspectStaged($pendingToken);
        $pendingInstall['token'] = $pendingToken;
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
        $pendingToken = null;
    }
}

$pluginList = $kernel->pluginRegistry->discover();
$listView = $kernel->users->getListViewMode($currentUser->id, 'plugins', 'list');

// LP-166: each plugin's own bootstrap registers its functional/settings
// links via add_filter("plugin_action_links_{$slug}", ...), mirroring
// WordPress's own Plugins screen mechanism. Computed once per slug here
// (rather than inside each of the three render loops below) since the
// list-table, grid card, and Details panel markup all render the same
// plugin's links.
$pluginActionLinksBySlug = [];

foreach ($pluginList as $info) {
    $pluginActionLinksBySlug[$info->slug] = apply_filters("plugin_action_links_{$info->slug}", []);
}

$pluginUpdateToken = is_string($_GET['plugin_update_token'] ?? null) ? $_GET['plugin_update_token'] : null;
$pluginUpdateProgress = null;

if ($pluginUpdateToken !== null) {
    try {
        $pluginUpdateProgress = $kernel->updates->installProgress($pluginUpdateToken);

        if ($pluginUpdateProgress['scope'] !== 'plugin') {
            $pluginUpdateProgress = null;
        }
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// Cached from the last GitHub check (here or on Maintenance > Updates) — no network call on page load.
$pluginUpdatesAvailable = $kernel->githubUpdates->cachedPluginUpdates($kernel->updates->installedPluginVersions());
$pluginUpdatesLastCheckedAt = (int) $kernel->config->option('update_last_checked_at', '0');
?>
<h1 class="lp-admin__title">Plugins</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['plugin_updated'])): ?>
    <?php $pluginUpdatedStatus = UpdateStatus::tryFrom((string) ($_GET['status'] ?? '')); ?>
    <div class="lp-alert <?= $pluginUpdatedStatus === UpdateStatus::Success ? 'lp-alert--success' : 'lp-alert--error' ?>">
        <?= esc_html((string) ($_GET['message'] ?? 'The plugin update finished.')) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['plugin_update_error'])): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html((string) $_GET['plugin_update_error']) ?></div>
<?php endif; ?>

<?php if (isset($_GET['plugin_updates_checked'])): ?>
    <div class="lp-alert lp-alert--success">
        <?= $pluginUpdatesAvailable === [] ? 'All bundled plugins are up to date.' : count($pluginUpdatesAvailable) . ' plugin update' . (count($pluginUpdatesAvailable) === 1 ? '' : 's') . ' available.' ?>
    </div>
<?php endif; ?>

<?php if ($pluginUpdateProgress !== null): ?>
    <section class="lp-admin__panel lp-plugin-update">
        <h2>Updating <?= esc_html((string) $pluginUpdateProgress['plugin_name']) ?></h2>
        <p>
            Updating from <strong><?= esc_html($pluginUpdateProgress['from_version']) ?></strong>
            to <strong><?= esc_html($pluginUpdateProgress['to_version']) ?></strong>&hellip;
        </p>
        <p class="lp-field__hint" data-lp-update-stage><?= esc_html($pluginUpdateStageLabels[$pluginUpdateProgress['stage']] ?? $pluginUpdateProgress['stage']) ?></p>
        <p class="lp-field__hint" data-lp-update-detail hidden></p>
        <div class="lp-alert lp-alert--warning">This page updates on its own — leave it open until it finishes.</div>

        <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" id="update-install-continue">
            <?= Csrf::field('plugin_update_continue') ?>
            <input type="hidden" name="form" value="continue_plugin_update">
            <input type="hidden" name="token" value="<?= esc_attr($pluginUpdateToken) ?>">
            <button type="submit" class="lp-button lp-button--primary">Continue</button>
        </form>
    </section>
    <?php return; ?>
<?php endif; ?>

<?php if ($pluginUpdateSummary !== null): ?>
    <section class="lp-admin__panel lp-plugin-update">
        <h2>Plugin Update Summary</h2>
        <p>
            Updating <strong><?= esc_html($pluginUpdateSummary['name']) ?></strong>
            from <strong><?= esc_html($pluginUpdateSummary['from_version']) ?></strong>
            to <strong><?= esc_html($pluginUpdateSummary['to_version']) ?></strong>
        </p>

        <?php if ($pluginUpdateSummary['blocking'] !== []): ?>
            <ul class="lp-install__requirements">
                <?php foreach ($pluginUpdateSummary['blocking'] as $problem): ?>
                    <li class="lp-alert lp-alert--error"><?= esc_html($problem) ?></li>
                <?php endforeach; ?>
            </ul>
            <p><a class="lp-button" href="<?= esc_url(admin_url('plugins')) ?>">Back to Plugins</a></p>
        <?php else: ?>
            <?php if ($pluginUpdateSummary['warnings'] !== []): ?>
                <ul class="lp-install__requirements">
                    <?php foreach ($pluginUpdateSummary['warnings'] as $warning): ?>
                        <li class="lp-alert lp-alert--warning"><?= esc_html($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p>Only this plugin's folder is replaced. Lumora Press will back up your files and database first, and roll back automatically if anything goes wrong.</p>

            <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('plugins')) ?>?ajax=progress" data-lp-update-progress-target="lp-plugin-update-progress-install">
                <?= Csrf::field('plugin_update_install') ?>
                <input type="hidden" name="form" value="plugin_update_install">
                <input type="hidden" name="token" value="<?= esc_attr($pluginUpdateSummary['token']) ?>">
                <button type="submit" class="lp-button lp-button--primary">Confirm &amp; Update</button>
            </form>
            <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                <?= Csrf::field('plugin_update_cancel') ?>
                <input type="hidden" name="form" value="plugin_update_cancel">
                <input type="hidden" name="token" value="<?= esc_attr($pluginUpdateSummary['token']) ?>">
                <button type="submit" class="lp-button">Cancel</button>
            </form>
            <ul id="lp-plugin-update-progress-install" class="lp-update-progress" hidden></ul>
        <?php endif; ?>
    </section>
    <?php return; ?>
<?php endif; ?>

<?php if (isset($_GET['activated'])): ?>
    <div class="lp-alert lp-alert--success">Plugin activated.</div>
<?php endif; ?>

<?php if (isset($_GET['deactivated'])): ?>
    <div class="lp-alert lp-alert--success">Plugin deactivated.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Plugin deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['bulk_activated'])): ?>
    <?php $bulkActivatedCount = (int) $_GET['bulk_activated']; ?>
    <?php $bulkActivateSkippedCount = (int) ($_GET['bulk_activate_skipped'] ?? 0); ?>
    <div class="lp-alert lp-alert--success">
        <?= $bulkActivatedCount ?> plugin<?= $bulkActivatedCount === 1 ? '' : 's' ?> activated.
        <?php if ($bulkActivateSkippedCount > 0): ?>
            <?= $bulkActivateSkippedCount ?> skipped (already active, or requires a newer PHP version).
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['bulk_deactivated'])): ?>
    <?php $bulkDeactivatedCount = (int) $_GET['bulk_deactivated']; ?>
    <?php $bulkDeactivateSkippedCount = (int) ($_GET['bulk_deactivate_skipped'] ?? 0); ?>
    <div class="lp-alert lp-alert--success">
        <?= $bulkDeactivatedCount ?> plugin<?= $bulkDeactivatedCount === 1 ? '' : 's' ?> deactivated.
        <?php if ($bulkDeactivateSkippedCount > 0): ?>
            <?= $bulkDeactivateSkippedCount ?> skipped (already inactive).
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['bulk_deleted'])): ?>
    <?php $bulkDeletedCount = (int) $_GET['bulk_deleted']; ?>
    <?php $bulkSkippedCount = (int) ($_GET['bulk_skipped'] ?? 0); ?>
    <div class="lp-alert lp-alert--success">
        <?= $bulkDeletedCount ?> plugin<?= $bulkDeletedCount === 1 ? '' : 's' ?> deleted.
        <?php if ($bulkSkippedCount > 0): ?>
            <?= $bulkSkippedCount ?> skipped (still active, or already removed).
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['installed'])): ?>
    <div class="lp-alert lp-alert--success">Installed &ldquo;<?= esc_html((string) $_GET['installed']) ?>&rdquo;.</div>
<?php endif; ?>

<?php if ($pendingInstall !== null): ?>
    <section class="lp-admin__panel lp-plugin-pending">
        <h2>Confirm Plugin Installation</h2>

        <?php if ($pendingInstall['existing'] !== null): ?>
            <p>
                A plugin named &ldquo;<?= esc_html($pendingInstall['existing']->name) ?>&rdquo;
                (version <?= esc_html($pendingInstall['existing']->version !== '' ? $pendingInstall['existing']->version : 'unknown') ?>)
                is already installed. This archive contains
                &ldquo;<?= esc_html($pendingInstall['name']) ?>&rdquo;
                (version <?= esc_html($pendingInstall['version'] !== '' ? $pendingInstall['version'] : 'unknown') ?>).
            </p>
        <?php else: ?>
            <p>
                Ready to install &ldquo;<?= esc_html($pendingInstall['name']) ?>&rdquo;
                <?php if ($pendingInstall['version'] !== ''): ?>(version <?= esc_html($pendingInstall['version']) ?>)<?php endif; ?>.
            </p>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('plugins')) ?>">
            <?= Csrf::field('confirm_install_plugin') ?>
            <input type="hidden" name="form" value="confirm_install_plugin">
            <input type="hidden" name="token" value="<?= esc_attr($pendingInstall['token']) ?>">
            <?php if ($pendingInstall['existing'] !== null): ?>
                <input type="hidden" name="replace" value="1">
            <?php endif; ?>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="activate_now" value="1" checked>
                Activate this plugin now
            </label>

            <button type="submit" class="lp-button lp-button--primary">
                <?= $pendingInstall['existing'] !== null ? 'Replace Installed Plugin' : 'Install Plugin' ?>
            </button>
        </form>
        <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
            <?= Csrf::field('cancel_install_plugin') ?>
            <input type="hidden" name="form" value="cancel_install_plugin">
            <input type="hidden" name="token" value="<?= esc_attr($pendingInstall['token']) ?>">
            <button type="submit" class="lp-button">Cancel</button>
        </form>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Install a Plugin</h2>
    <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('install_plugin') ?>
        <input type="hidden" name="form" value="install_plugin">

        <p class="lp-field">
            <label for="plugin-zip">Plugin ZIP file</label>
            <input type="file" id="plugin-zip" name="plugin_zip" accept=".zip" required>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Upload &amp; Review</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Installed Plugins</h2>

    <div class="lp-plugin-updates-bar">
        <p class="lp-plugin-updates-bar__status">
            <?php if ($pluginUpdatesAvailable !== []): ?>
                <span class="lp-status-badge lp-status-badge--warning"><?= count($pluginUpdatesAvailable) ?> update<?= count($pluginUpdatesAvailable) === 1 ? '' : 's' ?> available</span>
            <?php endif; ?>
            Bundled plugin updates last checked:
            <?= $pluginUpdatesLastCheckedAt > 0 ? esc_html(date('M j, Y g:i A T', $pluginUpdatesLastCheckedAt)) : 'Never' ?>
        </p>
        <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
            <?= Csrf::field('plugin_update_check') ?>
            <input type="hidden" name="form" value="plugin_update_check">
            <button type="submit" class="lp-button lp-button--secondary">Check for Updates</button>
        </form>
    </div>
    <ul id="lp-plugin-update-progress" class="lp-update-progress" hidden></ul>

    <?php if (count($pluginList) > 1): ?>
        <div class="lp-plugin-toolbar">
            <p class="lp-field lp-plugin-search">
                <label for="plugin-search">Search plugins</label>
                <input type="search" id="plugin-search" data-lp-plugin-search placeholder="Search by name, author, or tag&hellip;">
            </p>
            <p class="lp-field lp-plugin-filter">
                <label for="plugin-filter">Filter</label>
                <select id="plugin-filter" data-lp-plugin-filter>
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="update">Update Available</option>
                </select>
            </p>
        </div>

        <p class="lp-plugin-view-toggle" role="group" aria-label="View" data-lp-plugin-view-toggle data-csrf="<?= esc_attr(Csrf::token('set_list_view')) ?>">
            <button
                type="button"
                class="lp-button<?= $listView === 'grid' ? ' lp-button--primary' : ' lp-button--secondary' ?>"
                data-lp-plugin-view-button="grid"
                aria-pressed="<?= $listView === 'grid' ? 'true' : 'false' ?>"
            >Grid</button>
            <button
                type="button"
                class="lp-button<?= $listView === 'list' ? ' lp-button--primary' : ' lp-button--secondary' ?>"
                data-lp-plugin-view-button="list"
                aria-pressed="<?= $listView === 'list' ? 'true' : 'false' ?>"
            >List</button>
        </p>
    <?php endif; ?>

    <?php if ($pluginList === []): ?>
        <p class="lp-admin__widget-placeholder">No plugins are installed yet. Upload one below to get started.</p>
    <?php else: ?>
        <div class="lp-plugin-view-wrapper" data-lp-plugin-view-wrapper data-view="<?= esc_attr($listView) ?>">
            <form id="plugins-bulk-form" method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__bulk-actions" data-lp-bulk-form>
                <?= Csrf::field('bulk_plugin_action') ?>
                <input type="hidden" name="form" value="bulk_plugin_action">
                <label class="lp-visually-hidden" for="plugins-bulk-action">Bulk action</label>
                <select id="plugins-bulk-action" name="bulk_action" data-lp-bulk-confirm-select>
                    <option value="">Bulk actions</option>
                    <option value="activate">Activate</option>
                    <option value="deactivate">Deactivate</option>
                    <option value="delete" data-lp-confirm="Delete the selected plugins permanently? This cannot be undone.">Delete</option>
                </select>
                <button type="submit" class="lp-button lp-button--secondary" data-lp-bulk-confirm-apply>Apply</button>
            </form>
        <table class="lp-table lp-plugin-table" data-lp-plugin-table>
            <thead>
                <tr>
                    <th scope="col">
                        <label class="lp-visually-hidden" for="plugins-select-all">Select all</label>
                        <input type="checkbox" id="plugins-select-all" data-lp-select-all="plugin_slugs[]" data-lp-select-all-scope="table">
                    </th>
                    <th scope="col">Plugin</th>
                    <th scope="col">Status</th>
                    <th scope="col">Version</th>
                    <th scope="col">Author</th>
                    <th scope="col">Description</th>
                    <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pluginList as $info): ?>
                    <?php
                    $rowSearchHaystack = strtolower($info->name . ' ' . $info->author . ' ' . implode(' ', $info->tags));
                    $rowStatusValue = $info->isActive ? 'active' : 'inactive';
                    $rowTemplateId = 'lp-plugin-details-' . $info->slug;
                    $rowActivateFormId = 'plugin-activate-form-row-' . $info->slug;
                    $rowDeactivateFormId = 'plugin-deactivate-form-row-' . $info->slug;
                    $rowDeleteFormId = 'plugin-delete-form-row-' . $info->slug;
                    $rowUpdateFormId = 'plugin-update-form-row-' . $info->slug;
                    $rowUpdate = $pluginUpdatesAvailable[$info->slug] ?? null;
                    ?>
                    <tr
                        data-lp-plugin-row
                        data-plugin-search="<?= esc_attr($rowSearchHaystack) ?>"
                        data-plugin-status="<?= esc_attr($rowStatusValue) ?>"
                        <?= $rowUpdate !== null ? 'data-plugin-update' : '' ?>
                    >
                        <td>
                            <label class="lp-visually-hidden" for="plugin-select-<?= esc_attr($info->slug) ?>">Select "<?= esc_html($info->name) ?>"</label>
                            <input type="checkbox" id="plugin-select-<?= esc_attr($info->slug) ?>" name="plugin_slugs[]" value="<?= esc_attr($info->slug) ?>" form="plugins-bulk-form">
                        </td>
                        <td><?= esc_html($info->name) ?></td>
                        <td>
                            <?php if ($info->isActive): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--active">Active</span>
                            <?php elseif ($info->isDisabled): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--disabled">Disabled</span>
                            <?php else: ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= esc_html($info->version) ?>
                            <?php if ($rowUpdate !== null): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--update">Update available: <?= esc_html($rowUpdate['version']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc_html($info->author) ?></td>
                        <td><?= esc_html($info->description) ?></td>
                        <td class="lp-admin__row-actions">
                            <?php if ($rowUpdate !== null): ?>
                                <span class="lp-admin__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('plugin_update_row_' . $info->slug)) ?>" form="<?= esc_attr($rowUpdateFormId) ?>">
                                    <input type="hidden" name="form" value="plugin_update_download" form="<?= esc_attr($rowUpdateFormId) ?>">
                                    <input type="hidden" name="origin" value="row" form="<?= esc_attr($rowUpdateFormId) ?>">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>" form="<?= esc_attr($rowUpdateFormId) ?>">
                                    <button type="submit" class="lp-button--link" form="<?= esc_attr($rowUpdateFormId) ?>">Update to v<?= esc_html($rowUpdate['version']) ?></button>
                                </span>
                            <?php endif; ?>
                            <?php foreach ($pluginActionLinksBySlug[$info->slug] as $actionLink): ?>
                                <a class="lp-button--link" href="<?= esc_url($actionLink['url']) ?>"><?= esc_html($actionLink['label']) ?></a>
                            <?php endforeach; ?>
                            <button type="button" class="lp-button--link" data-lp-plugin-details-trigger data-plugin-template="<?= esc_attr($rowTemplateId) ?>">Details</button>
                            <?php if ($info->isActive): ?>
                                <span class="lp-admin__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('deactivate_plugin_row_' . $info->slug)) ?>" form="<?= esc_attr($rowDeactivateFormId) ?>">
                                    <input type="hidden" name="form" value="deactivate_plugin" form="<?= esc_attr($rowDeactivateFormId) ?>">
                                    <input type="hidden" name="origin" value="row" form="<?= esc_attr($rowDeactivateFormId) ?>">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>" form="<?= esc_attr($rowDeactivateFormId) ?>">
                                    <button type="submit" class="lp-button--link lp-button--link--danger" form="<?= esc_attr($rowDeactivateFormId) ?>">Deactivate</button>
                                </span>
                            <?php else: ?>
                                <?php if (!$info->isDisabled): ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('activate_plugin_row_' . $info->slug)) ?>" form="<?= esc_attr($rowActivateFormId) ?>">
                                        <input type="hidden" name="form" value="activate_plugin" form="<?= esc_attr($rowActivateFormId) ?>">
                                        <input type="hidden" name="origin" value="row" form="<?= esc_attr($rowActivateFormId) ?>">
                                        <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>" form="<?= esc_attr($rowActivateFormId) ?>">
                                        <button type="submit" class="lp-button--link" form="<?= esc_attr($rowActivateFormId) ?>">Activate</button>
                                    </span>
                                <?php endif; ?>
                                <span class="lp-admin__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('delete_plugin_row_' . $info->slug)) ?>" form="<?= esc_attr($rowDeleteFormId) ?>">
                                    <input type="hidden" name="form" value="delete_plugin" form="<?= esc_attr($rowDeleteFormId) ?>">
                                    <input type="hidden" name="origin" value="row" form="<?= esc_attr($rowDeleteFormId) ?>">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>" form="<?= esc_attr($rowDeleteFormId) ?>">
                                    <button type="submit" class="lp-button--link lp-button--link--danger" form="<?= esc_attr($rowDeleteFormId) ?>" data-lp-confirm="Delete this plugin permanently? This cannot be undone.">Delete</button>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Out-of-band target forms for each row's action buttons —
        // standalone <form>s the buttons point at via form="", since a
        // <form> can't nest inside plugins-bulk-form.
        foreach ($pluginList as $info):
            ?>
            <?php if (isset($pluginUpdatesAvailable[$info->slug])): ?>
                <form id="plugin-update-form-row-<?= esc_attr($info->slug) ?>" method="post" action="<?= esc_url(admin_url('plugins')) ?>" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('plugins')) ?>?ajax=progress" data-lp-update-progress-target="lp-plugin-update-progress"></form>
            <?php endif; ?>
            <?php if ($info->isActive): ?>
                <form id="plugin-deactivate-form-row-<?= esc_attr($info->slug) ?>" method="post" action="<?= esc_url(admin_url('plugins')) ?>"></form>
            <?php else: ?>
                <?php if (!$info->isDisabled): ?>
                    <form id="plugin-activate-form-row-<?= esc_attr($info->slug) ?>" method="post" action="<?= esc_url(admin_url('plugins')) ?>"></form>
                <?php endif; ?>
                <form id="plugin-delete-form-row-<?= esc_attr($info->slug) ?>" method="post" action="<?= esc_url(admin_url('plugins')) ?>"></form>
            <?php endif; ?>
            <?php
        endforeach;
        ?>

        <div class="lp-plugin-grid" data-lp-plugin-grid>
            <?php foreach ($pluginList as $info): ?>
                <?php
                $searchHaystack = strtolower($info->name . ' ' . $info->author . ' ' . implode(' ', $info->tags));
                $templateId = 'lp-plugin-details-' . $info->slug;
                $statusFilterValue = $info->isActive ? 'active' : 'inactive';
                $cardUpdate = $pluginUpdatesAvailable[$info->slug] ?? null;
                ?>
                <div
                    class="lp-plugin-card<?= $info->isActive ? ' lp-plugin-card--active' : '' ?><?= $info->isDisabled ? ' lp-plugin-card--disabled' : '' ?>"
                    data-lp-plugin-card
                    data-plugin-search="<?= esc_attr($searchHaystack) ?>"
                    data-plugin-status="<?= esc_attr($statusFilterValue) ?>"
                    <?= $cardUpdate !== null ? 'data-plugin-update' : '' ?>
                >
                    <div class="lp-plugin-card__screenshot-wrap">
                        <?php if ($info->screenshotUrl !== null): ?>
                            <img class="lp-plugin-card__screenshot" src="<?= esc_url($info->screenshotUrl) ?>" alt="">
                        <?php else: ?>
                            <div class="lp-plugin-card__screenshot lp-plugin-card__screenshot--placeholder" aria-hidden="true"></div>
                        <?php endif; ?>
                    </div>
                    <div class="lp-plugin-card__body">
                        <h3 class="lp-plugin-card__name">
                            <?= esc_html($info->name) ?>
                            <?php if ($info->isActive): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--active">Active</span>
                            <?php elseif ($info->isDisabled): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--disabled">Disabled</span>
                            <?php else: ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--inactive">Inactive</span>
                            <?php endif; ?>
                        </h3>
                        <?php if ($info->description !== ''): ?>
                            <p class="lp-plugin-card__description"><?= esc_html($info->description) ?></p>
                        <?php endif; ?>
                        <p class="lp-plugin-card__meta">
                            <?php if ($info->version !== ''): ?><span>Version <?= esc_html($info->version) ?></span><?php endif; ?>
                            <?php if ($info->author !== ''): ?><span>By <?= esc_html($info->author) ?></span><?php endif; ?>
                        </p>
                        <?php if ($cardUpdate !== null): ?>
                            <p class="lp-plugin-card__update">
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--update">Update available: <?= esc_html($cardUpdate['version']) ?></span>
                            </p>
                        <?php endif; ?>
                        <div class="lp-plugin-card__actions">
                            <?php if ($cardUpdate !== null): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('plugins')) ?>?ajax=progress" data-lp-update-progress-target="lp-plugin-update-progress">
                                    <?= Csrf::field('plugin_update_card_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="plugin_update_download">
                                    <input type="hidden" name="origin" value="card">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--primary">Update</button>
                                </form>
                            <?php endif; ?>
                            <?php foreach ($pluginActionLinksBySlug[$info->slug] as $actionLink): ?>
                                <a class="lp-button lp-button--secondary" href="<?= esc_url($actionLink['url']) ?>"><?= esc_html($actionLink['label']) ?></a>
                            <?php endforeach; ?>
                            <button type="button" class="lp-button lp-button--secondary" data-lp-plugin-details-trigger data-plugin-template="<?= esc_attr($templateId) ?>">Details</button>
                            <?php if ($info->isActive): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('deactivate_plugin_card_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="deactivate_plugin">
                                    <input type="hidden" name="origin" value="card">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--danger">Deactivate</button>
                                </form>
                            <?php else: ?>
                                <?php if (!$info->isDisabled): ?>
                                    <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                        <?= Csrf::field('activate_plugin_card_' . $info->slug) ?>
                                        <input type="hidden" name="form" value="activate_plugin">
                                        <input type="hidden" name="origin" value="card">
                                        <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                        <button type="submit" class="lp-button lp-button--primary">Activate</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this plugin permanently? This cannot be undone.">
                                    <?= Csrf::field('delete_plugin_card_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="delete_plugin">
                                    <input type="hidden" name="origin" value="card">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <template id="<?= esc_attr($templateId) ?>">
                    <div class="lp-plugin-details">
                        <?php if ($info->screenshots !== []): ?>
                            <div class="lp-plugin-details__gallery">
                                <?php foreach ($info->screenshots as $screenshot): ?>
                                    <img src="<?= esc_url($screenshot) ?>" alt="<?= esc_attr($info->name) ?> screenshot">
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="lp-plugin-details__gallery lp-plugin-details__gallery--placeholder" aria-hidden="true"></div>
                        <?php endif; ?>

                        <h3 class="lp-plugin-details__name">
                            <?= esc_html($info->name) ?>
                            <?php if ($info->isActive): ?><span class="lp-plugin-card__badge lp-plugin-card__badge--active">Active</span><?php endif; ?>
                            <?php if ($info->isDisabled): ?><span class="lp-plugin-card__badge lp-plugin-card__badge--disabled">Disabled</span><?php endif; ?>
                        </h3>

                        <?php if ($info->isDisabled): ?>
                            <p class="lp-alert lp-alert--error">This plugin requires PHP <?= esc_html($info->requiresPhp) ?>+ and cannot be activated on this server.</p>
                        <?php endif; ?>

                        <?php if ($info->description !== ''): ?>
                            <p class="lp-plugin-details__description"><?= esc_html($info->description) ?></p>
                        <?php endif; ?>

                        <dl class="lp-plugin-details__facts">
                            <?php if ($info->version !== ''): ?><dt>Version</dt><dd><?= esc_html($info->version) ?></dd><?php endif; ?>
                            <?php if ($cardUpdate !== null): ?><dt>Update available</dt><dd>Version <?= esc_html($cardUpdate['version']) ?></dd><?php endif; ?>
                            <?php if ($info->author !== ''): ?>
                                <dt>Author</dt>
                                <dd><?php if ($info->authorUri !== ''): ?><a href="<?= esc_url($info->authorUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->author) ?></a><?php else: ?><?= esc_html($info->author) ?><?php endif; ?></dd>
                            <?php endif; ?>
                            <?php if ($info->pluginUri !== ''): ?><dt>Homepage</dt><dd><a href="<?= esc_url($info->pluginUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->pluginUri) ?></a></dd><?php endif; ?>
                            <?php if ($info->license !== ''): ?>
                                <dt>License</dt>
                                <dd><?php if ($info->licenseUri !== ''): ?><a href="<?= esc_url($info->licenseUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->license) ?></a><?php else: ?><?= esc_html($info->license) ?><?php endif; ?></dd>
                            <?php endif; ?>
                            <?php if ($info->requiresAtLeast !== ''): ?><dt>Requires Lumora Press</dt><dd><?= esc_html($info->requiresAtLeast) ?>+</dd><?php endif; ?>
                            <?php if ($info->requiresPhp !== ''): ?><dt>Requires PHP</dt><dd><?= esc_html($info->requiresPhp) ?>+</dd><?php endif; ?>
                            <?php if ($info->requiresPlugins !== []): ?>
                                <dt>Dependencies</dt>
                                <dd><?= esc_html(implode(', ', $info->requiresPlugins)) ?></dd>
                            <?php endif; ?>
                            <dt>Folder</dt>
                            <dd><code><?= esc_html($info->relativePath) ?></code></dd>
                        </dl>

                        <?php if ($info->tags !== []): ?>
                            <ul class="lp-plugin-details__tags">
                                <?php foreach ($info->tags as $tag): ?>
                                    <li><?= esc_html($tag) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($info->readmeFile !== null): ?>
                            <details class="lp-plugin-details__document">
                                <summary>View README</summary>
                                <pre><?= esc_html((string) $kernel->pluginRegistry->documentContent($info->slug, $info->readmeFile)) ?></pre>
                            </details>
                        <?php endif; ?>

                        <?php if ($info->changelogFile !== null): ?>
                            <details class="lp-plugin-details__document">
                                <summary>View CHANGELOG</summary>
                                <pre><?= esc_html((string) $kernel->pluginRegistry->documentContent($info->slug, $info->changelogFile)) ?></pre>
                            </details>
                        <?php endif; ?>

                        <?php do_action('lp_plugin_details_panel', $info); ?>

                        <div class="lp-plugin-details__actions">
                            <?php if ($cardUpdate !== null): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('plugin_update_details_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="plugin_update_download">
                                    <input type="hidden" name="origin" value="details">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--primary">Update to v<?= esc_html($cardUpdate['version']) ?></button>
                                </form>
                            <?php endif; ?>
                            <?php foreach ($pluginActionLinksBySlug[$info->slug] as $actionLink): ?>
                                <a class="lp-button lp-button--secondary" href="<?= esc_url($actionLink['url']) ?>"><?= esc_html($actionLink['label']) ?></a>
                            <?php endforeach; ?>
                            <?php if ($info->isActive): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('deactivate_plugin_details_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="deactivate_plugin">
                                    <input type="hidden" name="origin" value="details">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--danger">Deactivate</button>
                                </form>
                            <?php else: ?>
                                <?php if (!$info->isDisabled): ?>
                                    <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                        <?= Csrf::field('activate_plugin_details_' . $info->slug) ?>
                                        <input type="hidden" name="form" value="activate_plugin">
                                        <input type="hidden" name="origin" value="details">
                                        <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                        <button type="submit" class="lp-button lp-button--primary">Activate</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this plugin permanently? This cannot be undone.">
                                    <?= Csrf::field('delete_plugin_details_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="delete_plugin">
                                    <input type="hidden" name="origin" value="details">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--danger">Delete</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="lp-button" data-lp-plugin-dialog-close>Close</button>
                        </div>
                    </div>
                </template>
            <?php endforeach; ?>
        </div>
        </div>
        <p class="lp-plugin-grid__empty" data-lp-plugin-empty hidden>No plugins match your search.</p>

        <dialog class="lp-plugin-dialog" data-lp-plugin-dialog aria-label="Plugin details"></dialog>
    <?php endif; ?>
</section>
