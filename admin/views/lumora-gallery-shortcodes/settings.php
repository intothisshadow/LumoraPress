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
 * Only reachable while the plugin is active, so GallerySettingsService's
 * class is guaranteed to already be loaded.
 */
$service = new GallerySettingsService();
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$testResult = null;
$detectResult = null;
$error = null;

// "" (add) or an existing connection's slug (edit) — carried through every
// form on the add/edit screen via a hidden `context_slug` field, since a
// "Save & Test Connection"/"Detect from config.php" submission needs to
// stay on that same connection's form rather than bouncing back to the list.
$contextSlug = is_string($_POST['context_slug'] ?? null) ? $_POST['context_slug'] : (is_string($_GET['edit'] ?? null) ? $_GET['edit'] : '');
$isEditingExisting = $contextSlug !== '' && $service->connection($contextSlug) !== null;
$showForm = $isEditingExisting || isset($_GET['add']) || in_array($form, ['lumora_gallery_shortcodes_connection', 'detect_gallery_config'], true);

$existingConnection = $isEditingExisting ? $service->connection($contextSlug) : null;

$formValues = [
    'label' => $existingConnection['label'] ?? '',
    'db_host' => $existingConnection['db_host'] ?? '',
    'db_port' => $existingConnection['db_port'] ?? 3306,
    'db_name' => $existingConnection['db_name'] ?? '',
    'db_user' => $existingConnection['db_user'] ?? '',
    'db_password' => $existingConnection['db_password'] ?? '',
    'table_prefix' => $existingConnection['table_prefix'] ?? 'lum_',
    'base_url' => $existingConnection['base_url'] ?? '',
    'gallery_config_path' => is_string($_POST['gallery_config_path'] ?? null) ? $_POST['gallery_config_path'] : '',
];

