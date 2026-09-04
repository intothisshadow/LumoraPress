<?php

/**
 * The admin Settings > Maintenance Mode screen (LP-033).
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

// The Dashboard's one-click toggle button posts here too (see
// admin/views/dashboard.php).
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

/**
 * Parses a <input type="datetime-local"> value into the app's stored
 * 'Y-m-d H:i:s' format. Returns '' (meaning "unset") for an empty or
 * unparseable value, since PressConfig options are always strings.
 */
$parseScheduleInput = static function (string $raw): string {
    $raw = trim($raw);

    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
    } catch (\Exception) {
        return '';
    }
};

if ($form === 'maintenance_settings' && Csrf::verify('maintenance_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $enabled = ($_POST['maintenance_mode_enabled'] ?? '') === '1';
    $kernel->config->setOption('maintenance_mode_enabled', $enabled ? '1' : '0');
    $kernel->config->setOption('maintenance_title', trim((string) ($_POST['maintenance_title'] ?? '')));
    $kernel->config->setOption('maintenance_message', trim((string) ($_POST['maintenance_message'] ?? '')));
    $kernel->config->setOption('maintenance_start_at', $parseScheduleInput((string) ($_POST['maintenance_start_at'] ?? '')));
    $kernel->config->setOption('maintenance_end_at', $parseScheduleInput((string) ($_POST['maintenance_end_at'] ?? '')));
    $kernel->config->setOption(
        'maintenance_bypass_capability',
        ($_POST['maintenance_allow_editors'] ?? '') === '1' ? 'moderate_comments' : 'manage_options',
    );
    $kernel->config->setOption('maintenance_retry_after_seconds', (string) max(0, (int) ($_POST['maintenance_retry_after_seconds'] ?? 3600)));

    do_action('maintenance_mode_toggled', $enabled);

    header('Location: ' . admin_url('settings/maintenance-mode') . '?saved=1');
    exit;
} elseif ($form === 'maintenance_toggle' && Csrf::verify('maintenance_toggle', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $enabled = $kernel->config->option('maintenance_mode_enabled', '0') === '0';
    $kernel->config->setOption('maintenance_mode_enabled', $enabled ? '1' : '0');

    do_action('maintenance_mode_toggled', $enabled);

    header('Location: ' . admin_url('dashboard') . '?saved=1');
    exit;
}
?>
<h1 class="lp-admin__title">Maintenance Mode</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <form method="post" action="<?= esc_url(admin_url('settings/maintenance-mode')) ?>">
        <?= Csrf::field('maintenance_settings') ?>
        <input type="hidden" name="form" value="maintenance_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="maintenance_mode_enabled" value="1" <?= $kernel->config->option('maintenance_mode_enabled', '0') === '1' ? 'checked' : '' ?>>
            Enable maintenance mode now
        </label>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="maintenance_allow_editors" value="1" <?= $kernel->config->option('maintenance_bypass_capability', 'manage_options') === 'moderate_comments' ? 'checked' : '' ?>>
            Also let Editors bypass maintenance mode (Administrators always can)
        </label>

        <p class="lp-field">
            <label for="maintenance-title">Maintenance page title</label>
            <input type="text" id="maintenance-title" name="maintenance_title" value="<?= esc_attr((string) $kernel->config->option('maintenance_title', 'Maintenance')) ?>">
        </p>

        <p class="lp-field">
            <label for="maintenance-message">Maintenance message</label>
            <textarea id="maintenance-message" name="maintenance_message" rows="3"><?= esc_html((string) $kernel->config->option('maintenance_message', 'We are currently performing scheduled maintenance. Please check back shortly.')) ?></textarea>
        </p>

        <p class="lp-field">
            <label for="maintenance-start-at">Scheduled start (optional)</label>
            <input
                type="datetime-local"
                id="maintenance-start-at"
                name="maintenance_start_at"
                value="<?= esc_attr(($rawStart = (string) $kernel->config->option('maintenance_start_at', '')) !== '' ? (new DateTimeImmutable($rawStart))->format('Y-m-d\TH:i') : '') ?>"
            >
        </p>

        <p class="lp-field">
            <label for="maintenance-end-at">Scheduled end (optional)</label>
            <input
                type="datetime-local"
                id="maintenance-end-at"
                name="maintenance_end_at"
                value="<?= esc_attr(($rawEnd = (string) $kernel->config->option('maintenance_end_at', '')) !== '' ? (new DateTimeImmutable($rawEnd))->format('Y-m-d\TH:i') : '') ?>"
            >
            <span class="lp-field__hint">Shown to visitors as the estimated return time, and used to compute the Retry-After header.</span>
        </p>

        <p class="lp-field">
            <label for="maintenance-retry-after">Retry-After header (seconds, 0 to omit unless a scheduled end is set)</label>
            <input type="number" id="maintenance-retry-after" name="maintenance_retry_after_seconds" min="0" value="<?= esc_attr((string) $kernel->config->option('maintenance_retry_after_seconds', '3600')) ?>">
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
