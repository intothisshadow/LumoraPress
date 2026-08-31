<?php

/**
 * The admin Lumora Gallery Shortcodes > Settings screen, provided by the bundled Lumora Gallery Shortcodes plugin (LPP-015).
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

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\LumoraGalleryShortcodes\GalleryConfigParser;
use LumoraPress\Plugins\LumoraGalleryShortcodes\GallerySettingsService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LPP-015. Only reachable while the plugin is active (admin/index.php
 * only adds this menu entry in that case), so GallerySettingsService's
 * class is guaranteed to already be loaded — PluginManager::loadActive()
 * required content/plugins/lumora-gallery-shortcodes/
 * lumora-gallery-shortcodes.php earlier this same request, in
 * include/bootstrap.php. Mirrors lumora-shield/settings.php's identical
 * reasoning.
 */
$service = new GallerySettingsService();
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$testResult = null;
$detectResult = null;
$settings = $service->settings();

/*
 * Seeded from the persisted settings by default, same as before this
 * ticket — only a "detect_gallery_config" submission (below) actually
 * overwrites any of these for the response about to render. "save"/
 * "test" always redirect or re-derive from the just-saved settings, so
 * they never need $formValues at all.
 */
$formValues = [
    'db_host' => $settings['db_host'],
    'db_port' => $settings['db_port'],
    'db_name' => $settings['db_name'],
    'db_user' => $settings['db_user'],
    'db_password' => $settings['db_password'],
    'table_prefix' => $settings['table_prefix'],
    'base_url' => $settings['base_url'],
    'gallery_config_path' => is_string($_POST['gallery_config_path'] ?? null) ? $_POST['gallery_config_path'] : '',
];

