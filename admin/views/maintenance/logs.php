<?php

/**
 * The admin Maintenance > Logs screen: the application error log, recorded login attempts, and (while Lumora Shield is active) blocked enumeration attempts (LP-114/LP-153).
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
use LumoraPress\Plugins\LumoraShield\LumoraShieldService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// The class is only loaded while the plugin is active, so this doubles as
// the "is Lumora Shield active" check, matching settings/security.php's
// own use of this same technique.
$lumoraShieldActive = class_exists(LumoraShieldService::class, false);

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

    if ($form === 'clear_security_log' && Csrf::verify('clear_security_log', $token)) {
        if ($kernel->securityLog->clear()) {
            header('Location: ' . admin_url('maintenance/logs') . '?security_cleared=1#security-events');
            exit;
        }

        $error = 'Could not clear the security log — check that storage/logs/ is writable.';
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

$securityLimit = max(25, min(500, (int) ($_GET['security_limit'] ?? 25)));
$securityEvents = $kernel->securityLog->recent($securityLimit);

$securityEventLabels = [
    'csrf_failure' => 'Invalid or expired security token',
    'honeypot_triggered' => 'Hidden trap field filled in',
    'form_too_fast' => 'Form submitted too quickly',
    'form_expired' => 'Comment form open too long',
    'comment_rate_limited' => 'Comment rate limit reached',
    'login_lockout' => 'Login lockout',
];

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

$shieldLimit = max(25, min(500, (int) ($_GET['shield_limit'] ?? 25)));
$shieldAttempts = $lumoraShieldActive ? LumoraShieldService::instance()->recentEnumerationAttempts($shieldLimit) : [];

$shieldReasonLabels = [
    'unknown_user' => 'Unknown username',
    'zero_posts' => 'No published posts',
    'hidden_by_setting' => 'Hidden (setting)',
];

$spamLimit = max(25, min(500, (int) ($_GET['spam_limit'] ?? 25)));
$spamEvents = $lumoraShieldActive ? LumoraShieldService::instance()->recentSpamEvents($spamLimit) : [];

$spamRuleLabels = [
    'extremely_short' => 'Extremely short',
    'extremely_long' => 'Extremely long',
    'excessive_links' => 'Excessive links',
    'excessive_uppercase' => 'Excessive uppercase',
    'excessive_punctuation' => 'Excessive punctuation',
    'repeated_phrase' => 'Repeated phrase',
    'hidden_characters' => 'Hidden characters',
    'previous_spam_history' => 'Previous spam history',
    'posting_frequency' => 'Posting frequency',
    'duplicate_comment' => 'Duplicate comment',
];

$spamSourceLabels = ['comment' => 'Comment', 'contact_form' => 'Contact form'];

// requested_slug repeats across rows for a single brute-force run — same
// per-IP-memoized idiom this file's own $lockoutStatus already uses,
// applied here to the attempt count instead.
$shieldCountByIp = [];
$shieldAttemptCountForIp = static function (string $ip) use ($lumoraShieldActive, &$shieldCountByIp): int {
    if (!$lumoraShieldActive) {
        return 0;
    }

    return $shieldCountByIp[$ip] ??= LumoraShieldService::instance()->countEnumerationAttemptsForIp($ip, 3600);
};
?>
<h1 class="lp-admin__title">Logs</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (($_GET['cleared'] ?? null) === '1'): ?>
    <div class="lp-alert lp-alert--success">Error log cleared.</div>
<?php endif; ?>

<?php if (($_GET['security_cleared'] ?? null) === '1'): ?>
    <div class="lp-alert lp-alert--success">Security log cleared.</div>
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
                    <a class="lp-button" href="<?= esc_url(admin_url('maintenance/logs') . '?error_limit=' . ($errorLimit + 25) . '&login_limit=' . $loginLimit . '&shield_limit=' . $shieldLimit . '#application-errors') ?>">
                        Show 25 more
                    </a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="lp-admin__panel" id="security-events">
    <h2>Security Events</h2>
    <p class="lp-field__hint">
        Blocked spam and abuse attempts, newest first: invalid security
        tokens, filled-in trap fields, forms submitted too quickly, comment
        rate limits, and login lockouts. Only the event, the IP address, and
        a short note are kept &mdash; never comment text, passwords, or
        security tokens. The log keeps itself small by rotating at about
        half a megabyte.
    </p>

    <?php if ($securityEvents === []): ?>
        <p class="lp-admin__widget-placeholder">No security events have been recorded.</p>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('maintenance/logs')) ?>#security-events" data-lp-confirm="Permanently clear the security log? This cannot be undone.">
            <?= Csrf::field('clear_security_log') ?>
            <input type="hidden" name="form" value="clear_security_log">
            <button type="submit" class="lp-button lp-button--danger">Clear Log</button>
        </form>

        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">Event</th>
                    <th scope="col">IP Address</th>
                    <th scope="col">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($securityEvents as $event): ?>
                    <tr>
                        <td><?= esc_html($event['time']) ?></td>
                        <td><?= esc_html($securityEventLabels[$event['event']] ?? $event['event']) ?></td>
                        <td><?= esc_html($event['ip']) ?></td>
                        <td><?= esc_html($event['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($securityEvents) >= $securityLimit): ?>
            <p>
                <a class="lp-button" href="<?= esc_url(admin_url('maintenance/logs') . '?error_limit=' . $errorLimit . '&login_limit=' . $loginLimit . '&shield_limit=' . $shieldLimit . '&security_limit=' . ($securityLimit + 25) . '#security-events') ?>">
                    Show 25 more
                </a>
            </p>
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
                <a class="lp-button" href="<?= esc_url(admin_url('maintenance/logs') . '?error_limit=' . $errorLimit . '&login_limit=' . ($loginLimit + 25) . '&shield_limit=' . $shieldLimit . '#login-attempts') ?>">
                    Show 25 more
                </a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($lumoraShieldActive): ?>
<section class="lp-admin__panel" id="enumeration-attempts">
    <h2>Blocked Enumeration Attempts</h2>
    <p class="lp-field__hint">
        Every blocked <code>/author/{slug}</code> request, newest first —
        an unknown username, a real author with no published posts, or an
        archive hidden by the setting on
        <a href="<?= esc_url(admin_url('settings/security') . '?tab=lumora-shield#monitoring') ?>">Settings &rsaquo; Security &rsaquo; Lumora Shield</a>.
        Logging, retention, and email alerts are also configured there.
    </p>

    <?php if ($shieldAttempts === []): ?>
        <p class="lp-admin__widget-placeholder">No blocked enumeration attempts have been recorded.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">IP Address</th>
                    <th scope="col">Requested Slug</th>
                    <th scope="col">Reason</th>
                    <th scope="col">Attempts (past hour)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shieldAttempts as $attempt): ?>
                    <tr>
                        <td><?= esc_html($attempt['attemptedAt']) ?></td>
                        <td><code><?= esc_html($attempt['ipAddress']) ?></code></td>
                        <td><code><?= esc_html($attempt['requestedSlug']) ?></code></td>
                        <td><?= esc_html($shieldReasonLabels[$attempt['reason']] ?? $attempt['reason']) ?></td>
                        <td><?= esc_html((string) $shieldAttemptCountForIp($attempt['ipAddress'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($shieldAttempts) >= $shieldLimit): ?>
            <p>
                <a class="lp-button" href="<?= esc_url(admin_url('maintenance/logs') . '?error_limit=' . $errorLimit . '&login_limit=' . $loginLimit . '&shield_limit=' . ($shieldLimit + 25) . '#enumeration-attempts') ?>">
                    Show 25 more
                </a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="lp-admin__panel" id="spam-events">
    <h2>Spam Analysis Log</h2>
    <p class="lp-field__hint">
        Every comment or contact form submission Lumora Shield flagged as spam, newest first, with the checks that caught it.
        A comment that landed in Spam by mistake can be restored from
        <a href="<?= esc_url(admin_url('comments')) ?>">Comments</a>;
        this log shows which check to blame. Retention follows the log retention setting on
        <a href="<?= esc_url(admin_url('settings/security') . '?tab=lumora-shield#monitoring') ?>">Settings &rsaquo; Security &rsaquo; Lumora Shield</a>.
    </p>

    <?php if ($spamEvents === []): ?>
        <p class="lp-admin__widget-placeholder">No spam has been flagged by Lumora Shield.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">Source</th>
                    <th scope="col">Author</th>
                    <th scope="col">IP Address</th>
                    <th scope="col">Score</th>
                    <th scope="col">Triggered Rules</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($spamEvents as $event): ?>
                    <tr>
                        <td><?= esc_html($event['createdAt']) ?></td>
                        <td>
                            <?= esc_html($spamSourceLabels[$event['source']] ?? $event['source']) ?>
                            <?php if ($event['commentId'] !== null): ?>
                                <a href="<?= esc_url(admin_url('comments')) ?>?action=edit&amp;id=<?= (int) $event['commentId'] ?>">#<?= (int) $event['commentId'] ?></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= esc_html($event['authorName'] !== '' ? $event['authorName'] : '—') ?>
                            <?php if ($event['authorEmail'] !== ''): ?>
                                <br><span class="lp-field__hint"><?= esc_html($event['authorEmail']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= esc_html($event['ipAddress']) ?></code></td>
                        <td><?= esc_html((string) $event['spamScore']) ?></td>
                        <td>
                            <?= esc_html(implode(', ', array_map(static fn (string $rule): string => $spamRuleLabels[$rule] ?? $rule, $event['rules']))) ?>
                        </td>
                        <td><?= esc_html(ucfirst($event['action'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($spamEvents) >= $spamLimit): ?>
            <p>
                <a class="lp-button" href="<?= esc_url(admin_url('maintenance/logs') . '?error_limit=' . $errorLimit . '&login_limit=' . $loginLimit . '&shield_limit=' . $shieldLimit . '&spam_limit=' . ($spamLimit + 25) . '#spam-events') ?>">
                    Show 25 more
                </a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
