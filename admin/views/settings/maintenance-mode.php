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

use LumoraPress\Controllers\Admin\FeaturedImageDefaultsController;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\IpRangeMatcher;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// The Dashboard's one-click toggle button posts here too (see
// admin/views/dashboard.php).
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

// The background-image picker (featured-image-picker.js) queries this page for JSON.
if ($form === FeaturedImageDefaultsController::MAINTENANCE_BACKGROUND_PICKER_CSRF_ACTION) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $pickerResponse = (new FeaturedImageDefaultsController($kernel->config, $kernel->media, $kernel->thumbnails))->pickerQuery(
        $_POST,
        $currentUser->can('upload_files'),
        is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null,
        FeaturedImageDefaultsController::MAINTENANCE_BACKGROUND_PICKER_CSRF_ACTION,
    );
    http_response_code($pickerResponse['status']);
    header('Content-Type: application/json');
    echo json_encode($pickerResponse['body']);
    exit;
}

$ignoredWhitelistLines = [];

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
    $kernel->config->setOption('maintenance_show_countdown', ($_POST['maintenance_show_countdown'] ?? '') === '1' ? '1' : '0');

    // Invalid lines are dropped, never stored: a typo in an allow-list
    // must not silently look like a working rule.
    $validRules = [];

    foreach (IpRangeMatcher::parseList((string) ($_POST['maintenance_ip_whitelist'] ?? '')) as $rule) {
        if (IpRangeMatcher::isValidRule($rule)) {
            $validRules[] = $rule;
        } else {
            $ignoredWhitelistLines[] = $rule;
        }
    }

    $kernel->config->setOption('maintenance_ip_whitelist', implode("\n", array_values(array_unique($validRules))));

    foreach (['background', 'text', 'accent'] as $colorKey) {
        $color = trim((string) ($_POST['maintenance_' . $colorKey . '_color'] ?? ''));
        $kernel->config->setOption(
            'maintenance_' . $colorKey . '_color',
            preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) === 1 ? $color : '',
        );
    }

    // Only an existing image is accepted, so a stale or tampered id can't point the page at a non-image.
    $backgroundId = max(0, (int) ($_POST['maintenance_background_media_id'] ?? 0));
    $backgroundItem = $backgroundId > 0 ? $kernel->media->find($backgroundId) : null;
    $kernel->config->setOption(
        'maintenance_background_media_id',
        $backgroundItem !== null && str_starts_with((string) ($backgroundItem['mime_type'] ?? ''), 'image/') ? (string) $backgroundId : '0',
    );

    $kernel->config->setOption('maintenance_custom_css', trim((string) preg_replace('/<\/style\s*>/i', '', (string) ($_POST['maintenance_custom_css'] ?? ''))));

    do_action('maintenance_mode_toggled', $enabled);

    header('Location: ' . admin_url('settings/maintenance-mode') . '?saved=1' . ($ignoredWhitelistLines !== [] ? '&ignored=' . count($ignoredWhitelistLines) : ''));
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

<?php if ((int) ($_GET['ignored'] ?? 0) > 0): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html((string) (int) $_GET['ignored']) ?> line<?= (int) $_GET['ignored'] === 1 ? ' was' : 's were' ?> not a valid IP address or range and <?= (int) $_GET['ignored'] === 1 ? 'was' : 'were' ?> left out of the IP whitelist.</div>
<?php endif; ?>

