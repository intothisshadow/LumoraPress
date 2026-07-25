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
    }
}

$installedVersion = $updates->installedVersion();
$recentLog = $updates->recentLog(10);
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
