<?php

/**
 * The admin Appearance > Font Awesome settings screen, provided by the bundled Font Awesome plugin (LPP-002).
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
use LumoraPress\Plugins\FontAwesome\FontAwesomeService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the plugin is active, so FontAwesomeService's
// class is guaranteed to already be loaded.
$service = FontAwesomeService::instance();
$errors = [];
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'font_awesome_settings' && Csrf::verify('font_awesome_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $delivery = ($_POST['delivery'] ?? '') === 'self_hosted' ? 'self_hosted' : 'cdn';
    $selfHostedUrl = trim((string) ($_POST['self_hosted_url'] ?? ''));

    if ($delivery === 'self_hosted' && $selfHostedUrl !== '' && filter_var($selfHostedUrl, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Self-hosted URL';
    } else {
        $service->saveSettings([
            'enabled' => isset($_POST['enabled']),
            'delivery' => $delivery,
            'version' => trim((string) ($_POST['version'] ?? '')) !== '' ? trim((string) $_POST['version']) : FontAwesomeService::DEFAULT_VERSION,
            'self_hosted_url' => $selfHostedUrl,
            'compatibility_mode' => isset($_POST['compatibility_mode']),
        ]);

        header('Location: ' . admin_url('appearance/font-awesome') . '?saved=1');
        exit;
    }
}

$settings = $service->settings();
?>
<h1 class="lp-admin__title">Font Awesome</h1>

<?php if (isset($_GET['saved']) && $errors === []): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="lp-alert lp-alert--error">Couldn't save: <?= esc_html(implode(', ', $errors)) ?> — please check the value(s) and try again.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Settings</h2>
    <p class="lp-field__hint">
        Enables the <code>[icon name="camera"]</code> shortcode and the
        <code>lp_icon()</code> theme helper site-wide. Disabled by default so
        no external request is made until you opt in.
    </p>

    <form method="post" action="<?= esc_url(admin_url('appearance/font-awesome')) ?>">
        <?= Csrf::field('font_awesome_settings') ?>
        <input type="hidden" name="form" value="font_awesome_settings">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
                Enable Font Awesome
            </label>
        </p>

        <p class="lp-field">
            <label for="fa-delivery">Delivery method</label>
            <select id="fa-delivery" name="delivery">
                <option value="cdn" <?= $settings['delivery'] === 'cdn' ? 'selected' : '' ?>>Official CDN (jsDelivr)</option>
                <option value="self_hosted" <?= $settings['delivery'] === 'self_hosted' ? 'selected' : '' ?>>Self-hosted (your own URL)</option>
            </select>
            <span class="lp-field__hint">Font Awesome Kit and Pro support are not available yet — see the plugin's README.</span>
        </p>

        <p class="lp-field">
            <label for="fa-version">Version</label>
            <input type="text" id="fa-version" name="version" value="<?= esc_attr($settings['version']) ?>" placeholder="<?= esc_attr(FontAwesomeService::DEFAULT_VERSION) ?>">
            <span class="lp-field__hint">A Font Awesome Free release number, used to build the CDN URL. Ignored for self-hosted delivery.</span>
        </p>

        <p class="lp-field">
            <label for="fa-self-hosted-url">Self-hosted CSS URL</label>
            <input type="url" id="fa-self-hosted-url" name="self_hosted_url" value="<?= esc_attr($settings['self_hosted_url']) ?>" placeholder="https://example.com/assets/fontawesome/css/all.min.css">
            <span class="lp-field__hint">Only used when Delivery method is Self-hosted. Its origin is added to the Content-Security-Policy automatically.</span>
        </p>

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="compatibility_mode" value="1" <?= $settings['compatibility_mode'] ? 'checked' : '' ?>>
                Compatibility mode (also load the v4-shims stylesheet, for old <code>fa fa-camera</code>-style class names)
            </label>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
    </form>
</section>

<?php $conflicts = $service->detectConflicts(); ?>
<section class="lp-admin__panel">
    <h2>Diagnostics</h2>
    <ul class="lp-admin__meta-list">
        <li><span>Active Version</span><span><?= esc_html($settings['delivery'] === 'self_hosted' ? 'Self-hosted (version not tracked)' : $settings['version']) ?></span></li>
        <li><span>Source</span><span><?= $settings['enabled'] ? 'Core &mdash; Font Awesome plugin' : 'Not loaded (disabled)' ?></span></li>
        <li><span>Delivery</span><span><?= $settings['delivery'] === 'self_hosted' ? 'Self-hosted' : 'Official CDN (jsDelivr)' ?></span></li>
    </ul>

    <?php if ($conflicts === []): ?>
        <p class="lp-field__hint">No other Font Awesome references detected in the active theme or other active plugins.</p>
    <?php else: ?>
        <div class="lp-alert lp-alert--error">
            <p>Possible duplicate Font Awesome loading detected — the active theme or another active plugin appears to reference Font Awesome on its own, independently of this plugin:</p>
            <ul>
                <?php foreach ($conflicts as $conflict): ?>
                    <li><?= esc_html($conflict['source']) ?> (<?= esc_html($conflict['file']) ?>)</li>
                <?php endforeach; ?>
            </ul>
            <p>Remove the theme's/plugin's own reference to avoid loading Font Awesome twice.</p>
        </div>
    <?php endif; ?>
</section>
