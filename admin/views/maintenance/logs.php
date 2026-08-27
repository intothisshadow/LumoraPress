<?php

/**
 * The admin Maintenance > Logs screen: the application error log and recorded login attempts (LP-114).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'clear_error_log' && Csrf::verify('clear_error_log', $token)) {
        if ($kernel->errorLog->clear()) {
            header('Location: ' . admin_url('maintenance/logs') . '?cleared=1#application-errors');
            exit;
        }

        $error = 'Could not clear the error log — check that storage/logs/error.log is writable.';
    }
}

// Simple "show more" pagination rather than numbered pages, matching this
// project's lightweight philosophy — capped so a query-string value can't
// force an unbounded read.
$errorLimit = max(25, min(500, (int) ($_GET['error_limit'] ?? 25)));
$loginLimit = max(25, min(500, (int) ($_GET['login_limit'] ?? 25)));

$errorLogExists = $kernel->errorLog->exists();
$errorFileSize = $kernel->errorLog->fileSize();
$errorTotal = $kernel->errorLog->totalEntries();
$errorEntries = $kernel->errorLog->read($errorLimit);

$loginAttempts = $kernel->loginThrottle->recentAttempts($loginLimit);

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
};

// isLocked()/secondsUntilUnlocked() each run a query, and the same IP can
// repeat across many rows — cache per unique IP so the table doesn't issue
// a duplicate lockout query per row.
$lockoutStatusByIp = [];
$lockoutStatus = static function (string $ip) use ($kernel, &$lockoutStatusByIp): int {
    return $lockoutStatusByIp[$ip] ??= $kernel->loginThrottle->secondsUntilUnlocked($ip);
};
?>
<h1 class="lp-admin__title">Logs</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (($_GET['cleared'] ?? null) === '1'): ?>
    <div class="lp-alert lp-alert--success">Error log cleared.</div>
<?php endif; ?>

<section class="lp-admin__panel" id="application-errors">
    <h2>Application Errors</h2>
    <p class="lp-field__hint">
        Uncaught exceptions and fatal PHP errors, newest first. Read from
        <code>storage/logs/error.log</code>.
    </p>

    <?php if (!$errorLogExists): ?>
        <p class="lp-admin__widget-placeholder">No errors have been logged yet.</p>
    <?php else: ?>
        <p class="lp-field__hint">
            <?= esc_html((string) $errorTotal) ?> entr<?= $errorTotal === 1 ? 'y' : 'ies' ?>
            &middot; <?= esc_html($formatBytes($errorFileSize)) ?>
        </p>

        <form method="post" action="<?= esc_url(admin_url('maintenance/logs')) ?>#application-errors" data-lp-confirm="Permanently clear the error log? This cannot be undone.">
            <?= Csrf::field('clear_error_log') ?>
            <input type="hidden" name="form" value="clear_error_log">
            <button type="submit" class="lp-button lp-button--danger">Clear Log</button>
        </form>

        <?php if ($errorEntries === []): ?>
            <p class="lp-admin__widget-placeholder">The error log exists but has no readable entries.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Exception</th>
                        <th scope="col">Message</th>
                        <th scope="col">Location</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errorEntries as $entry): ?>
                        <tr>
                            <td><?= esc_html($entry['timestamp'] ?? '—') ?></td>
                            <td><?= esc_html($entry['exception_class'] ?? 'Unknown') ?></td>
                            <td><?= esc_html($entry['message']) ?></td>
                            <td>
                                <?php if ($entry['file'] !== null): ?>
                                    <code><?= esc_html(basename($entry['file'])) ?>:<?= esc_html((string) $entry['line']) ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (trim($entry['trace']) !== ''): ?>
                            <tr>
                                <td colspan="4">
                                    <details>
                                        <summary>Stack trace</summary>
                                        <pre><?= esc_html($entry['trace']) ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($errorTotal > count($errorEntries)): ?>
                <p>
                    <a class="lp-button" href="<?= esc_url(admin_url('maintenance/logs') . '?error_limit=' . ($errorLimit + 25) . '&login_limit=' . $loginLimit . '#application-errors') ?>">
                        Show 25 more
                    </a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="lp-admin__panel" id="login-attempts">
    <h2>Login Attempts</h2>
    <p class="lp-field__hint">
        Failed login attempts, newest first, used to decide brute-force
        lockouts. Thresholds are configured on Settings &rsaquo; Security.
    </p>

    <?php if ($loginAttempts === []): ?>
        <p class="lp-admin__widget-placeholder">No failed login attempts have been recorded.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">IP Address</th>
                    <th scope="col">Username</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loginAttempts as $attempt): ?>
                    <?php $secondsLocked = $lockoutStatus((string) $attempt['ip_address']); ?>
                    <tr>
                        <td><?= esc_html((string) $attempt['attempted_at']) ?></td>
                        <td><code><?= esc_html((string) $attempt['ip_address']) ?></code></td>
                        <td><?= esc_html((string) ($attempt['username'] ?? '—')) ?></td>
                        <td>
                            <?php if ($secondsLocked > 0): ?>
                                <span class="lp-status-badge lp-status-badge--warning">
                                    Locked (<?= esc_html((string) ceil($secondsLocked / 60)) ?> min)
                                </span>
                            <?php else: ?>
                                Not locked
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($loginAttempts) >= $loginLimit): ?>
            <p>
                <a class="lp-button" href="<?= esc_url(admin_url('maintenance/logs') . '?error_limit=' . $errorLimit . '&login_limit=' . ($loginLimit + 25) . '#login-attempts') ?>">
                    Show 25 more
                </a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>
