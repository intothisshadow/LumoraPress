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
    } else {
        header('Location: ' . admin_url('lumora-gallery-shortcodes/settings') . '?saved=1');
        exit;
    }
}

$settings = $service->settings();
?>
<h1 class="lp-admin__title">Lumora Gallery Shortcodes</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($testResult !== null): ?>
    <div class="lp-alert <?= $testResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($testResult['message']) ?></div>
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

    <form method="post" action="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings')) ?>">
        <?= Csrf::field('lumora_gallery_shortcodes_settings') ?>
        <input type="hidden" name="form" value="lumora_gallery_shortcodes_settings">

        <p class="lp-field">
            <label for="db-host">Database host</label>
            <input type="text" id="db-host" name="db_host" value="<?= esc_attr($settings['db_host']) ?>" placeholder="127.0.0.1">
        </p>

        <p class="lp-field">
            <label for="db-port">Database port</label>
            <input type="number" id="db-port" name="db_port" min="1" value="<?= esc_attr((string) $settings['db_port']) ?>">
        </p>

        <p class="lp-field">
            <label for="db-name">Database name</label>
            <input type="text" id="db-name" name="db_name" value="<?= esc_attr($settings['db_name']) ?>">
        </p>

        <p class="lp-field">
            <label for="db-user">Database username</label>
            <input type="text" id="db-user" name="db_user" value="<?= esc_attr($settings['db_user']) ?>" autocomplete="off">
        </p>

        <p class="lp-field">
            <label for="db-password">Database password</label>
            <input type="password" id="db-password" name="db_password" value="<?= $settings['db_password'] !== '' ? '••••••••' : '' ?>" autocomplete="new-password">
            <span class="lp-field__hint">Left showing dots means the currently saved password is kept unless you type a new one.</span>
        </p>

        <p class="lp-field">
            <label for="table-prefix">Table prefix</label>
            <input type="text" id="table-prefix" name="table_prefix" value="<?= esc_attr($settings['table_prefix']) ?>" placeholder="lum_">
        </p>

        <p class="lp-field">
            <label for="base-url">Gallery site base URL</label>
            <input type="url" id="base-url" name="base_url" value="<?= esc_attr($settings['base_url']) ?>" placeholder="https://gallery.example.com">
            <span class="lp-field__hint">Used to build "View album" links and the thumbnail/full-image URLs — this plugin has no access to the Gallery site's own URL-building code.</span>
        </p>

        <button type="submit" name="action" value="save" class="lp-button lp-button--primary">Save Settings</button>
        <button type="submit" name="action" value="test" class="lp-button lp-button--secondary">Save &amp; Test Connection</button>
    </form>
</section>
