<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\UpdateStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$updates = $kernel->updates;
$error = null;
$checkResult = null;
$githubRelease = null;
$githubChecked = false;

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
                $checkResult = $updates->checkUpload($_FILES['package']['tmp_name'], $allowDowngrade);
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
        $githubChecked = true;

        try {
            $githubRelease = $kernel->githubUpdates->fetchLatestRelease();
            $kernel->config->setOption('update_last_checked_at', (string) time());

            if ($githubRelease === null) {
                $error = 'Could not reach GitHub, or the configured repository has no releases yet. Check the repository setting below and try again.';
            } else {
                $kernel->config->setOption('update_last_known_version', $githubRelease['latest_version']);
                $kernel->config->setOption('update_last_known_changelog_url', (string) ($githubRelease['changelog_url'] ?? ''));
            }
        } catch (\Throwable $exception) {
            $error = 'Could not check for updates: ' . $exception->getMessage();
        }
    } elseif ($form === 'github_download' && Csrf::verify('github_download', $token)) {
        $allowDowngrade = ($_POST['allow_downgrade'] ?? '') === '1';
        $downloadPath = null;

        try {
            $release = $kernel->githubUpdates->fetchLatestRelease();

            if ($release === null) {
                throw new \RuntimeException('Could not reach GitHub to download the release.');
            }

            $downloadDir = rtrim(LUMORA_ROOT, '/') . '/storage/updates/downloads';

            if (!is_dir($downloadDir) && !mkdir($downloadDir, 0755, true) && !is_dir($downloadDir)) {
                throw new \RuntimeException('Unable to prepare the downloads directory.');
            }

            $downloadPath = $downloadDir . '/' . bin2hex(random_bytes(16)) . '.zip';

            $kernel->githubUpdates->downloadRelease($release, $downloadPath);

            $checkResult = $updates->checkUpload($downloadPath, $allowDowngrade, 'github');
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
    }
}

$installedVersion = $updates->installedVersion();
$recentLog = $updates->recentLog(10);
$backups = $updates->listBackups();
$githubRepo = (string) $kernel->config->option('update_github_repo', 'intothisshadow/LumoraPress');
$githubToken = (string) $kernel->config->option('update_github_token', '');
$githubChannel = (string) $kernel->config->option('update_channel', 'stable');
$githubAutoCheckEnabled = ((string) $kernel->config->option('update_auto_check_enabled', '1')) === '1';
$githubCheckInterval = (string) $kernel->config->option('update_check_interval', '86400');
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