if ($form === 'lumora_gallery_shortcodes_connection' && Csrf::verify('lumora_gallery_shortcodes_connection', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $label = trim((string) ($_POST['label'] ?? ''));
    $submitted = [
        'label' => $label,
        'db_host' => trim((string) ($_POST['db_host'] ?? '')),
        'db_port' => max(1, (int) ($_POST['db_port'] ?? 3306)),
        'db_name' => trim((string) ($_POST['db_name'] ?? '')),
        'db_user' => trim((string) ($_POST['db_user'] ?? '')),
        // An unchanged, masked password field posts back the placeholder
        // rather than the real value (see the <input> below) — keep the
        // already-saved password in that case instead of overwriting it
        // with the placeholder text itself.
        'db_password' => ($_POST['db_password'] ?? '') === '••••••••' && $existingConnection !== null
            ? $existingConnection['db_password']
            : (string) ($_POST['db_password'] ?? ''),
        'table_prefix' => trim((string) ($_POST['table_prefix'] ?? '')) !== '' ? trim((string) $_POST['table_prefix']) : 'lum_',
        'base_url' => rtrim(trim((string) ($_POST['base_url'] ?? '')), '/'),
    ];

    if ($label === '') {
        $error = 'Give this connection a label before saving.';
        $showForm = true;
        $formValues = [...$formValues, ...$submitted];
    } else {
        $slug = $isEditingExisting ? $contextSlug : GallerySettingsService::slugify($label, $service->connections());
        $service->saveConnection($slug, $submitted);

        if (($_POST['action'] ?? '') === 'test') {
            $testResult = $service->testConnection($slug)
                ? ['ok' => true, 'message' => 'Connected successfully — the albums/images tables were found.']
                : ['ok' => false, 'message' => 'Could not connect, or the albums/images tables were not found. Double-check the host, credentials, database name, and table prefix.'];
            $existingConnection = $service->connection($slug);
            $contextSlug = $slug;
            $isEditingExisting = true;
            $showForm = true;
            $formValues = [...$formValues, ...$existingConnection];
        } else {
            header('Location: ' . admin_url('lumora-gallery-shortcodes/settings') . '?saved=1');
            exit;
        }
    }
} elseif ($form === 'detect_gallery_config' && Csrf::verify('detect_gallery_config', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    try {
        $detected = (new GalleryConfigParser())->parse($formValues['gallery_config_path']);

        // Only overwrite fields config.php actually named — a
        // partially-recognized config.php shouldn't blank out a field
        // the admin already typed by hand.
        foreach ($detected as $key => $value) {
            $formValues[$key] = $value;
        }

        $missing = array_diff(['db_host', 'db_name', 'db_user', 'table_prefix'], array_keys($detected));

        $detectResult = ['ok' => true, 'message' => $missing === []
            ? 'Detected database connection details from config.php.'
            : 'Detected some database connection details from config.php — enter the rest (' . implode(', ', $missing) . ') by hand.'];

        // base_url isn't a config.php constant — Lumora Gallery stores it
        // in its own database, so this only tries once enough of the
        // connection was detected above.
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
} elseif ($form === 'lumora_gallery_shortcodes_delete_connection' && Csrf::verify('lumora_gallery_shortcodes_delete_connection', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $slug = (string) ($_POST['slug'] ?? '');

    if (count($service->connections()) <= 1) {
        $error = 'Can\'t delete the only Gallery connection — add another one first if you want to replace it.';
    } else {
        $service->deleteConnection($slug);
        header('Location: ' . admin_url('lumora-gallery-shortcodes/settings') . '?deleted=1');
        exit;
    }
} elseif ($form === 'lumora_gallery_shortcodes_make_default' && Csrf::verify('lumora_gallery_shortcodes_make_default', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $service->setDefaultConnection((string) ($_POST['slug'] ?? ''));
    header('Location: ' . admin_url('lumora-gallery-shortcodes/settings') . '?default_set=1');
    exit;
}

// The "••••••••" placeholder only stands in when $formValues still
// matches the saved password. A "detect from config.php" pass can put a
// real, not-yet-saved password into $formValues, which must render as
// itself or Save would discard it by hitting the "keep saved value" branch.
$showPasswordPlaceholder = $existingConnection !== null && $existingConnection['db_password'] !== '' && $formValues['db_password'] === $existingConnection['db_password'];
$defaultSlug = $service->defaultConnection();
?>
<h1 class="lp-admin__title">Lumora Gallery Shortcodes</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Connection deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['default_set'])): ?>
    <div class="lp-alert lp-alert--success">Default connection updated.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if ($testResult !== null): ?>
    <div class="lp-alert <?= $testResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($testResult['message']) ?></div>
<?php endif; ?>

<?php if ($detectResult !== null): ?>
    <div class="lp-alert <?= $detectResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($detectResult['message']) ?></div>
<?php endif; ?>

<?php if (!$showForm): ?>
<section class="lp-admin__panel">
    <h2>Gallery Database Connections</h2>
    <p class="lp-field__hint">
        Lumora Gallery has no API of its own, so this plugin reads its
        database directly, read-only. Configure one connection per
        separately-installed Gallery site you want to embed from — a
        shortcode names which one it wants with a
        <code>gallery="..."</code> attribute, or omits it to use whichever
        connection is marked Default below. With no connection configured
        at all, <code>[lumora_gallery_album]</code>/<code>[lumora_gallery_newest]</code>
        simply render nothing.
    </p>

    <?php if ($service->connections() === []): ?>
        <p class="lp-empty-state">No Gallery connections configured yet.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Host / Database</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($service->connections() as $slug => $connection): ?>
                    <?php $connectionLabel = $connection['label'] !== '' ? $connection['label'] : $slug; ?>
                    <tr>
                        <td>
                            <?= esc_html($connectionLabel) ?>
                            <?php if ($slug === $defaultSlug): ?>
                                <span class="lp-status-badge lp-status-badge--success">Default</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $connection['db_host'] !== '' ? esc_html($connection['db_host'] . ' / ' . $connection['db_name']) : '—' ?></td>
                        <td><?= $service->isConfigured($slug) ? 'Configured' : 'Incomplete' ?></td>
                        <td>
                            <a class="lp-button lp-button--secondary" href="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings') . '?edit=' . rawurlencode($slug)) ?>">Edit</a>
                            <?php if ($slug !== $defaultSlug): ?>
                                <form class="lp-admin__inline-form" method="post" action="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings')) ?>">
                                    <?= Csrf::field('lumora_gallery_shortcodes_make_default') ?>
                                    <input type="hidden" name="form" value="lumora_gallery_shortcodes_make_default">
                                    <input type="hidden" name="slug" value="<?= esc_attr($slug) ?>">
                                    <button type="submit" class="lp-button lp-button--secondary">Make Default</button>
                                </form>
                            <?php endif; ?>
                            <?php if (count($service->connections()) > 1): ?>
                                <form class="lp-admin__inline-form" method="post" action="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings')) ?>" data-lp-confirm="<?= esc_attr('Delete the "' . $connectionLabel . '" Gallery connection? Shortcodes using gallery="' . $slug . '" will render nothing afterward.') ?>">
                                    <?= Csrf::field('lumora_gallery_shortcodes_delete_connection') ?>
                                    <input type="hidden" name="form" value="lumora_gallery_shortcodes_delete_connection">
                                    <input type="hidden" name="slug" value="<?= esc_attr($slug) ?>">
                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings') . '?add=1') ?>">Add Connection</a></p>
</section>
<?php else: ?>
<section class="lp-admin__panel">
    <h2><?= $isEditingExisting ? 'Edit Connection' : 'Add Connection' ?></h2>
    <p><a href="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings')) ?>">&larr; Back to connections</a></p>

    <h3>Auto-detect from config.php</h3>

    <p class="lp-field__hint">
        If this Gallery site's own <code>config.php</code> is readable on
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
        <input type="hidden" name="context_slug" value="<?= esc_attr($contextSlug) ?>">

        <p class="lp-field">
            <label for="gallery-config-path">Path to config.php</label>
            <input type="text" id="gallery-config-path" name="gallery_config_path" value="<?= esc_attr($formValues['gallery_config_path']) ?>" placeholder="/path/to/lumoragallery/config.php">
        </p>

        <?php
        // This form only collects gallery_config_path; every other field
        // belongs to the connection form below, carried forward as
        // hidden inputs so submitting Detect doesn't blank them out.
        ?>
        <input type="hidden" name="label" value="<?= esc_attr($formValues['label']) ?>">
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
        <?= Csrf::field('lumora_gallery_shortcodes_connection') ?>
        <input type="hidden" name="form" value="lumora_gallery_shortcodes_connection">
        <input type="hidden" name="context_slug" value="<?= esc_attr($contextSlug) ?>">
        <input type="hidden" name="gallery_config_path" value="<?= esc_attr($formValues['gallery_config_path']) ?>">

        <p class="lp-field">
            <label for="connection-label">Label</label>
            <input type="text" id="connection-label" name="label" value="<?= esc_attr($formValues['label']) ?>" placeholder="e.g. Xena Archive" required>
            <span class="lp-field__hint">Shown in this list and in the Insert Shortcode picker. Doesn't change the <code>gallery="..."</code> slug once this connection has been created.</span>
        </p>

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

        <button type="submit" name="action" value="save" class="lp-button lp-button--primary">Save Connection</button>
        <button type="submit" name="action" value="test" class="lp-button lp-button--secondary">Save &amp; Test Connection</button>
    </form>
</section>
<?php endif; ?>