if ($form === 'lumora_gallery_shortcodes_settings' && Csrf::verify('lumora_gallery_shortcodes_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $submitted = [
        'db_host' => trim((string) ($_POST['db_host'] ?? '')),
        'db_port' => max(1, (int) ($_POST['db_port'] ?? 3306)),
        'db_name' => trim((string) ($_POST['db_name'] ?? '')),
        'db_user' => trim((string) ($_POST['db_user'] ?? '')),
        // An unchanged, masked password field posts back the placeholder
        // rather than the real value (see the <input> below) — keep the
        // already-saved password in that case instead of overwriting it
        // with the placeholder text itself.
        'db_password' => ($_POST['db_password'] ?? '') === '••••••••' ? $service->settings()['db_password'] : (string) ($_POST['db_password'] ?? ''),
        'table_prefix' => trim((string) ($_POST['table_prefix'] ?? '')) !== '' ? trim((string) $_POST['table_prefix']) : 'lum_',
        'base_url' => rtrim(trim((string) ($_POST['base_url'] ?? '')), '/'),
    ];

    $service->saveSettings($submitted);

    if (($_POST['action'] ?? '') === 'test') {
        $testResult = $service->testConnection()
            ? ['ok' => true, 'message' => 'Connected successfully — the albums/images tables were found.']
            : ['ok' => false, 'message' => 'Could not connect, or the albums/images tables were not found. Double-check the host, credentials, database name, and table prefix.'];
        $settings = $service->settings();
        $formValues = [...$formValues, ...$settings];
    } else {
        header('Location: ' . admin_url('lumora-gallery-shortcodes/settings') . '?saved=1');
        exit;
    }
} elseif ($form === 'detect_gallery_config' && Csrf::verify('detect_gallery_config', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    try {
        $detected = (new GalleryConfigParser())->parse($formValues['gallery_config_path']);

        // Only overwrite fields config.php actually named — mirrors
        // WordPressConfigParser's identical caller-side merge in
        // admin/views/maintenance/import.php, for the same reason: a
        // partially-recognized config.php shouldn't blank out a field
        // the admin already typed by hand.
        foreach ($detected as $key => $value) {
            $formValues[$key] = $value;
        }

        $missing = array_diff(['db_host', 'db_name', 'db_user', 'table_prefix'], array_keys($detected));

        $detectResult = ['ok' => true, 'message' => $missing === []
            ? 'Detected database connection details from config.php.'
            : 'Detected some database connection details from config.php — enter the rest (' . implode(', ', $missing) . ') by hand.'];

        // base_url isn't a config.php constant at all — Lumora Gallery
        // stores it in its own database, so this only has a chance once
        // enough of the connection was actually detected above to try
        // it (see GallerySettingsService::detectBaseUrl()'s own
        // docblock).
        if ($missing === [] || !in_array('table_prefix', $missing, true)) {
            $detectedBaseUrl = $service->detectBaseUrl([
                'db_host' => $formValues['db_host'],
                'db_port' => (int) $formValues['db_port'],
                'db_name' => $formValues['db_name'],
                'db_user' => $formValues['db_user'],
                'db_password' => $formValues['db_password'],
                'db_prefix' => $formValues['table_prefix'] !== '' ? $formValues['table_prefix'] : 'lum_',
            ]);

            if ($detectedBaseUrl !== null) {
                $formValues['base_url'] = $detectedBaseUrl;
                $detectResult['message'] .= ' Also detected the Gallery site\'s base URL from its own database.';
            } else {
                $detectResult['message'] .= ' Could not connect to detect the Gallery site\'s base URL — enter it manually.';
            }
        }
    } catch (\Throwable $exception) {
        $detectResult = ['ok' => false, 'message' => 'Could not read config.php: ' . $exception->getMessage()];
    }
}

/*
 * A masked "••••••••" placeholder only stands in for the password field
 * when $formValues still matches the persisted, already-saved password
 * (a normal page load, or a "Save & Test" round trip that just re-read
 * $settings back) — see the save handler above for the matching
 * "posted-back placeholder means keep the saved value" logic this has
 * to stay in sync with. A "detect from config.php" pass can put a real,
 * different, not-yet-saved plaintext password into $formValues, and
 * that must render as itself (same as the WordPress Importer's own
 * plain, unmasked db_password field), or Save would silently discard it
 * — hitting the placeholder-preserves-saved-value branch instead of
 * actually persisting what was just detected.
 */
$showPasswordPlaceholder = $settings['db_password'] !== '' && $formValues['db_password'] === $settings['db_password'];
?>
<h1 class="lp-admin__title">Lumora Gallery Shortcodes</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($testResult !== null): ?>
    <div class="lp-alert <?= $testResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($testResult['message']) ?></div>
<?php endif; ?>

<?php if ($detectResult !== null): ?>
    <div class="lp-alert <?= $detectResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($detectResult['message']) ?></div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Gallery Database Connection</h2>
    <p class="lp-field__hint">
        Lumora Gallery has no API of its own, so this plugin reads its
        database directly, read-only — a separate, optional connection
        that requires nothing from the Gallery installation itself.
        Leave this unconfigured and the
        <code>[lumora_gallery_album]</code>/<code>[lumora_gallery_newest]</code>
        shortcodes simply render nothing.
    </p>

    <h3>Auto-detect from config.php</h3>

    <p class="lp-field__hint">
        If the Gallery site's own <code>config.php</code> is readable on
        this server's local filesystem, point this at it to pre-fill the
        database connection fields below — and, when the connection it
        describes actually works, the Gallery site's base URL too (read
        from the Gallery's own database, since <code>config.php</code>
        doesn't store that). Nothing is read from <code>config.php</code>
        beyond its <code>DB_*</code> values — it is never executed.
        Every pre-filled field below stays fully editable; use this as a
        shortcut, not a requirement.
    </p>

    <form method="post" action="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings')) ?>">
        <?= Csrf::field('detect_gallery_config') ?>
        <input type="hidden" name="form" value="detect_gallery_config">

        <p class="lp-field">
            <label for="gallery-config-path">Path to config.php</label>
            <input type="text" id="gallery-config-path" name="gallery_config_path" value="<?= esc_attr($formValues['gallery_config_path']) ?>" placeholder="/path/to/lumoragallery/config.php">
        </p>

        <?php
        // This form only ever collects gallery_config_path — every
        // other field belongs to the connection form below it; carried
        // forward as hidden inputs so submitting Detect doesn't blank
        // out anything already typed there, mirroring the WordPress
        // Importer's own identical two-forms-on-one-page pattern
        // (admin/views/maintenance/import.php).
        ?>
        <input type="hidden" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>">
        <input type="hidden" name="db_port" value="<?= esc_attr((string) $formValues['db_port']) ?>">
        <input type="hidden" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>">
        <input type="hidden" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>">
        <input type="hidden" name="db_password" value="<?= esc_attr($formValues['db_password']) ?>">
        <input type="hidden" name="table_prefix" value="<?= esc_attr($formValues['table_prefix']) ?>">
        <input type="hidden" name="base_url" value="<?= esc_attr($formValues['base_url']) ?>">

        <button type="submit" class="lp-button lp-button--secondary">Detect from config.php</button>
    </form>

    <h3>Connection</h3>

    <form method="post" action="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings')) ?>">
        <?= Csrf::field('lumora_gallery_shortcodes_settings') ?>
        <input type="hidden" name="form" value="lumora_gallery_shortcodes_settings">
        <input type="hidden" name="gallery_config_path" value="<?= esc_attr($formValues['gallery_config_path']) ?>">

        <p class="lp-field">
            <label for="db-host">Database host</label>
            <input type="text" id="db-host" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>" placeholder="127.0.0.1">
        </p>

        <p class="lp-field">
            <label for="db-port">Database port</label>
            <input type="number" id="db-port" name="db_port" min="1" value="<?= esc_attr((string) $formValues['db_port']) ?>">
        </p>

        <p class="lp-field">
            <label for="db-name">Database name</label>
            <input type="text" id="db-name" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>">
        </p>

        <p class="lp-field">
            <label for="db-user">Database username</label>
            <input type="text" id="db-user" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>" autocomplete="off">
        </p>

        <p class="lp-field">
            <label for="db-password">Database password</label>
            <input type="password" id="db-password" name="db_password" value="<?= $showPasswordPlaceholder ? '••••••••' : esc_attr($formValues['db_password']) ?>" autocomplete="new-password">
            <span class="lp-field__hint">Left showing dots means the currently saved password is kept unless you type a new one.</span>
        </p>

        <p class="lp-field">
            <label for="table-prefix">Table prefix</label>
            <input type="text" id="table-prefix" name="table_prefix" value="<?= esc_attr($formValues['table_prefix']) ?>" placeholder="lum_">
        </p>

        <p class="lp-field">
            <label for="base-url">Gallery site base URL</label>
            <input type="url" id="base-url" name="base_url" value="<?= esc_attr($formValues['base_url']) ?>" placeholder="https://gallery.example.com">
            <span class="lp-field__hint">Used to build "View album" links and the thumbnail/full-image URLs — this plugin has no access to the Gallery site's own URL-building code.</span>
        </p>

        <button type="submit" name="action" value="save" class="lp-button lp-button--primary">Save Settings</button>
        <button type="submit" name="action" value="test" class="lp-button lp-button--secondary">Save &amp; Test Connection</button>
    </form>
</section>