<?php
$backgroundMediaId = (int) $kernel->config->option('maintenance_background_media_id', '0');
$backgroundMedia = $backgroundMediaId > 0 ? $kernel->media->find($backgroundMediaId) : null;
$pickerFolders = array_map(
    static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
    $kernel->folders->listAllForTree(),
);
$colorPattern = '#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})';
$yourIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
?>
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

        <label class="lp-field--checkbox">
            <input type="checkbox" name="maintenance_show_countdown" value="1" <?= $kernel->config->option('maintenance_show_countdown', '0') === '1' ? 'checked' : '' ?>>
            Show a live countdown to the scheduled end
        </label>
        <p class="lp-field__hint">Needs a scheduled end time above. The page reloads itself when the countdown reaches zero.</p>

        <h2>Who can still see the site</h2>

        <p class="lp-field">
            <label for="maintenance-ip-whitelist">IP whitelist (optional)</label>
            <textarea id="maintenance-ip-whitelist" name="maintenance_ip_whitelist" rows="4" spellcheck="false" autocomplete="off"><?= esc_html((string) $kernel->config->option('maintenance_ip_whitelist', '')) ?></textarea>
            <span class="lp-field__hint">Visitors from these addresses see the normal site while maintenance mode is on, signed in or not. One IP address or range (such as <code>203.0.113.0/24</code>) per line. Your current address is <code><?= esc_html($yourIp) ?></code>.</span>
        </p>

        <h2>Maintenance page appearance</h2>

        <fieldset class="lp-field">
            <legend>Background image</legend>
            <div
                class="lp-featured-image-picker"
                data-lp-featured-image-picker
                data-picker-url="<?= esc_url(admin_url('settings/maintenance-mode')) ?>"
                data-picker-action="<?= esc_attr(FeaturedImageDefaultsController::MAINTENANCE_BACKGROUND_PICKER_CSRF_ACTION) ?>"
                data-picker-csrf="<?= esc_attr(Csrf::token(FeaturedImageDefaultsController::MAINTENANCE_BACKGROUND_PICKER_CSRF_ACTION)) ?>"
                data-media-folders="<?= esc_attr((string) json_encode($pickerFolders)) ?>"
            >
                <input type="hidden" name="maintenance_background_media_id" data-picker-value value="<?= $backgroundMediaId ?>">
                <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Media Manager&hellip;</button>
                <button type="button" class="lp-button lp-button--link lp-button--link--danger" data-picker-remove <?= $backgroundMedia === null ? 'hidden' : '' ?>>Remove</button>
                <span class="lp-featured-image-picker__chosen" data-picker-chosen>
                    <?php if ($backgroundMedia !== null): ?>
                        <img class="lp-featured-image-picker__chosen-thumb" src="<?= esc_url($kernel->media->url($backgroundMedia)) ?>" alt="">
                        <?= esc_html((string) $backgroundMedia['file_name']) ?>
                    <?php endif; ?>
                </span>
            </div>
            <p class="lp-field__hint">Covers the whole page behind the message. Leave empty for the theme's own background.</p>
        </fieldset>

        <p class="lp-field">
            <label for="maintenance-background-color">Background color</label>
            <input type="text" id="maintenance-background-color" name="maintenance_background_color" maxlength="9" pattern="<?= esc_attr($colorPattern) ?>" placeholder="#1a1a2e" value="<?= esc_attr((string) $kernel->config->option('maintenance_background_color', '')) ?>">
        </p>

        <p class="lp-field">
            <label for="maintenance-text-color">Text color</label>
            <input type="text" id="maintenance-text-color" name="maintenance_text_color" maxlength="9" pattern="<?= esc_attr($colorPattern) ?>" placeholder="#ffffff" value="<?= esc_attr((string) $kernel->config->option('maintenance_text_color', '')) ?>">
        </p>

        <p class="lp-field">
            <label for="maintenance-accent-color">Countdown color</label>
            <input type="text" id="maintenance-accent-color" name="maintenance_accent_color" maxlength="9" pattern="<?= esc_attr($colorPattern) ?>" placeholder="#e0a458" value="<?= esc_attr((string) $kernel->config->option('maintenance_accent_color', '')) ?>">
            <span class="lp-field__hint">Colors are written as hex values such as <code>#336699</code>. Leave a color blank to keep the theme's own. Check that your text stays readable against the background.</span>
        </p>

        <p class="lp-field">
            <label for="maintenance-custom-css">Custom CSS</label>
            <textarea id="maintenance-custom-css" name="maintenance_custom_css" rows="6" spellcheck="false" autocomplete="off"><?= esc_html((string) $kernel->config->option('maintenance_custom_css', '')) ?></textarea>
            <span class="lp-field__hint">Extra CSS added to the maintenance page only. The page's elements use the classes <code>.lp-maintenance</code>, <code>.lp-maintenance__message</code>, <code>.lp-maintenance__return-at</code>, and <code>.lp-maintenance__countdown</code>.</span>
        </p>

        <input type="hidden" name="preview_token" value="<?= esc_attr($kernel->maintenance->previewToken()) ?>">

        <button type="submit" class="lp-button lp-button--primary">Save</button>
        <button type="submit" class="lp-button lp-button--secondary" formaction="<?= esc_url(site_url('maintenance-preview')) ?>" formtarget="_blank" data-lp-maintenance-preview data-preview-url="<?= esc_url(site_url('maintenance-preview')) ?>">Preview in New Tab</button>
        <span class="lp-field__hint">Shows the maintenance page with the values above, including any you haven't saved yet. Nothing is changed.</span>
    </form>
</section>
