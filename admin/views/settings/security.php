<?php
/** @var \LumoraPress\Core\Kernel $kernel */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-025: this page used to be a placeholder reserving the spot LP-042
 * planned for it ("Most of LP-042's planned Security settings ... have no
 * configurable backing yet"). The login-lockout thresholds below are the
 * first of those to get a real settings UI — LoginThrottle itself already
 * existed (LP-007), just with hardcoded values.
 */
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
}

$maxAttempts = (int) $kernel->config->option('login_max_attempts', '5');
$windowMinutes = (int) round(((int) $kernel->config->option('login_window_seconds', '900')) / 60);
$lockoutMinutes = (int) round(((int) $kernel->config->option('login_lockout_seconds', '900')) / 60);
$akismetEnabled = ((string) $kernel->config->option('akismet_enabled', '0')) === '1';
?>
<h1 class="lp-admin__title">Security</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved. New thresholds take effect on the next login attempt.</div>
<?php endif; ?>

<?php if (isset($_GET['akismet_saved'])): ?>
    <div class="lp-alert lp-alert--success">Spam protection settings saved.</div>
<?php endif; ?>

<?php if (($_GET['akismet_verify'] ?? null) === 'valid'): ?>
    <div class="lp-alert lp-alert--success">Akismet API key is valid.</div>
<?php elseif (($_GET['akismet_verify'] ?? null) === 'invalid'): ?>
    <div class="lp-alert lp-alert--error">Akismet API key is invalid, or Akismet could not be reached.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Login Lockout</h2>
    <p class="lp-field__hint">Brute-force protection, keyed by IP address (see <code>LoginThrottle</code>) — a failed login always shows a generic "Invalid username or password" message, never revealing whether the username exists.</p>
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
            <span class="lp-field__hint">Leave blank to keep the current key unchanged.</span>
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
    <h2>User Enumeration</h2>
    <p>
        Lumora Press has no attack surface for the classic WordPress
        user-enumeration vectors, so there is nothing further to configure
        here:
    </p>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>No XML-RPC endpoint (see <code>MEMORY.md</code>).</li>
        <li>The "Forgot password?" flow always shows the same "if that address is registered, we sent a link" response, whether or not the email exists, and is itself IP-rate-limited to blunt large-scale probing.</li>
        <li>No public author-archive route and no REST API endpoint that lists or exposes user accounts.</li>
        <li>The login form already gives a single generic error for both a wrong username and a wrong password.</li>
    </ul>
</section>

<section class="lp-admin__panel">
    <h2>Always On</h2>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>CSRF protection on every administrative form.</li>
        <li>Secure, HttpOnly session cookies.</li>
        <li>Submission timing and honeypot checks on the public comment form, plus optional Akismet spam-checking (Spam Protection panel above).</li>
    </ul>
</section>