<section class="lp-admin__panel">
    <h2>Current Version</h2>
    <p>Lumora Press <strong><?= esc_html($installedVersion) ?></strong></p>
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
            <p>Resolve the issues above, then upload the package again.</p>
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
        <h2>Check for Updates (GitHub)</h2>

        <?php if ($githubChecked && $githubRelease !== null): ?>
            <?php $updateAvailable = version_compare($githubRelease['latest_version'], $installedVersion, '>'); ?>

            <?php if (!$updateAvailable): ?>
                <p>You're running the latest <?= $githubRelease['prerelease'] ? 'pre-release' : 'stable' ?> version available on GitHub (<strong><?= esc_html($githubRelease['latest_version']) ?></strong>).</p>
            <?php else: ?>
                <p>
                    A new version is available: <strong><?= esc_html($githubRelease['latest_version']) ?></strong>
                    <?php if ($githubRelease['release_date'] !== null): ?>
                        (released <?= esc_html($githubRelease['release_date']) ?>)
                    <?php endif; ?>
                    <?php if ($githubRelease['prerelease']): ?>
                        <span class="lp-status-badge lp-status-badge--warning">Pre-release</span>
                    <?php endif; ?>
                </p>

                <?php if ($githubRelease['changelog_url'] !== null): ?>
                    <p><a href="<?= esc_url($githubRelease['changelog_url']) ?>" target="_blank" rel="noopener noreferrer">View release notes on GitHub</a></p>
                <?php endif; ?>

                <?php if ($githubRelease['download']['size'] !== null): ?>
                    <p class="lp-field__hint">Download size: <?= esc_html(number_format($githubRelease['download']['size'] / 1024 / 1024, 1)) ?> MB</p>
                <?php endif; ?>

                <?php if ($githubRelease['release_notes'] !== null): ?>
                    <details class="lp-admin__panel">
                        <summary><?= esc_html($githubRelease['release_name'] ?? ('Release notes for ' . $githubRelease['latest_version'])) ?></summary>
                        <pre class="lp-update__release-notes"><?= esc_html($githubRelease['release_notes']) ?></pre>
                    </details>
                <?php endif; ?>

                <?php if ($githubRelease['sha256'] === null): ?>
                    <div class="lp-alert lp-alert--warning">This release has no published checksum — the download will be installed without checksum verification.</div>
                <?php endif; ?>

                <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>">
                    <?= Csrf::field('github_download') ?>
                    <input type="hidden" name="form" value="github_download">

                    <p class="lp-field lp-field--checkbox">
                        <input type="checkbox" id="github-allow-downgrade" name="allow_downgrade" value="1">
                        <label for="github-allow-downgrade">Allow installing an older version than what is currently installed</label>
                    </p>

                    <button type="submit" class="lp-button lp-button--primary">Download &amp; Check</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p>Lumora Press <strong><?= esc_html($installedVersion) ?></strong> is currently installed. Check GitHub for a newer release.</p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>">
                <?= Csrf::field('github_check') ?>
                <input type="hidden" name="form" value="github_check">
                <button type="submit" class="lp-button lp-button--primary">Check for Updates</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="lp-admin__panel">
        <h2>GitHub Update Settings</h2>
        <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>">
            <?= Csrf::field('github_settings') ?>
            <input type="hidden" name="form" value="github_settings">

            <p class="lp-field">
                <label for="update-github-repo">Repository</label>
                <input type="text" id="update-github-repo" name="update_github_repo" value="<?= esc_attr($githubRepo) ?>" placeholder="owner/repo">
                <span class="lp-field__hint">The GitHub repository releases are checked against, as "owner/repo".</span>
            </p>

            <p class="lp-field">
                <label for="update-github-token">Personal access token (optional)</label>
                <input type="password" id="update-github-token" name="update_github_token" value="<?= esc_attr($githubToken) ?>" autocomplete="off">
                <span class="lp-field__hint">Only needed for a private repository, or to raise GitHub's unauthenticated API rate limit. Never required for the public Lumora Press repository under normal use.</span>
            </p>

            <p class="lp-field">
                <label for="update-channel">Release channel</label>
                <select id="update-channel" name="update_channel">
                    <option value="stable" <?= $githubChannel === 'stable' ? 'selected' : '' ?>>Stable only</option>
                    <option value="prerelease" <?= $githubChannel === 'prerelease' ? 'selected' : '' ?>>Include pre-releases</option>
                </select>
            </p>

            <p class="lp-field lp-field--checkbox">
                <input type="checkbox" id="update-auto-check-enabled" name="update_auto_check_enabled" value="1" <?= $githubAutoCheckEnabled ? 'checked' : '' ?>>
                <label for="update-auto-check-enabled">Automatically check for updates when visiting the Dashboard</label>
                <span class="lp-field__hint">This only checks — it never downloads or installs anything automatically. A notice appears on the Dashboard when a newer version is found.</span>
            </p>

            <p class="lp-field">
                <label for="update-check-interval">Check frequency</label>
                <select id="update-check-interval" name="update_check_interval">
                    <option value="3600" <?= $githubCheckInterval === '3600' ? 'selected' : '' ?>>Hourly</option>
                    <option value="86400" <?= $githubCheckInterval === '86400' ? 'selected' : '' ?>>Daily</option>
                    <option value="604800" <?= $githubCheckInterval === '604800' ? 'selected' : '' ?>>Weekly</option>
                </select>
            </p>

            <button type="submit" class="lp-button">Save Settings</button>
        </form>
    </section>

    <section class="lp-admin__panel">
        <h2>Upload Update Package</h2>
        <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>" enctype="multipart/form-data">
            <?= Csrf::field('update_upload') ?>
            <input type="hidden" name="form" value="upload">

            <p class="lp-field">
                <label for="update-package">Release ZIP file</label>
                <input type="file" id="update-package" name="package" accept=".zip" required>
                <span class="lp-field__hint">Only official Lumora Press release packages should be uploaded here.</span>
            </p>

            <p class="lp-field lp-field--checkbox">
                <input type="checkbox" id="update-allow-downgrade" name="allow_downgrade" value="1">
                <label for="update-allow-downgrade">Allow installing an older version than what is currently installed</label>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Upload &amp; Check</button>
        </form>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Recent Updates</h2>
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
    <h2>Backups</h2>
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
                        <td>
                            <?php if ($backup['files_filename'] !== null): ?>
                                <form method="post" action="<?= esc_url(admin_url('maintenance/updates')) ?>" class="lp-admin__inline-form" onsubmit="return confirm('Restore Lumora Press to this backup? Everything since it was taken will be lost.');">
                                    <?= Csrf::field('restore_backup') ?>
                                    <input type="hidden" name="form" value="restore_backup">
                                    <input type="hidden" name="files_filename" value="<?= esc_attr($backup['files_filename']) ?>">
                                    <?php if ($backup['database_filename'] !== null): ?>
                                        <input type="hidden" name="database_filename" value="<?= esc_attr($backup['database_filename']) ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="lp-button lp-button--danger">Restore</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
