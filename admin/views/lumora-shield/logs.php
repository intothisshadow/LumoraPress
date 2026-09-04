<?php

/**
 * The admin Lumora Shield > Logs screen: recorded blocked enumeration attempts (LPP-001's Monitoring sub-module).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Plugins\LumoraShield\LumoraShieldService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the plugin is active.
$service = LumoraShieldService::instance();

// Simple "show more" pagination rather than numbered pages, matching
// admin/views/maintenance/logs.php's identical convention.
$limit = max(25, min(500, (int) ($_GET['limit'] ?? 25)));
$attempts = $service->recentEnumerationAttempts($limit);

$reasonLabels = [
    'unknown_user' => 'Unknown username',
    'zero_posts' => 'No published posts',
    'hidden_by_setting' => 'Hidden (setting)',
];

// requested_slug repeats across rows for a single brute-force run — same
// per-IP-memoized idiom admin/views/maintenance/logs.php uses for lockout
// status, applied here to the attempt count instead.
$countByIp = [];
$attemptCountForIp = static function (string $ip) use ($service, &$countByIp): int {
    return $countByIp[$ip] ??= $service->countEnumerationAttemptsForIp($ip, 3600);
};
?>
<h1 class="lp-admin__title">Lumora Shield &rsaquo; Logs</h1>

<section class="lp-admin__panel">
    <h2>Blocked Enumeration Attempts</h2>
    <p class="lp-field__hint">
        Every blocked <code>/author/{slug}</code> request, newest first —
        an unknown username, a real author with no published posts, or an
        archive hidden by the setting on
        <a href="<?= esc_url(admin_url('lumora-shield/settings')) ?>">Lumora Shield &rsaquo; Settings</a>.
        Logging, retention, and email alerts are also configured there.
    </p>

    <?php if ($attempts === []): ?>
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
                <?php foreach ($attempts as $attempt): ?>
                    <tr>
                        <td><?= esc_html($attempt['attemptedAt']) ?></td>
                        <td><code><?= esc_html($attempt['ipAddress']) ?></code></td>
                        <td><code><?= esc_html($attempt['requestedSlug']) ?></code></td>
                        <td><?= esc_html($reasonLabels[$attempt['reason']] ?? $attempt['reason']) ?></td>
                        <td><?= esc_html((string) $attemptCountForIp($attempt['ipAddress'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($attempts) >= $limit): ?>
            <p>
                <a class="lp-button" href="<?= esc_url(admin_url('lumora-shield/logs') . '?limit=' . ($limit + 25)) ?>">
                    Show 25 more
                </a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>
