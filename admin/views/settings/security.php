<?php

/**
 * The admin Settings > Security screen: login throttling and related security options.
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

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\TrustedImageOrigins;
use LumoraPress\Plugins\LumoraShield\LumoraShieldService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'security_settings' && Csrf::verify('security_settings', $token)) {
        $kernel->config->setOption('login_max_attempts', (string) max(1, (int) ($_POST['login_max_attempts'] ?? 5)));
        $kernel->config->setOption('login_window_seconds', (string) (max(1, (int) ($_POST['login_window_minutes'] ?? 15)) * 60));
        $kernel->config->setOption('login_lockout_seconds', (string) (max(1, (int) ($_POST['login_lockout_minutes'] ?? 15)) * 60));

        header('Location: ' . admin_url('settings/security') . '?saved=1');
        exit;
    }

    if ($form === 'akismet_settings' && Csrf::verify('akismet_settings', $token)) {
        $kernel->config->setOption('akismet_enabled', ($_POST['akismet_enabled'] ?? '') === '1' ? '1' : '0');

        $submittedKey = trim((string) ($_POST['akismet_api_key'] ?? ''));

        if ($submittedKey !== '') {
            $kernel->config->setOption('akismet_api_key', $submittedKey);
        }

        header('Location: ' . admin_url('settings/security') . '?akismet_saved=1');
        exit;
    }

    if ($form === 'akismet_verify' && Csrf::verify('akismet_verify', $token)) {
        header('Location: ' . admin_url('settings/security') . '?akismet_verify=' . ($kernel->akismet->verifyKey() ? 'valid' : 'invalid'));
        exit;
    }

    if ($form === 'trusted_image_origins' && Csrf::verify('trusted_image_origins', $token)) {
        // Invalid lines are dropped here rather than saved and quietly
        // ignored at header-build time, so this screen always matches
        // what's actually allowed.
        $validOrigins = TrustedImageOrigins::parse((string) ($_POST['trusted_image_origins'] ?? ''));

        $kernel->config->setOption('trusted_image_origins', implode("\n", $validOrigins));

        header('Location: ' . admin_url('settings/security') . '?trusted_image_origins_saved=1');
        exit;
    }
}

$maxAttempts = (int) $kernel->config->option('login_max_attempts', '5');
$windowMinutes = (int) round(((int) $kernel->config->option('login_window_seconds', '900')) / 60);
$lockoutMinutes = (int) round(((int) $kernel->config->option('login_lockout_seconds', '900')) / 60);
$akismetEnabled = ((string) $kernel->config->option('akismet_enabled', '0')) === '1';
$akismetKeyConfigured = trim((string) $kernel->config->option('akismet_api_key', '')) !== '';
$trustedImageOrigins = (string) $kernel->config->option('trusted_image_origins', '');
?>
<h1 class="lp-admin__title">Security</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved. New thresholds take effect on the next login attempt.</div>
<?php endif; ?>

<?php if (isset($_GET['akismet_saved'])): ?>
    <div class="lp-alert lp-alert--success">Spam protection settings saved.</div>
<?php endif; ?>

<?php if (isset($_GET['trusted_image_origins_saved'])): ?>
    <div class="lp-alert lp-alert--success">Trusted image sources saved.</div>
<?php endif; ?>

<?php if (($_GET['akismet_verify'] ?? null) === 'valid'): ?>
    <div class="lp-alert lp-alert--success">Akismet API key is valid.</div>
<?php elseif (($_GET['akismet_verify'] ?? null) === 'invalid'): ?>
    <div class="lp-alert lp-alert--error">Akismet API key is invalid, or Akismet could not be reached.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Login Lockout</h2>
    <p class="lp-field__hint">Brute-force protection, keyed by IP address — a failed login always shows a generic "Invalid username or password" message, never revealing whether the username exists.</p>
    <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>">
        <?= Csrf::field('security_settings') ?>
        <input type="hidden" name="form" value="security_settings">

        <p class="lp-field">
            <label for="login-max-attempts">Failed attempts before lockout</label>
            <input type="number" id="login-max-attempts" name="login_max_attempts" min="1" value="<?= esc_attr((string) $maxAttempts) ?>">
        </p>

        <p class="lp-field">
            <label for="login-window-minutes">Attempt window (minutes)</label>
            <input type="number" id="login-window-minutes" name="login_window_minutes" min="1" value="<?= esc_attr((string) $windowMinutes) ?>">
            <span class="lp-field__hint">Failed attempts older than this are no longer counted toward the threshold above.</span>
        </p>

        <p class="lp-field">
            <label for="login-lockout-minutes">Lockout duration (minutes)</label>
            <input type="number" id="login-lockout-minutes" name="login_lockout_minutes" min="1" value="<?= esc_attr((string) $lockoutMinutes) ?>">
            <span class="lp-field__hint">How long an IP address is blocked from attempting another login once locked out.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Spam Protection (Akismet)</h2>
    <p class="lp-field__hint">Optional. Comments work exactly as before if this is left off — no Akismet account is ever required.</p>
    <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>">
        <?= Csrf::field('akismet_settings') ?>
        <input type="hidden" name="form" value="akismet_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="akismet_enabled" value="1" <?= $akismetEnabled ? 'checked' : '' ?>>
            Check new comments with Akismet
        </label>

        <p class="lp-field">
            <label for="akismet-api-key">API key</label>
            <input type="password" id="akismet-api-key" name="akismet_api_key" placeholder="Not set" autocomplete="off">
            <span class="lp-field__hint">
                <?php if ($akismetKeyConfigured): ?>
                    A key is currently configured — this field always shows blank for security, not because the key is missing. Leave it blank to keep that key unchanged, or type a new one to replace it.
                <?php else: ?>
                    No key is currently configured. Leave blank to save without one, or type your Akismet API key.
                <?php endif; ?>
            </span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
    <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>" class="lp-admin__inline-form">
        <?= Csrf::field('akismet_verify') ?>
        <input type="hidden" name="form" value="akismet_verify">
        <button type="submit" class="lp-button">Verify Key</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Trusted Image Sources</h2>
    <p class="lp-field__hint">
        Optional. Content that embeds an <code>&lt;img&gt;</code> pointing
        at an external host — a personal gallery or CDN you run yourself,
        for example — won't display by default: the site's Content
        Security Policy only allows images from this site's own domain,
        and a blocked image fails silently in the visitor's browser with
        nothing in any server log to explain why. List any external
        origins you trust below, one per line
        (<code>https://gallery.example.com</code>), to allow images from
        them through.
    </p>
    <p class="lp-field__hint">
        An Administrator or Editor saving a post or page never needs to
        do this by hand for their own content — any external image
        origin their saved content actually uses is added here
        automatically. This list is where those origins land, and you
        can remove one at any time.
    </p>
    <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>">
        <?= Csrf::field('trusted_image_origins') ?>
        <input type="hidden" name="form" value="trusted_image_origins">

        <p class="lp-field">
            <label for="trusted-image-origins">Trusted origins</label>
            <textarea id="trusted-image-origins" name="trusted_image_origins" rows="4" placeholder="https://gallery.example.com"><?= esc_html($trustedImageOrigins) ?></textarea>
            <span class="lp-field__hint">One per line. Scheme and host only — no path (<code>https://gallery.example.com</code>, not <code>https://gallery.example.com/albums/</code>). A line that doesn't match this is dropped when saved.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<?php $lumoraShieldActive = class_exists(LumoraShieldService::class, false); ?>
<section class="lp-admin__panel">
    <h2>User Enumeration</h2>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>No XML-RPC endpoint.</li>
        <li>The "Forgot password?" flow always shows the same "if that address is registered, we sent a link" response, whether or not the email exists, and is itself IP-rate-limited to blunt large-scale probing.</li>
        <li>No REST API endpoint that lists or exposes user accounts.</li>
        <li>The login form already gives a single generic error for both a wrong username and a wrong password.</li>
        <li>The public author archive (<code>/author/{slug}</code>) 404s an author with zero published posts exactly like a nonexistent username.</li>
    </ul>
    <?php if ($lumoraShieldActive): ?>
        <p class="lp-field__hint">
            The Lumora Shield plugin adds an optional stronger setting —
            see <a href="<?= esc_url(admin_url('lumora-shield/settings')) ?>">Lumora Shield &rsaquo; Settings</a>.
        </p>
    <?php endif; ?>
</section>

<section class="lp-admin__panel">
    <h2>Always On</h2>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>CSRF protection on every administrative form.</li>
        <li>Secure, HttpOnly session cookies.</li>
        <li>Submission timing and honeypot checks on the public comment form, plus optional Akismet spam-checking (Spam Protection panel above).</li>
    </ul>
</section>
