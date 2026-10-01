<?php

/**
 * The admin Appearance > Themes screen: switch, install, or remove themes.
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

use LumoraPress\Controllers\Admin\ThemesController;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\UpdateStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Two independent sections — Theme Management and Branding — each with its
// own "form" value. POST handling lives in ThemesController; this view
// dispatches to it and turns the result into a redirect or $error string.
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

// Bundled theme updates reuse Maintenance > Updates' staged pipeline
// (download + validate, confirm, then a fetch()-driven continue loop via
// update-continue.js), scoped to a single theme directory — the same flow
// the Plugins screen uses for bundled plugins.
if (($_GET['ajax'] ?? null) === 'progress') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    echo json_encode($kernel->updateProgress->read());
    exit;
}

$themeUpdateForms = ['theme_update_check', 'theme_update_download', 'theme_update_install', 'continue_theme_update', 'theme_update_cancel'];
$themeUpdateSummary = null;
$themeUpdateStageLabels = [
    'backup_files' => 'Backing up files…',
    'backup_database' => 'Backing up database…',
    'apply_files' => 'Replacing theme files…',
    'clear_cache' => 'Clearing caches…',
    'cleanup' => 'Finishing up…',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($form, $themeUpdateForms, true)) {
    $isAjaxContinueRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $respondJson = static function (array $payload): never {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    };

    if ($form === 'theme_update_check' && Csrf::verify('theme_update_check', $csrfToken)) {
        try {
            if ($kernel->githubUpdates->checkNow() === null) {
                $error = 'Could not reach GitHub, or the configured repository has no releases yet. Check the repository setting under Maintenance > Updates and try again.';
            } else {
                redirect(admin_url('appearance/themes') . '?theme_updates_checked=1');
            }
        } catch (\Throwable $exception) {
            $error = 'Could not check for updates: ' . $exception->getMessage();
        }
    } elseif ($form === 'theme_update_download') {
        $slug = is_string($_POST['slug'] ?? null) ? $_POST['slug'] : '';
        $origin = in_array($_POST['origin'] ?? null, ['card', 'details'], true) ? $_POST['origin'] : 'card';

        if (!Csrf::verify('theme_update_' . $origin . '_' . $slug, $csrfToken)) {
            $error = 'Your session expired. Please try again.';
        } elseif (!$kernel->updates->isBundledTheme($slug)) {
            $error = 'Only themes bundled with Lumora Press can be updated this way.';
        } else {
            $downloadPath = null;

            $kernel->updateProgress->reset('theme_download', [
                ['key' => 'download', 'label' => 'Downloading theme package'],
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
                $kernel->githubUpdates->downloadThemeRelease($slug, $downloadPath);

                $themeUpdateSummary = $kernel->updates->checkThemePackage($slug, $downloadPath, $currentUser->id);
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
    } elseif ($form === 'theme_update_install' && Csrf::verify('theme_update_install', $csrfToken)) {
        $installToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

        $kernel->updateProgress->reset('theme_install', [
            ['key' => 'backup_files', 'label' => 'Backing up files'],
            ['key' => 'backup_database', 'label' => 'Backing up database'],
            ['key' => 'apply_files', 'label' => 'Replacing theme files'],
            ['key' => 'clear_cache', 'label' => 'Clearing caches'],
            ['key' => 'cleanup', 'label' => 'Finishing up'],
        ]);

        session_write_close();

        try {
            $begin = $kernel->updates->beginInstall($installToken, $currentUser->id);
            redirect(admin_url('appearance/themes') . '?theme_update_token=' . urlencode($begin['token']));
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            $kernel->updateProgress->complete(false, $error);
            session_start();
        }
    } elseif ($form === 'continue_theme_update' && Csrf::verify('theme_update_continue', $csrfToken)) {
        $installToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

        try {
            $result = $kernel->updates->continueInstall($installToken);

            if ($result['done']) {
                $redirectUrl = admin_url('appearance/themes') . '?' . http_build_query([
                    'theme_updated' => 1,
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
                    'stage_label' => $themeUpdateStageLabels[$result['stage']] ?? $result['stage'],
                    'database_progress' => $result['database_progress'] ?? null,
                    'csrf_token' => Csrf::token('theme_update_continue'),
                ]);
            }

            redirect(admin_url('appearance/themes') . '?theme_update_token=' . urlencode($installToken));
        } catch (\Throwable $exception) {
            $redirectUrl = admin_url('appearance/themes') . '?theme_update_error=' . urlencode($exception->getMessage());

            if ($isAjaxContinueRequest) {
                $respondJson(['done' => true, 'redirect' => $redirectUrl]);
            }

            redirect($redirectUrl);
        }
    } elseif ($form === 'theme_update_cancel' && Csrf::verify('theme_update_cancel', $csrfToken)) {
        try {
            $kernel->updates->cancel(is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
        } catch (\Throwable $exception) {
            // Nothing to clean up, or an already-expired token — safe to ignore.
        }

        redirect(admin_url('appearance/themes'));
    } else {
        $error = 'Your session expired. Please try again.';
    }
}

if ($form !== '' && !in_array($form, $themeUpdateForms, true)) {
    $controller = new ThemesController($kernel->themes, $kernel->themeInstaller, $kernel->config, $kernel->media);

    $result = match ($form) {
        'activate_theme' => $controller->activateTheme($_POST, $csrfToken),
        'delete_theme' => $controller->deleteTheme($_POST, $csrfToken),
        'bulk_delete_themes' => $controller->bulkDeleteThemes($_POST, $csrfToken),
        'update_theme' => $controller->updateTheme($_POST, $_FILES, $csrfToken),
        'install_theme' => $controller->installTheme($_FILES, $csrfToken),
        'branding' => $controller->saveBranding($_POST, $_FILES, $currentUser->id, $csrfToken),
        default => null,
    };

    if ($result !== null) {
        if ($result->redirectUrl !== null) {
            redirect($result->redirectUrl);
        }

        $error = $result->errorMessage;
    }
}

$themeList = $kernel->themes->discover();
$currentLogoId = (int) $kernel->config->option('site_logo_media_id', '');
$currentFaviconId = (int) $kernel->config->option('favicon_media_id', '');
$currentLogo = $currentLogoId > 0 ? $kernel->media->find($currentLogoId) : null;
$currentFavicon = $currentFaviconId > 0 ? $kernel->media->find($currentFaviconId) : null;

$themeUpdateToken = is_string($_GET['theme_update_token'] ?? null) ? $_GET['theme_update_token'] : null;
$themeUpdateProgress = null;

if ($themeUpdateToken !== null) {
    try {
        $themeUpdateProgress = $kernel->updates->installProgress($themeUpdateToken);

        if ($themeUpdateProgress['scope'] !== 'theme') {
            $themeUpdateProgress = null;
        }
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// Cached from the last GitHub check (here or on Maintenance > Updates) — no network call on page load.
$themeUpdatesAvailable = $kernel->githubUpdates->cachedThemeUpdates($kernel->updates->installedThemeVersions());
$themeUpdatesLastCheckedAt = (int) $kernel->config->option('update_last_checked_at', '0');
$hasBundledThemes = $kernel->updates->bundledThemeSlugs() !== [];
?>
<h1 class="lp-admin__title">Appearance</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['theme_updated'])): ?>
    <?php $themeUpdatedStatus = UpdateStatus::tryFrom((string) ($_GET['status'] ?? '')); ?>
    <div class="lp-alert <?= $themeUpdatedStatus === UpdateStatus::Success ? 'lp-alert--success' : 'lp-alert--error' ?>">
        <?= esc_html((string) ($_GET['message'] ?? 'The theme update finished.')) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['theme_update_error'])): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html((string) $_GET['theme_update_error']) ?></div>
<?php endif; ?>

<?php if (isset($_GET['theme_updates_checked'])): ?>
    <div class="lp-alert lp-alert--success">
        <?= $themeUpdatesAvailable === [] ? 'All bundled themes are up to date.' : count($themeUpdatesAvailable) . ' theme update' . (count($themeUpdatesAvailable) === 1 ? '' : 's') . ' available.' ?>
    </div>
<?php endif; ?>

<?php if ($themeUpdateProgress !== null): ?>
    <section class="lp-admin__panel lp-theme-update">
        <h2>Updating <?= esc_html((string) $themeUpdateProgress['theme_name']) ?></h2>
        <p>
            Updating from <strong><?= esc_html($themeUpdateProgress['from_version']) ?></strong>
            to <strong><?= esc_html($themeUpdateProgress['to_version']) ?></strong>&hellip;
        </p>
        <p class="lp-field__hint" data-lp-update-stage><?= esc_html($themeUpdateStageLabels[$themeUpdateProgress['stage']] ?? $themeUpdateProgress['stage']) ?></p>
        <p class="lp-field__hint" data-lp-update-detail hidden></p>
        <div class="lp-alert lp-alert--warning">This page updates on its own — leave it open until it finishes.</div>

        <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" id="update-install-continue">
            <?= Csrf::field('theme_update_continue') ?>
            <input type="hidden" name="form" value="continue_theme_update">
            <input type="hidden" name="token" value="<?= esc_attr($themeUpdateToken) ?>">
            <button type="submit" class="lp-button lp-button--primary">Continue</button>
        </form>
    </section>
    <?php return; ?>
<?php endif; ?>

<?php if ($themeUpdateSummary !== null): ?>
    <section class="lp-admin__panel lp-theme-update">
        <h2>Theme Update Summary</h2>
        <p>
            Updating <strong><?= esc_html($themeUpdateSummary['name']) ?></strong>
            from <strong><?= esc_html($themeUpdateSummary['from_version']) ?></strong>
            to <strong><?= esc_html($themeUpdateSummary['to_version']) ?></strong>
        </p>

        <?php if ($themeUpdateSummary['blocking'] !== []): ?>
            <ul class="lp-install__requirements">
                <?php foreach ($themeUpdateSummary['blocking'] as $problem): ?>
                    <li class="lp-alert lp-alert--error"><?= esc_html($problem) ?></li>
                <?php endforeach; ?>
            </ul>
            <p><a class="lp-button" href="<?= esc_url(admin_url('appearance/themes')) ?>">Back to Themes</a></p>
        <?php else: ?>
            <?php if ($themeUpdateSummary['warnings'] !== []): ?>
                <ul class="lp-install__requirements">
                    <?php foreach ($themeUpdateSummary['warnings'] as $warning): ?>
                        <li class="lp-alert lp-alert--warning"><?= esc_html($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p>Only this theme's folder is replaced, and your Theme Options are kept. Lumora Press will back up your files and database first, and roll back automatically if anything goes wrong.</p>

            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('appearance/themes')) ?>?ajax=progress" data-lp-update-progress-target="lp-theme-update-progress-install">
                <?= Csrf::field('theme_update_install') ?>
                <input type="hidden" name="form" value="theme_update_install">
                <input type="hidden" name="token" value="<?= esc_attr($themeUpdateSummary['token']) ?>">
                <button type="submit" class="lp-button lp-button--primary">Confirm &amp; Update</button>
            </form>
            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form">
                <?= Csrf::field('theme_update_cancel') ?>
                <input type="hidden" name="form" value="theme_update_cancel">
                <input type="hidden" name="token" value="<?= esc_attr($themeUpdateSummary['token']) ?>">
                <button type="submit" class="lp-button">Cancel</button>
            </form>
            <ul id="lp-theme-update-progress-install" class="lp-update-progress" hidden></ul>
        <?php endif; ?>
    </section>
    <?php return; ?>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['installed'])): ?>
    <div class="lp-alert lp-alert--success">Installed &ldquo;<?= esc_html((string) $_GET['installed']) ?>&rdquo;.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Theme deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['bulk_deleted'])): ?>
    <?php $bulkDeletedThemeCount = (int) $_GET['bulk_deleted']; ?>
    <?php $bulkSkippedThemeCount = (int) ($_GET['bulk_skipped'] ?? 0); ?>
    <div class="lp-alert lp-alert--success">
        <?= $bulkDeletedThemeCount ?> theme<?= $bulkDeletedThemeCount === 1 ? '' : 's' ?> deleted.
        <?php if ($bulkSkippedThemeCount > 0): ?>
            <?= $bulkSkippedThemeCount ?> skipped (active, or already removed).
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="lp-alert lp-alert--success">Updated &ldquo;<?= esc_html((string) $_GET['updated']) ?>&rdquo;.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Themes</h2>

    <?php if ($hasBundledThemes): ?>
        <div class="lp-theme-updates-bar">
            <p class="lp-theme-updates-bar__status">
                <?php if ($themeUpdatesAvailable !== []): ?>
                    <span class="lp-status-badge lp-status-badge--warning"><?= count($themeUpdatesAvailable) ?> update<?= count($themeUpdatesAvailable) === 1 ? '' : 's' ?> available</span>
                <?php endif; ?>
                Bundled theme updates last checked:
                <?= $themeUpdatesLastCheckedAt > 0 ? esc_html(date('M j, Y g:i A T', $themeUpdatesLastCheckedAt)) : 'Never' ?>
            </p>
            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form">
                <?= Csrf::field('theme_update_check') ?>
                <input type="hidden" name="form" value="theme_update_check">
                <button type="submit" class="lp-button lp-button--secondary">Check for Updates</button>
            </form>
        </div>
        <ul id="lp-theme-update-progress" class="lp-update-progress" hidden></ul>
    <?php endif; ?>

    <?php if (count($themeList) > 1): ?>
        <p class="lp-field lp-theme-search">
            <label for="theme-search">Search themes</label>
            <input type="search" id="theme-search" data-lp-theme-search placeholder="Search by name, author, or tag&hellip;">
        </p>
    <?php endif; ?>

    <?php $hasDeletableThemes = array_filter($themeList, static fn (\LumoraPress\Core\Theme\ThemeInfo $info): bool => !$info->isActive) !== []; ?>

    <?php if ($hasDeletableThemes): ?>
        <form id="themes-bulk-form" method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__bulk-actions" data-lp-bulk-form>
            <?= Csrf::field('bulk_delete_themes') ?>
            <input type="hidden" name="form" value="bulk_delete_themes">
            <label class="lp-visually-hidden" for="themes-select-all">Select all</label>
            <input type="checkbox" id="themes-select-all" data-lp-select-all="theme_slugs[]" data-lp-select-all-scope="section">
            <label class="lp-visually-hidden" for="themes-bulk-action">Bulk action</label>
            <select id="themes-bulk-action" name="bulk_action">
                <option value="">Bulk actions</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="lp-button lp-button--secondary" data-lp-confirm="Delete the selected themes permanently? This cannot be undone.">Apply</button>
        </form>
    <?php endif; ?>

    <div class="lp-theme-grid" data-lp-theme-grid>
        <?php foreach ($themeList as $info): ?>
            <?php
            $searchHaystack = strtolower($info->name . ' ' . $info->author . ' ' . implode(' ', $info->tags));
            $templateId = 'lp-theme-details-' . $info->slug;
            $previewUrl = site_url('') . '?lp_preview_theme=' . rawurlencode($info->slug);
            // Computed once and reused by both the card and its details
            // template — a second Csrf::field() call for the same action
            // would overwrite the first form's token.
            $activateCsrfField = Csrf::field('activate_theme_' . $info->slug);
            $deleteCsrfField = Csrf::field('delete_theme_' . $info->slug);
            $themeUpdate = $themeUpdatesAvailable[$info->slug] ?? null;
            ?>
            <div class="lp-theme-card<?= $info->isActive ? ' lp-theme-card--active' : '' ?>" data-lp-theme-card data-theme-search="<?= esc_attr($searchHaystack) ?>">
                <div class="lp-theme-card__screenshot-wrap">
                    <?php if (!$info->isActive): ?>
                        <label class="lp-theme-card__select">
                            <span class="lp-visually-hidden">Select "<?= esc_html($info->name) ?>"</span>
                            <input type="checkbox" name="theme_slugs[]" value="<?= esc_attr($info->slug) ?>" form="themes-bulk-form">
                        </label>
                    <?php endif; ?>
                    <?php if ($info->screenshotUrl !== null): ?>
                        <img class="lp-theme-card__screenshot" src="<?= esc_url($info->screenshotUrl) ?>" alt="">
                    <?php else: ?>
                        <div class="lp-theme-card__screenshot lp-theme-card__screenshot--placeholder" aria-hidden="true"></div>
                    <?php endif; ?>
                </div>
                <div class="lp-theme-card__body">
                    <h3 class="lp-theme-card__name">
                        <?= esc_html($info->name) ?>
                        <?php if ($info->isActive): ?>
                            <span class="lp-theme-card__badge">Active</span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($info->description !== ''): ?>
                        <p class="lp-theme-card__description"><?= esc_html($info->description) ?></p>
                    <?php endif; ?>
                    <p class="lp-theme-card__meta">
                        <?php if ($info->version !== ''): ?><span>Version <?= esc_html($info->version) ?></span><?php endif; ?>
                        <?php if ($info->author !== ''): ?><span>By <?= esc_html($info->author) ?></span><?php endif; ?>
                    </p>
                    <?php if ($themeUpdate !== null): ?>
                        <p class="lp-theme-card__update">
                            <span class="lp-theme-card__badge lp-theme-card__badge--update">Update available: <?= esc_html($themeUpdate['version']) ?></span>
                        </p>
                    <?php endif; ?>
                    <div class="lp-theme-card__actions">
                        <?php if ($themeUpdate !== null): ?>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('appearance/themes')) ?>?ajax=progress" data-lp-update-progress-target="lp-theme-update-progress">
                                <?= Csrf::field('theme_update_card_' . $info->slug) ?>
                                <input type="hidden" name="form" value="theme_update_download">
                                <input type="hidden" name="origin" value="card">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--primary">Update</button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="lp-button lp-button--secondary" data-lp-theme-details-trigger data-theme-template="<?= esc_attr($templateId) ?>">Details</button>
                        <?php if (!$info->isActive): ?>
                            <a class="lp-button lp-button--secondary" href="<?= esc_url($previewUrl) ?>" target="_blank" rel="noopener noreferrer">Preview</a>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form">
                                <?= $activateCsrfField ?>
                                <input type="hidden" name="form" value="activate_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--primary">Activate</button>
                            </form>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this theme permanently? This cannot be undone.">
                                <?= $deleteCsrfField ?>
                                <input type="hidden" name="form" value="delete_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <template id="<?= esc_attr($templateId) ?>">
                <div class="lp-theme-details">
                    <?php if ($info->screenshots !== []): ?>
                        <div class="lp-theme-details__gallery">
                            <?php foreach ($info->screenshots as $screenshot): ?>
                                <img src="<?= esc_url($screenshot) ?>" alt="<?= esc_attr($info->name) ?> screenshot">
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="lp-theme-details__gallery lp-theme-details__gallery--placeholder" aria-hidden="true"></div>
                    <?php endif; ?>

                    <h3 class="lp-theme-details__name">
                        <?= esc_html($info->name) ?>
                        <?php if ($info->isActive): ?><span class="lp-theme-card__badge">Active</span><?php endif; ?>
                    </h3>

                    <?php if ($info->description !== ''): ?>
                        <p class="lp-theme-details__description"><?= esc_html($info->description) ?></p>
                    <?php endif; ?>

                    <dl class="lp-theme-details__facts">
                        <?php if ($info->version !== ''): ?><dt>Version</dt><dd><?= esc_html($info->version) ?></dd><?php endif; ?>
                        <?php if ($info->author !== ''): ?>
                            <dt>Author</dt>
                            <dd><?php if ($info->authorUri !== ''): ?><a href="<?= esc_url($info->authorUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->author) ?></a><?php else: ?><?= esc_html($info->author) ?><?php endif; ?></dd>
                        <?php endif; ?>
                        <?php if ($info->themeUri !== ''): ?><dt>Homepage</dt><dd><a href="<?= esc_url($info->themeUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->themeUri) ?></a></dd><?php endif; ?>
                        <?php if ($info->license !== ''): ?>
                            <dt>License</dt>
                            <dd><?php if ($info->licenseUri !== ''): ?><a href="<?= esc_url($info->licenseUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->license) ?></a><?php else: ?><?= esc_html($info->license) ?><?php endif; ?></dd>
                        <?php endif; ?>
                        <?php if ($info->requiresAtLeast !== ''): ?><dt>Requires Lumora Press</dt><dd><?= esc_html($info->requiresAtLeast) ?>+</dd><?php endif; ?>
                        <?php if ($info->requiresPhp !== ''): ?><dt>Requires PHP</dt><dd><?= esc_html($info->requiresPhp) ?>+</dd><?php endif; ?>
                        <dt>Folder</dt>
                        <dd><code><?= esc_html($info->relativePath) ?></code></dd>
                    </dl>

                    <?php if ($info->tags !== []): ?>
                        <ul class="lp-theme-details__tags">
                            <?php foreach ($info->tags as $tag): ?>
                                <li><?= esc_html($tag) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($info->readmeFile !== null): ?>
                        <details class="lp-theme-details__document">
                            <summary>View README</summary>
                            <pre><?= esc_html((string) $kernel->themes->documentContent($info->slug, $info->readmeFile)) ?></pre>
                        </details>
                    <?php endif; ?>

                    <?php if ($info->changelogFile !== null): ?>
                        <details class="lp-theme-details__document">
                            <summary>View CHANGELOG</summary>
                            <pre><?= esc_html((string) $kernel->themes->documentContent($info->slug, $info->changelogFile)) ?></pre>
                        </details>
                    <?php endif; ?>

                    <?php do_action('lp_theme_details_panel', $info); ?>

                    <div class="lp-theme-details__actions">
                        <?php if ($themeUpdate !== null): ?>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('appearance/themes')) ?>?ajax=progress" data-lp-update-progress-target="lp-theme-update-progress">
                                <?= Csrf::field('theme_update_details_' . $info->slug) ?>
                                <input type="hidden" name="form" value="theme_update_download">
                                <input type="hidden" name="origin" value="details">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--primary">Update to <?= esc_html($themeUpdate['version']) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$info->isActive): ?>
                            <a class="lp-button lp-button--secondary" href="<?= esc_url($previewUrl) ?>" target="_blank" rel="noopener noreferrer">Preview</a>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form">
                                <?= $activateCsrfField ?>
                                <input type="hidden" name="form" value="activate_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--primary">Activate</button>
                            </form>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this theme permanently? This cannot be undone.">
                                <?= $deleteCsrfField ?>
                                <input type="hidden" name="form" value="delete_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--danger">Delete</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" enctype="multipart/form-data" class="lp-admin__inline-form lp-theme-details__update-form" data-lp-confirm="This will overwrite this theme's current files with the contents of the uploaded ZIP. Continue?">
                            <?= Csrf::field('update_theme_' . $info->slug) ?>
                            <input type="hidden" name="form" value="update_theme">
                            <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                            <label class="lp-visually-hidden" for="<?= esc_attr($templateId) ?>-zip">Theme ZIP file to update &ldquo;<?= esc_html($info->name) ?>&rdquo; with</label>
                            <input type="file" id="<?= esc_attr($templateId) ?>-zip" name="theme_zip" accept=".zip" required>
                            <button type="submit" class="lp-button lp-button--secondary">Update from ZIP</button>
                        </form>
                        <button type="button" class="lp-button" data-lp-theme-dialog-close>Close</button>
                    </div>
                </div>
            </template>
        <?php endforeach; ?>
    </div>
    <p class="lp-theme-grid__empty" data-lp-theme-empty hidden>No themes match your search.</p>

    <dialog class="lp-theme-dialog" data-lp-theme-dialog aria-label="Theme details"></dialog>

    <h3>Install a Theme</h3>
    <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('install_theme') ?>
        <input type="hidden" name="form" value="install_theme">

        <p class="lp-field">
            <label for="theme-zip">Theme ZIP file</label>
            <input type="file" id="theme-zip" name="theme_zip" accept=".zip" required>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Upload &amp; Install</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Branding</h2>
    <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('branding') ?>
        <input type="hidden" name="form" value="branding">

        <p class="lp-field">
            <label for="site-name">Site name</label>
            <input type="text" id="site-name" name="site_name" value="<?= esc_attr((string) $kernel->config->option('site_name', '')) ?>">
        </p>

        <p class="lp-field">
            <label for="site-logo">Site logo</label>
            <?php if ($currentLogo !== null): ?>
                <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentLogo)) ?>" alt="Current site logo">
                <label class="lp-field--checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
            <?php endif; ?>
            <input type="file" id="site-logo" name="logo" accept="image/*">
        </p>

        <p class="lp-field">
            <label for="site-favicon">Favicon</label>
            <?php if ($currentFavicon !== null): ?>
                <img class="lp-branding-preview lp-branding-preview--favicon" src="<?= esc_url($kernel->media->url($currentFavicon)) ?>" alt="Current favicon">
                <label class="lp-field--checkbox"><input type="checkbox" name="remove_favicon" value="1"> Remove current favicon</label>
            <?php endif; ?>
            <input type="file" id="site-favicon" name="favicon" accept="image/png,image/x-icon,.ico">
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
