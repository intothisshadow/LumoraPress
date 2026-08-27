<?php

/**
 * The admin Contact Forms > Settings screen: optional spam-protection integrations (LPP-003).
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'contact_forms_spam_settings' && Csrf::verify('contact_forms_spam_settings', $token)) {
        $kernel->config->setOption('contact_forms_recaptcha_enabled', ($_POST['contact_forms_recaptcha_enabled'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('contact_forms_recaptcha_site_key', trim((string) ($_POST['contact_forms_recaptcha_site_key'] ?? '')));
        $kernel->config->setOption('contact_forms_recaptcha_secret_key', trim((string) ($_POST['contact_forms_recaptcha_secret_key'] ?? '')));
        $kernel->config->setOption('contact_forms_turnstile_enabled', ($_POST['contact_forms_turnstile_enabled'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('contact_forms_turnstile_site_key', trim((string) ($_POST['contact_forms_turnstile_site_key'] ?? '')));
        $kernel->config->setOption('contact_forms_turnstile_secret_key', trim((string) ($_POST['contact_forms_turnstile_secret_key'] ?? '')));
        $kernel->config->setOption('contact_forms_use_akismet', ($_POST['contact_forms_use_akismet'] ?? '') === '1' ? '1' : '0');

        header('Location: ' . admin_url('contact-forms/settings') . '?saved=1');
        exit;
    }
}

$recaptchaEnabled = $kernel->config->option('contact_forms_recaptcha_enabled', '0') === '1';
$recaptchaSiteKey = (string) $kernel->config->option('contact_forms_recaptcha_site_key', '');
$recaptchaSecretKey = (string) $kernel->config->option('contact_forms_recaptcha_secret_key', '');
$turnstileEnabled = $kernel->config->option('contact_forms_turnstile_enabled', '0') === '1';
$turnstileSiteKey = (string) $kernel->config->option('contact_forms_turnstile_site_key', '');
$turnstileSecretKey = (string) $kernel->config->option('contact_forms_turnstile_secret_key', '');
$useAkismet = $kernel->config->option('contact_forms_use_akismet', '0') === '1';
$akismetConfigured = $kernel->akismet->isEnabled();
?>
<h1 class="lp-admin__title">Contact Forms Settings</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <form method="post" action="<?= esc_url(admin_url('contact-forms/settings')) ?>">
        <?= Csrf::field('contact_forms_spam_settings') ?>
        <input type="hidden" name="form" value="contact_forms_spam_settings">

        <h2>Google reCAPTCHA</h2>
        <p class="lp-field__hint">Off by default. Requires a reCAPTCHA v2 ("I'm not a robot") site and secret key.</p>
        <p class="lp-field lp-field--checkbox">
            <label>
                <input type="checkbox" name="contact_forms_recaptcha_enabled" value="1" <?= $recaptchaEnabled ? 'checked' : '' ?>>
                Enable reCAPTCHA on every contact form
            </label>
        </p>
        <p class="lp-field">
            <label for="contact-forms-recaptcha-site-key">Site key</label>
            <input type="text" id="contact-forms-recaptcha-site-key" name="contact_forms_recaptcha_site_key" value="<?= esc_attr($recaptchaSiteKey) ?>">
        </p>
        <p class="lp-field">
            <label for="contact-forms-recaptcha-secret-key">Secret key</label>
            <input type="text" id="contact-forms-recaptcha-secret-key" name="contact_forms_recaptcha_secret_key" value="<?= esc_attr($recaptchaSecretKey) ?>">
        </p>

        <h2>Cloudflare Turnstile</h2>
        <p class="lp-field__hint">Off by default. Requires a Turnstile site and secret key.</p>
        <p class="lp-field lp-field--checkbox">
            <label>
                <input type="checkbox" name="contact_forms_turnstile_enabled" value="1" <?= $turnstileEnabled ? 'checked' : '' ?>>
                Enable Turnstile on every contact form
            </label>
        </p>
        <p class="lp-field">
            <label for="contact-forms-turnstile-site-key">Site key</label>
            <input type="text" id="contact-forms-turnstile-site-key" name="contact_forms_turnstile_site_key" value="<?= esc_attr($turnstileSiteKey) ?>">
        </p>
        <p class="lp-field">
            <label for="contact-forms-turnstile-secret-key">Secret key</label>
            <input type="text" id="contact-forms-turnstile-secret-key" name="contact_forms_turnstile_secret_key" value="<?= esc_attr($turnstileSecretKey) ?>">
        </p>

        <h2>Akismet</h2>
        <p class="lp-field lp-field--checkbox">
            <label>
                <input type="checkbox" name="contact_forms_use_akismet" value="1" <?= $useAkismet ? 'checked' : '' ?> <?= $akismetConfigured ? '' : 'disabled' ?>>
                Also check contact form submissions with Akismet
            </label>
        </p>
        <?php if ($akismetConfigured): ?>
            <p class="lp-field__hint">Using this site's existing Akismet key.</p>
        <?php else: ?>
            <p class="lp-field__hint">Set up an Akismet key under Settings &rsaquo; Discussion first to enable this.</p>
        <?php endif; ?>

        <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
    </form>
</section>
