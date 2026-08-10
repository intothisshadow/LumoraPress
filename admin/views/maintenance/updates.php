<?php

/**
 * The admin Maintenance > Updates screen: check for and apply manual/GitHub updates.
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
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\UpdateStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$updates = $kernel->updates;
$error = null;
$checkResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'upload' && Csrf::verify('update_upload', $token)) {
        $parseIniBytes = static function (string $value): int {
            $value = trim($value);

            if ($value === '') {
                return 0;
            }

            $unit = strtolower(substr($value, -1));

            return match ($unit) {
                'g' => ((int) $value) * 1024 * 1024 * 1024,
                'm' => ((int) $value) * 1024 * 1024,
                'k' => ((int) $value) * 1024,
                default => (int) $value,
            };
        };

        $maxPostBytes = $parseIniBytes((string) ini_get('post_max_size'));
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($maxPostBytes > 0 && $contentLength > $maxPostBytes) {
            $error = sprintf(
                'The uploaded file is larger than this server allows (limit: %s). Ask your host to raise post_max_size / upload_max_filesize.',
                ini_get('post_max_size'),
            );
        } elseif (!isset($_FILES['package']) || $_FILES['package']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a ZIP file to upload.';
        } elseif ($_FILES['package']['error'] !== UPLOAD_ERR_OK) {
            $error = 'The file upload failed. Please try again.';
        } else {
            $allowDowngrade = ($_POST['allow_downgrade'] ?? '') === '1';

            try {
                $checkResult = $updates->checkUpload($_FILES['package']['tmp_name'], $allowDowngrade, 'manual', $currentUser->id);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'install' && Csrf::verify('update_install', $token)) {
        $installToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

        try {
            $result = $updates->install($installToken, $currentUser->id);
            $query = http_build_query([
                'installed' => 1,
                'status' => $result['status']->value,
                'message' => $result['message'],
            ]);
            header('Location: ' . admin_url('maintenance/updates') . '?' . $query);
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($form === 'cancel' && Csrf::verify('update_cancel', $token)) {
        $cancelToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

        try {
            $updates->cancel($cancelToken);
        } catch (\Throwable $exception) {
            // Nothing to clean up, or an already-expired token — safe to ignore.
        }

        header('Location: ' . admin_url('maintenance/updates'));
        exit;
    } elseif ($form === 'github_check' && Csrf::verify('github_check', $token)) {
        try {
            if ($kernel->githubUpdates->checkNow() === null) {
                $error = 'Could not reach GitHub, or the configured repository has no releases yet. Check the repository setting below and try again.';
            }
        } catch (\Throwable $exception) {
            $error = 'Could not check for updates: ' . $exception->getMessage();
        }
    } elseif ($form === 'github_download' && Csrf::verify('github_download', $token)) {
        $allowDowngrade = ($_POST['allow_downgrade'] ?? '') === '1';
        $downloadPath = null;

        try {
            $release = $kernel->githubUpdates->checkNow();

            if ($release === null) {
                throw new \RuntimeException('Could not reach GitHub to download the release.');
            }

            $downloadDir = rtrim(LUMORA_ROOT, '/') . '/storage/updates/downloads';

            if (!is_dir($downloadDir) && !mkdir($downloadDir, 0755, true) && !is_dir($downloadDir)) {
                throw new \RuntimeException('Unable to prepare the downloads directory.');
            }

            $downloadPath = $downloadDir . '/' . bin2hex(random_bytes(16)) . '.zip';

            $kernel->githubUpdates->downloadRelease($release, $downloadPath);

            $checkResult = $updates->checkUpload($downloadPath, $allowDowngrade, 'github', $currentUser->id);
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        } finally {
            if ($downloadPath !== null && is_file($downloadPath)) {
                unlink($downloadPath);
            }
        }
    } elseif ($form === 'github_settings' && Csrf::verify('github_settings', $token)) {
        $kernel->config->setOption('update_github_repo', trim((string) ($_POST['update_github_repo'] ?? '')));
        $kernel->config->setOption('update_github_token', trim((string) ($_POST['update_github_token'] ?? '')));
        $kernel->config->setOption('update_channel', ($_POST['update_channel'] ?? '') === 'prerelease' ? 'prerelease' : 'stable');
        $kernel->config->setOption('update_auto_check_enabled', ($_POST['update_auto_check_enabled'] ?? '') === '1' ? '1' : '0');

        $allowedIntervals = ['3600', '86400', '604800'];
        $intervalInput = (string) ($_POST['update_check_interval'] ?? '86400');
        $kernel->config->setOption('update_check_interval', in_array($intervalInput, $allowedIntervals, true) ? $intervalInput : '86400');

        header('Location: ' . admin_url('maintenance/updates') . '?settings_saved=1');
        exit;
    } elseif ($form === 'restore_backup' && Csrf::verify('restore_backup', $token)) {
        $filesFilename = is_string($_POST['files_filename'] ?? null) && $_POST['files_filename'] !== '' ? $_POST['files_filename'] : null;
        $databaseFilename = is_string($_POST['database_filename'] ?? null) && $_POST['database_filename'] !== '' ? $_POST['database_filename'] : null;

        if ($filesFilename === null) {
            $error = 'This backup has no files archive to restore.';
        } else {
            try {
                $updates->restoreBackup($filesFilename, $databaseFilename);
                header('Location: ' . admin_url('maintenance/updates') . '?restored=1');
                exit;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'delete_backup' && Csrf::verify('delete_backup', $token)) {
        $filesFilename = is_string($_POST['files_filename'] ?? null) && $_POST['files_filename'] !== '' ? $_POST['files_filename'] : null;
        $databaseFilename = is_string($_POST['database_filename'] ?? null) && $_POST['database_filename'] !== '' ? $_POST['database_filename'] : null;

        try {
            $updates->deleteBackup($filesFilename, $databaseFilename);
            header('Location: ' . admin_url('maintenance/updates') . '?deleted=1');
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($form === 'backup_now' && Csrf::verify('backup_now', $token)) {
        try {
            $updates->createBackupNow();
            header('Location: ' . admin_url('maintenance/updates') . '?backed_up=1');
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$installedVersion = $updates->installedVersion();
$recentLog = $updates->recentLog(10);
$backups = $updates->listBackups();
$migrationStatus = $updates->migrationStatus();
$systemStatus = $updates->systemStatus();
$githubRepo = (string) $kernel->config->option('update_github_repo', 'intothisshadow/LumoraPress');
$githubToken = (string) $kernel->config->option('update_github_token', '');
$githubChannel = (string) $kernel->config->option('update_channel', 'stable');
$githubAutoCheckEnabled = ((string) $kernel->config->option('update_auto_check_enabled', '1')) === '1';
$githubCheckInterval = (string) $kernel->config->option('update_check_interval', '86400');
$updateStatus = $kernel->githubUpdates->cachedUpdateStatus($installedVersion);
$releasesUrl = 'https://github.com/' . $githubRepo . '/releases';

// LP-057: default to the Manual Update tab when the current request is the
// result of a manual-upload error or a manual checkResult, so a validation
// error on upload doesn't get hidden behind the GitHub tab.
$activeTab = ($checkResult !== null && ($checkResult['source'] ?? 'manual') === 'manual')
    || (($form ?? '') === 'upload' && $error !== null)
    ? 'manual'
    : 'github';
?>
<h1 class="lp-admin__title">Updates</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (($_GET['installed'] ?? null) === '1'): ?>
    <?php $installedStatus = UpdateStatus::tryFrom((string) ($_GET['status'] ?? '')); ?>
    <div class="lp-alert <?= $installedStatus === UpdateStatus::Success ? 'lp-alert--success' : 'lp-alert--error' ?>">
        <?= esc_html((string) ($_GET['message'] ?? 'The update finished.')) ?>
    </div>
<?php endif; ?>

<?php if (($_GET['settings_saved'] ?? null) === '1'): ?>
    <div class="lp-alert lp-alert--success">GitHub update settings saved.</div>
<?php endif; ?>

<?php if (($_GET['restored'] ?? null) === '1'): ?>
    <div class="lp-alert lp-alert--success">Backup restored successfully.</div>
<?php endif; ?>

<?php if (($_GET['deleted'] ?? null) === '1'): ?>
    <div class="lp-alert lp-alert--success">Backup deleted.</div>
<?php endif; ?>

<?php if (($_GET['backed_up'] ?? null) === '1'): ?>
    <div class="lp-alert lp-alert--success">Backup created.</div>
<?php endif; ?>

<section class="lp-admin__panel lp-update__status-bar">
    <div class="lp-update__status-group">
        <p class="lp-update__status-label">Installed Version</p>
        <p class="lp-update__status-value"><?= esc_html($installedVersion) ?></p>
        <p class="lp-update__status-label">Database Schema</p>
        <p class="lp-update__status-value">
            v<?= esc_html((string) $migrationStatus['applied']) ?>
            (<?= $migrationStatus['up_to_date'] ? 'up to date' : ($migrationStatus['total'] - $migrationStatus['applied']) . ' pending' ?>)
        </p>
        <p class="lp-update__status-label">Installed At</p>
        <p class="lp-update__status-value"><?= esc_html(rtrim(LUMORA_ROOT, '/')) ?></p>
    </div>
    <div class="lp-update__status-group">
        <p class="lp-update__status-label">Update Status</p>
        <p>
            <span class="lp-status-badge <?= $updateStatus['available'] ? 'lp-status-badge--warning' : 'lp-status-badge--success' ?>">
                <?= $updateStatus['available'] ? 'Update available' : 'Up to date' ?>
            </span>
        </p>
        <p class="lp-update__status-label">Release Channel</p>
        <p class="lp-update__status-value"><?= $githubChannel === 'prerelease' ? 'Stable + Pre-releases' : 'Stable' ?></p>
        <p class="lp-update__status-label">Last Checked</p>
        <p class="lp-update__status-value">
            <?= $updateStatus['last_checked_at'] !== null ? esc_html(date('M j, Y g:i A T', $updateStatus['last_checked_at'])) : 'Never' ?>
        </p>
    </div>
    <div class="lp-update__status-group">
        <p class="lp-update__status-label">Update Source</p>
        <p class="lp-update__status-value">Provider: <strong>GitHub Releases</strong></p>
        <p class="lp-update__status-value">Repository: <code><?= esc_html($githubRepo) ?></code></p>
        <p><a href="<?= esc_url($releasesUrl) ?>" target="_blank" rel="noopener noreferrer">View all releases &#8599;</a></p>
    </div>
</section>

<?php if ($checkResult !== null): ?>
    <section class="lp-admin__panel">
        <h2>Update Summary</h2>
        <p>
            Updating from <strong><?= esc_html($checkResult['from_version']) ?></strong>
            to <strong><?= esc_html($checkResult['to_version']) ?></strong>
        </p>

        <?php if ($checkResult['blocking'] !== []): ?>
            <ul class="lp-install__requirements">
                <?php foreach ($checkResult['blocking'] as $problem): ?>
                    <li class="lp-alert lp-alert--error"><?= esc_html($problem) ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Resolve the issues above, then try again.</p>
        <?php else: ?>
            <?php if ($checkResult['warnings'] !== []): ?>
                <ul class="lp-install__requirements">
                    <?php foreach ($checkResult['warnings'] as $warning): ?>
                        <li class="lp-alert lp-alert--warning"><?= esc_html($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p>Lumora Press will automatically back up your files and database before installing this update.</p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>">
                <?= Csrf::field('update_install') ?>
                <input type="hidden" name="form" value="install">
                <input type="hidden" name="token" value="<?= esc_attr($checkResult['token']) ?>">
                <button type="submit" class="lp-button lp-button--primary">Confirm &amp; Install</button>
            </form>
            <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>">
                <?= Csrf::field('update_cancel') ?>
                <input type="hidden" name="form" value="cancel">
                <input type="hidden" name="token" value="<?= esc_attr($checkResult['token']) ?>">
                <button type="submit" class="lp-button">Cancel</button>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>

    <section class="lp-admin__panel">
        <h2>Database Updates</h2>
        <table class="lp-table">
            <tbody>
                <tr>
                    <td>Schema status</td>
                    <td>
                        <span class="lp-status-badge <?= $migrationStatus['up_to_date'] ? 'lp-status-badge--pass' : 'lp-status-badge--fail' ?>">
                            <?= $migrationStatus['up_to_date'] ? 'Up to date' : 'Pending' ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td>Applied</td>
                    <td><?= esc_html((string) $migrationStatus['applied']) ?> migration(s)</td>
                </tr>
            </tbody>
        </table>
    </section>

    <div class="lp-tabs">
        <div class="lp-tabs__list" role="tablist" aria-label="Update method">
            <button type="button" class="lp-tabs__tab" id="lp-tab-github" role="tab" aria-selected="<?= $activeTab === 'github' ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-github" tabindex="<?= $activeTab === 'github' ? '0' : '-1' ?>">GitHub</button>
            <button type="button" class="lp-tabs__tab" id="lp-tab-manual" role="tab" aria-selected="<?= $activeTab === 'manual' ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-manual" tabindex="<?= $activeTab === 'manual' ? '0' : '-1' ?>">Manual Update</button>
        </div>

        <div class="lp-tabs__panel" id="lp-tabpanel-github" role="tabpanel" aria-labelledby="lp-tab-github"<?= $activeTab === 'github' ? '' : ' hidden' ?>>
            <?php if ($updateStatus['latest_version'] !== null): ?>
                <section class="lp-admin__panel">
                    <h2>Latest release</h2>
                    <p>
                        Lumora Press <?= esc_html($updateStatus['release_name'] ?? ('v' . $updateStatus['latest_version'])) ?>
                        — version <strong><?= esc_html($updateStatus['latest_version']) ?></strong>
                        <span class="lp-status-badge <?= $updateStatus['prerelease'] ? 'lp-status-badge--warning' : 'lp-status-badge--success' ?>">
                            <?= $updateStatus['prerelease'] ? 'Pre-release' : 'Stable' ?>
                        </span>
                    </p>
                    <?php if ($updateStatus['release_date'] !== null): ?>
                        <p class="lp-field__hint">Published <?= esc_html($updateStatus['release_date']) ?></p>
                    <?php endif; ?>
                    <?php if ($updateStatus['download_size'] !== null): ?>
                        <p class="lp-field__hint">Download size: <?= esc_html(number_format($updateStatus['download_size'] / 1024 / 1024, 1)) ?> MB</p>
                    <?php endif; ?>

                    <p class="lp-admin__inline-form">
                        <a class="lp-button" href="<?= esc_url($releasesUrl) ?>" target="_blank" rel="noopener noreferrer">View release notes on GitHub</a>

                        <?php if ($updateStatus['available']): ?>
                            <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>" class="lp-admin__inline-form">
                                <?= Csrf::field('github_download') ?>
                                <input type="hidden" name="form" value="github_download">
                                <button type="submit" class="lp-button lp-button--primary">Download &amp; Install</button>
                            </form>
                        <?php endif; ?>
                    </p>

                    <?php if ($updateStatus['release_notes'] !== null): ?>
                        <div class="lp-update__release-notes">
                            <p><strong><?= esc_html($updateStatus['release_name'] ?? ('Release notes for ' . $updateStatus['latest_version'])) ?></strong></p>
                            <div class="lp-update__release-notes-body"><?= $kernel->content->render($updateStatus['release_notes'], ContentFormat::Markdown) ?></div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="lp-admin__panel">
                <h2>Check for Updates</h2>
                <p>Checks the configured release source for a new Lumora Press release. No site content, user data, or identifying information is ever transmitted — only a plain GET request is made to the release API.</p>
                <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>">
                    <?= Csrf::field('github_check') ?>
                    <input type="hidden" name="form" value="github_check">
                    <button type="submit" class="lp-button lp-button--primary">Check for Updates Now</button>
                </form>
            </section>
        </div>

        <div class="lp-tabs__panel" id="lp-tabpanel-manual" role="tabpanel" aria-labelledby="lp-tab-manual"<?= $activeTab === 'manual' ? '' : ' hidden' ?>>
            <section class="lp-admin__panel">
                <h2>Manual Update (ZIP Upload)</h2>
                <div class="lp-update-upload" data-lp-update-upload>
                    <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>" enctype="multipart/form-data">
                        <?= Csrf::field('update_upload') ?>
                        <input type="hidden" name="form" value="upload">

                        <p class="lp-field">
                            <label for="update-package">Release ZIP file</label>
                            <input type="file" id="update-package" name="package" accept=".zip" required>
                            <span class="lp-field__hint">Only official Lumora Press release packages should be uploaded here. You can also drag and drop a ZIP file anywhere in this box.</span>
                        </p>

                        <p class="lp-field lp-field--checkbox">
                            <input type="checkbox" id="update-allow-downgrade" name="allow_downgrade" value="1">
                            <label for="update-allow-downgrade">Allow installing an older version than what is currently installed</label>
                        </p>

                        <div class="lp-thumbnails__progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" data-lp-update-upload-progress hidden>
                            <div class="lp-thumbnails__progress-bar" data-lp-update-upload-progress-bar></div>
                        </div>

                        <button type="submit" class="lp-button lp-button--primary">Upload &amp; Check</button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    <section class="lp-admin__panel">
        <h2>Backups</h2>
        <p>A backup is a snapshot of the application's own code and configuration — not your uploaded media, which a backup or update never touches.</p>
        <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>" class="lp-admin__inline-form">
            <?= Csrf::field('backup_now') ?>
            <input type="hidden" name="form" value="backup_now">
            <button type="submit" class="lp-button">Back up now</button>
        </form>

        <?php if ($backups === []): ?>
            <p class="lp-admin__widget-placeholder">No backups have been created yet — one is made automatically before every update.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Version</th>
                        <th scope="col">Files</th>
                        <th scope="col">Database</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= esc_html(date('Y-m-d H:i:s', $backup['created_at'])) ?></td>
                            <td><?= esc_html($backup['version']) ?></td>
                            <td><?= $backup['files_size'] !== null ? esc_html(number_format($backup['files_size'] / 1024 / 1024, 1)) . ' MB' : '—' ?></td>
                            <td><?= $backup['database_size'] !== null ? esc_html(number_format($backup['database_size'] / 1024, 0)) . ' KB' : '—' ?></td>
                            <td class="lp-admin__row-actions">
                                <?php if ($backup['files_filename'] !== null): ?>
                                    <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>" class="lp-admin__inline-form" data-lp-confirm="Restore Lumora Press to this backup? Everything since it was taken will be lost.">
                                        <?= Csrf::field('restore_backup') ?>
                                        <input type="hidden" name="form" value="restore_backup">
                                        <input type="hidden" name="files_filename" value="<?= esc_attr($backup['files_filename']) ?>">
                                        <?php if ($backup['database_filename'] !== null): ?>
                                            <input type="hidden" name="database_filename" value="<?= esc_attr($backup['database_filename']) ?>">
                                        <?php endif; ?>
                                        <button type="submit" class="lp-button">Restore</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this backup permanently?">
                                    <?= Csrf::field('delete_backup') ?>
                                    <input type="hidden" name="form" value="delete_backup">
                                    <?php if ($backup['files_filename'] !== null): ?>
                                        <input type="hidden" name="files_filename" value="<?= esc_attr($backup['files_filename']) ?>">
                                    <?php endif; ?>
                                    <?php if ($backup['database_filename'] !== null): ?>
                                        <input type="hidden" name="database_filename" value="<?= esc_attr($backup['database_filename']) ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="lp-button lp-button--danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="lp-admin__panel">
        <h2>System status</h2>
        <p class="lp-field__hint">These reflect this server's current environment — they can change independently of anything above (e.g. if a host changes a PHP setting or free disk space runs low).</p>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Check</th>
                    <th scope="col">Status</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($systemStatus as $check): ?>
                    <tr>
                        <td><?= esc_html($check['label']) ?></td>
                        <td>
                            <span class="lp-status-badge <?= $check['ok'] ? 'lp-status-badge--pass' : 'lp-status-badge--fail' ?>">
                                <?= $check['ok'] ? 'Pass' : 'Fail' ?>
                            </span>
                        </td>
                        <td><?= esc_html($check['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="lp-admin__panel">
        <h2>Update settings</h2>
        <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>">
            <?= Csrf::field('github_settings') ?>
            <input type="hidden" name="form" value="github_settings">

            <p class="lp-field">
                <label for="update-github-repo">Repository</label>
                <input type="text" id="update-github-repo" name="update_github_repo" value="<?= esc_attr($githubRepo) ?>" placeholder="owner/repo">
                <span class="lp-field__hint">The GitHub repository releases are checked against, as "owner/repo".</span>
            </p>

            <p class="lp-field">
                <label for="update-channel">Release channel</label>
                <select id="update-channel" name="update_channel">
                    <option value="stable" <?= $githubChannel === 'stable' ? 'selected' : '' ?>>Stable (only stable releases)</option>
                    <option value="prerelease" <?= $githubChannel === 'prerelease' ? 'selected' : '' ?>>Stable + Pre-releases</option>
                </select>
                <span class="lp-field__hint">Stable checks GitHub's latest full release only. Pre-release also considers beta/pre-release tags.</span>
            </p>

            <p class="lp-field lp-field--checkbox">
                <label class="lp-toggle">
                    <input type="checkbox" id="update-auto-check-enabled" name="update_auto_check_enabled" value="1" <?= $githubAutoCheckEnabled ? 'checked' : '' ?>>
                    <span class="lp-toggle__track" aria-hidden="true"></span>
                </label>
                <label for="update-auto-check-enabled">Automatically check for updates</label>
                <span class="lp-field__hint">There's no cron available on typical shared hosting, so this checks opportunistically — the next time this admin page loads a check is due, rather than on a fixed schedule.</span>
            </p>

            <p class="lp-field">
                <label for="update-check-interval">Check frequency</label>
                <select id="update-check-interval" name="update_check_interval">
                    <option value="3600" <?= $githubCheckInterval === '3600' ? 'selected' : '' ?>>Hourly</option>
                    <option value="86400" <?= $githubCheckInterval === '86400' ? 'selected' : '' ?>>Daily</option>
                    <option value="604800" <?= $githubCheckInterval === '604800' ? 'selected' : '' ?>>Weekly</option>
                </select>
            </p>

            <p class="lp-field">
                <label for="update-github-token">GitHub token (optional)</label>
                <input type="password" id="update-github-token" name="update_github_token" value="<?= esc_attr($githubToken) ?>" placeholder="Not set" autocomplete="off">
                <span class="lp-field__hint">Only needed if update checks start hitting GitHub's unauthenticated rate limit. A fine-grained personal access token with no permissions (public read access only) is sufficient. Leave blank to keep the current token unchanged.</span>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save settings</button>
        </form>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Update History</h2>
    <?php if ($recentLog === []): ?>
        <p class="lp-admin__widget-placeholder">No updates have been applied yet.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">From</th>
                    <th scope="col">To</th>
                    <th scope="col">Source</th>
                    <th scope="col">Status</th>
                    <th scope="col">Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLog as $entry): ?>
                    <?php $entryStatus = UpdateStatus::tryFrom((string) $entry['status']); ?>
                    <tr>
                        <td><?= esc_html((string) $entry['created_at']) ?></td>
                        <td><?= esc_html((string) $entry['from_version']) ?></td>
                        <td><?= esc_html((string) $entry['to_version']) ?></td>
                        <td><?= esc_html(ucfirst((string) ($entry['source'] ?? 'manual'))) ?></td>
                        <td>
                            <span class="lp-status-badge lp-status-badge--<?= esc_attr((string) $entry['status']) ?>">
                                <?= esc_html($entryStatus?->label() ?? (string) $entry['status']) ?>
                            </span>
                        </td>
                        <td><?= esc_html((string) ($entry['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="lp-admin__panel">
    <h2>About Updates</h2>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>Update check results are cached; use the button above to force a refresh, or adjust the check frequency in Update settings.</li>
        <li>No site content, media, or user data is ever transmitted during an update check.</li>
        <li>Release source: GitHub Releases (<code><?= esc_html($githubRepo) ?></code>), or a manually uploaded ZIP.</li>
        <li>Themes and plugins other than the default theme are preserved during an update — only <code>app/</code>, <code>admin/</code>, <code>include/</code>, <code>install/</code>, <code>docs/</code>, the default theme, and the root PHP files are replaced.</li>
        <li>An automatic file and database backup is created before any update is applied. Use the Backups panel above to create one on demand, or restore/delete an existing one.</li>
        <li>Maintenance mode is automatically enabled for the duration of an update and restored to its previous state afterward — the admin area itself always stays reachable.</li>
        <li>If the <code>install/</code> directory is present when an update completes, it is automatically removed during cleanup.</li>
        <li>If a folder or file that made up an older release is no longer part of a newer one, it's automatically removed once the update finishes — this only ever applies to Lumora Press's own core paths (<code>app/</code>, <code>admin/</code>, <code>include/</code>, etc.); themes other than the default theme, plugins, uploads, and <code>config/</code> are never touched.</li>
        <li>SHA-256 checksum verification is used when the release source provides one.</li>
    </ul>
</section>
