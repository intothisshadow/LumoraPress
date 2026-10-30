<?php

/**
 * The admin Settings > Security screen: login throttling, spam protection, and (while active) Lumora Shield's own settings tab.
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
use LumoraPress\Core\Security\PasswordPolicy;
use LumoraPress\Core\Security\TrustedImageOrigins;
use LumoraPress\Core\Security\IpRangeMatcher;
use LumoraPress\Plugins\LumoraShield\LumoraShieldService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// The class is only loaded while the plugin is active, so this doubles as
// the "is Lumora Shield active" check the same way settings.php/logs.php
// used before their move here.
$lumoraShieldActive = class_exists(LumoraShieldService::class, false);

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

    if ($form === 'password_policy' && Csrf::verify('password_policy', $token)) {
        $kernel->config->setOption('password_min_length', (string) min(PasswordPolicy::CEILING_MIN_LENGTH, max(PasswordPolicy::FLOOR_MIN_LENGTH, (int) ($_POST['password_min_length'] ?? PasswordPolicy::DEFAULT_MIN_LENGTH))));

        header('Location: ' . admin_url('settings/security') . '?policy_saved=1');
        exit;
    }

    if ($form === 'session_settings' && Csrf::verify('session_settings', $token)) {
        $kernel->config->setOption('session_idle_minutes', (string) min(43200, max(0, (int) ($_POST['session_idle_minutes'] ?? 0))));
        $kernel->config->setOption('remember_me_days', (string) min(365, max(1, (int) ($_POST['remember_me_days'] ?? 30))));

        header('Location: ' . admin_url('settings/security') . '?policy_saved=1');
        exit;
    }

    if ($form === 'security_headers' && Csrf::verify('security_headers', $token)) {
        $kernel->config->setOption('hsts_enabled', ($_POST['hsts_enabled'] ?? '') === '1' ? '1' : '0');

        header('Location: ' . admin_url('settings/security') . '?policy_saved=1');
        exit;
    }

    if ($form === 'comment_rate_settings' && Csrf::verify('comment_rate_settings', $token)) {
        $kernel->config->setOption('comment_max_per_hour', (string) min(1000, max(0, (int) ($_POST['comment_max_per_hour'] ?? 20))));
        $kernel->config->setOption('comment_form_max_age_hours', (string) min(720, max(0, (int) ($_POST['comment_form_max_age_hours'] ?? 0))));

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

    // Five separate forms share this one handler — each needs its own CSRF
    // action name (csrf_action tells verify() which one to check against),
    // since Csrf::field() overwrites the session's token per action name
    // and all four forms rendering on one page would otherwise leave only
    // the last-rendered form's token actually valid.
    $shieldCsrfActions = [
        'lumora_shield_settings_enumeration',
        'lumora_shield_settings_monitoring',
        'lumora_shield_settings_comment_analysis',
        'lumora_shield_settings_contact_form',
        'lumora_shield_settings_lists',
    ];
    $shieldCsrfAction = is_string($_POST['csrf_action'] ?? null) ? $_POST['csrf_action'] : '';

    if ($lumoraShieldActive && $form === 'lumora_shield_settings' && in_array($shieldCsrfAction, $shieldCsrfActions, true) && Csrf::verify($shieldCsrfAction, $token)) {
        $shieldService = LumoraShieldService::instance();
        // Each form posts only its own fields (the older ones repeat the rest as hidden
        // inputs), so everything is merged over what is stored rather than replacing it.
        $shieldCurrent = $shieldService->settings();
        $shieldDropped = 0;

        if ($shieldCsrfAction === 'lumora_shield_settings_lists') {
            $keepLines = static function (string $raw, callable $isValid) use (&$shieldDropped): string {
                $kept = [];

                foreach (IpRangeMatcher::parseList($raw) as $line) {
                    if ($isValid($line)) {
                        $kept[] = $line;
                    } else {
                        $shieldDropped++;
                    }
                }

                return implode("\n", $kept);
            };

            $shieldService->saveSettings([
                ...$shieldCurrent,
                'trusted_ips' => $keepLines((string) ($_POST['trusted_ips'] ?? ''), static fn (string $rule): bool => IpRangeMatcher::isValidRule($rule)),
                'trusted_emails' => $keepLines((string) ($_POST['trusted_emails'] ?? ''), static fn (string $entry): bool => filter_var($entry, FILTER_VALIDATE_EMAIL) !== false || preg_match('/^@?[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $entry) === 1),
                'block_disposable_email' => isset($_POST['block_disposable_email']),
                'spam_username_keywords' => $keepLines((string) ($_POST['spam_username_keywords'] ?? ''), static fn (string $word): bool => mb_strlen($word) >= 3 && mb_strlen($word) <= 60),
            ]);
        } else {
            $shieldService->saveSettings([
                ...$shieldCurrent,
                'hide_author_archives' => isset($_POST['hide_author_archives']),
                'enable_logging' => isset($_POST['enable_logging']),
                'log_retention_days' => max(1, (int) ($_POST['log_retention_days'] ?? 30)),
                'notify_on_repeated_attempts' => isset($_POST['notify_on_repeated_attempts']),
                'notify_threshold' => max(1, (int) ($_POST['notify_threshold'] ?? 10)),
                'enable_comment_analysis' => isset($_POST['enable_comment_analysis']),
                'enable_contact_form_analysis' => isset($_POST['enable_contact_form_analysis']),
            ]);
        }

        header('Location: ' . admin_url('settings/security') . '?tab=lumora-shield&shield_saved=1' . ($shieldDropped > 0 ? '&shield_dropped=' . $shieldDropped : ''));
        exit;
    }
}

$passwordMinLength = (new PasswordPolicy($kernel->config))->minLength();
$sessionIdleMinutes = max(0, (int) $kernel->config->option('session_idle_minutes', '0'));
$rememberMeDays = min(365, max(1, (int) $kernel->config->option('remember_me_days', '30')));
$hstsEnabled = ((string) $kernel->config->option('hsts_enabled', '0')) === '1';
$siteIsHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$maxAttempts = (int) $kernel->config->option('login_max_attempts', '5');
$windowMinutes = (int) round(((int) $kernel->config->option('login_window_seconds', '900')) / 60);
$lockoutMinutes = (int) round(((int) $kernel->config->option('login_lockout_seconds', '900')) / 60);
$akismetEnabled = ((string) $kernel->config->option('akismet_enabled', '0')) === '1';
$commentMaxPerHour = max(0, (int) $kernel->config->option('comment_max_per_hour', '20'));
$commentFormMaxAgeHours = max(0, (int) $kernel->config->option('comment_form_max_age_hours', '0'));
$akismetKeyConfigured = trim((string) $kernel->config->option('akismet_api_key', '')) !== '';
$trustedImageOrigins = (string) $kernel->config->option('trusted_image_origins', '');
$shieldSettings = $lumoraShieldActive ? LumoraShieldService::instance()->settings() : null;

$tabs = ['general' => 'General'];

if ($lumoraShieldActive) {
    $tabs['lumora-shield'] = 'Lumora Shield';
}

$activeTab = in_array($_GET['tab'] ?? '', array_keys($tabs), true) ? $_GET['tab'] : 'general';
?>
<h1 class="lp-admin__title">Security</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved. New thresholds take effect on the next login attempt.</div>
<?php endif; ?>

<?php if (isset($_GET['policy_saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
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

<?php if (isset($_GET['shield_saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ((int) ($_GET['shield_dropped'] ?? 0) > 0): ?>
    <div class="lp-alert lp-alert--warning"><?= (int) $_GET['shield_dropped'] ?> line(s) were not valid and were left out.</div>
<?php endif; ?>

<div class="lp-tabs">
    <div class="lp-tabs__list" role="tablist" aria-label="Security section">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <button type="button" class="lp-tabs__tab" id="lp-tab-<?= esc_attr($tabKey) ?>" role="tab" aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-<?= esc_attr($tabKey) ?>" tabindex="<?= $activeTab === $tabKey ? '0' : '-1' ?>"><?= esc_html($tabLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-general" role="tabpanel" aria-labelledby="lp-tab-general"<?= $activeTab === 'general' ? '' : ' hidden' ?>>
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
            <h2>Passwords</h2>
            <p class="lp-field__hint">One minimum length applies everywhere a password is chosen: adding or editing a user, signing up, and resetting a forgotten password. It only affects passwords set from now on. Longer is better than complicated, so there are no rules about symbols or capitals.</p>
            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>">
                <?= Csrf::field('password_policy') ?>
                <input type="hidden" name="form" value="password_policy">

                <p class="lp-field">
                    <label for="password-min-length">Minimum password length</label>
                    <input type="number" id="password-min-length" name="password_min_length" min="<?= (int) PasswordPolicy::FLOOR_MIN_LENGTH ?>" max="<?= (int) PasswordPolicy::CEILING_MIN_LENGTH ?>" value="<?= esc_attr((string) $passwordMinLength) ?>">
                    <span class="lp-field__hint">Between <?= (int) PasswordPolicy::FLOOR_MIN_LENGTH ?> and <?= (int) PasswordPolicy::CEILING_MIN_LENGTH ?> characters. The default is <?= (int) PasswordPolicy::DEFAULT_MIN_LENGTH ?>.</span>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Sign-in Sessions</h2>
            <p class="lp-field__hint">How long a person stays signed in. Changes apply to sessions that start from now on.</p>
            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>">
                <?= Csrf::field('session_settings') ?>
                <input type="hidden" name="form" value="session_settings">

                <p class="lp-field">
                    <label for="session-idle-minutes">Sign out after this many idle minutes</label>
                    <input type="number" id="session-idle-minutes" name="session_idle_minutes" min="0" max="43200" value="<?= esc_attr((string) $sessionIdleMinutes) ?>">
                    <span class="lp-field__hint">0 uses your web host's own setting, which is often only 24 minutes. Raise it if you are signed out in the middle of writing. People who tick "Remember Me" stay signed in for the number of days below regardless.</span>
                </p>

                <p class="lp-field">
                    <label for="remember-me-days">"Remember Me" lasts (days)</label>
                    <input type="number" id="remember-me-days" name="remember_me_days" min="1" max="365" value="<?= esc_attr((string) $rememberMeDays) ?>">
                    <span class="lp-field__hint">Applies to the next time someone signs in with "Remember Me" ticked; devices that are already remembered keep their original length.</span>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Browser Security Headers</h2>
            <p class="lp-field__hint">Sent on every page, with nothing to configure: <code>X-Content-Type-Options</code> (stops browsers guessing file types), <code>X-Frame-Options</code> (other sites cannot put yours in a frame), <code>Referrer-Policy</code> (other sites see only your domain, not the full address), and <code>Permissions-Policy</code> (the camera, microphone and location are switched off for your pages). The Content-Security-Policy is separate.</p>
            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>">
                <?= Csrf::field('security_headers') ?>
                <input type="hidden" name="form" value="security_headers">

                <label class="lp-field--checkbox">
                    <input type="checkbox" name="hsts_enabled" value="1" <?= $hstsEnabled ? 'checked' : '' ?>>
                    Tell browsers to only ever use HTTPS for this site (HSTS)
                </label>
                <span class="lp-field__hint">Leave this off until your site works properly over HTTPS. Once a browser sees it, it refuses plain HTTP for this site for a year, so if HTTPS later breaks, visitors who have already been here cannot reach the site until it is fixed. It is only sent while the site is being viewed over HTTPS<?= $siteIsHttps ? '' : ', and this page is currently loaded over plain HTTP, so it has no effect yet' ?>.</span>

                <button type="submit" class="lp-button lp-button--primary">Save</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Comment Flood Protection</h2>
            <p class="lp-field__hint">Two comments from the same IP address within 30 seconds are always blocked. This adds a longer-term cap that stops spam trickling in slowly.</p>
            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>">
                <?= Csrf::field('comment_rate_settings') ?>
                <input type="hidden" name="form" value="comment_rate_settings">

                <p class="lp-field">
                    <label for="comment-max-per-hour">Maximum comments per IP address per hour</label>
                    <input type="number" id="comment-max-per-hour" name="comment_max_per_hour" min="0" max="1000" value="<?= esc_attr((string) $commentMaxPerHour) ?>">
                    <span class="lp-field__hint">Set to 0 for no hourly limit. If comment IP anonymization is on, visitors who share an anonymized address also share this limit.</span>
                </p>

                <p class="lp-field">
                    <label for="comment-form-max-age-hours">Comment form lifetime (hours)</label>
                    <input type="number" id="comment-form-max-age-hours" name="comment_form_max_age_hours" min="0" max="720" value="<?= esc_attr((string) $commentFormMaxAgeHours) ?>">
                    <span class="lp-field__hint">A comment form left open longer than this is rejected, and the visitor is asked to reload the page. Set to 0 for no limit. If your site caches pages for longer than this, leave it at 0 so a cached form doesn't expire before anyone can use it.</span>
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
                automatically. So are the image sources in a README imported
                from GitHub. This list is where those origins land, and you
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

        <section class="lp-admin__panel">
            <h2>User Enumeration</h2>
            <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
                <li>No XML-RPC endpoint.</li>
                <li>The "Forgot password?" flow always shows the same "if that address is registered, we sent a link" response, whether or not the email exists, and is itself IP-rate-limited to blunt large-scale probing.</li>
                <li>There is no REST API, so no endpoint lists or exposes user accounts.</li>
                <li>The login form already gives a single generic error for both a wrong username and a wrong password.</li>
                <li>The public author archive (<code>/author/{slug}</code>) 404s an author with zero published posts exactly like a nonexistent author.</li>
            </ul>
            <?php if ($lumoraShieldActive): ?>
                <p class="lp-field__hint">
                    The Lumora Shield plugin adds an optional stronger setting —
                    see the <a href="<?= esc_url(admin_url('settings/security') . '?tab=lumora-shield#stop-user-enumeration') ?>">Lumora Shield tab</a> above.
                </p>
            <?php endif; ?>
        </section>

        <section class="lp-admin__panel">
            <h2>Always On</h2>
            <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
                <li>CSRF protection on every administrative form.</li>
                <li>HttpOnly, SameSite session cookies, marked Secure when the site is served over HTTPS.</li>
                <li>Submission timing and honeypot checks on the public comment form, plus optional Akismet spam-checking (Spam Protection panel above).</li>
            </ul>
        </section>
    </div>

    <?php if ($lumoraShieldActive): ?>
    <div class="lp-tabs__panel" id="lp-tabpanel-lumora-shield" role="tabpanel" aria-labelledby="lp-tab-lumora-shield"<?= $activeTab === 'lumora-shield' ? '' : ' hidden' ?>>
        <section class="lp-admin__panel" id="stop-user-enumeration">
            <h2>Stop User Enumeration</h2>
            <p class="lp-field__hint">
                The public author archive (<code>/author/{slug}</code>) already
                404s an author with zero published posts exactly like a
                nonexistent username — that fix has no downside, so it's always
                on, no setting needed. The one real choice left is below.
            </p>

            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>?tab=lumora-shield">
                <?= Csrf::field('lumora_shield_settings_enumeration') ?>
                <input type="hidden" name="form" value="lumora_shield_settings">
                <input type="hidden" name="csrf_action" value="lumora_shield_settings_enumeration">
                <input type="hidden" name="enable_logging" value="<?= $shieldSettings['enable_logging'] ? '1' : '' ?>">
                <input type="hidden" name="log_retention_days" value="<?= (int) $shieldSettings['log_retention_days'] ?>">
                <input type="hidden" name="notify_on_repeated_attempts" value="<?= $shieldSettings['notify_on_repeated_attempts'] ? '1' : '' ?>">
                <input type="hidden" name="notify_threshold" value="<?= (int) $shieldSettings['notify_threshold'] ?>">
                <input type="hidden" name="enable_comment_analysis" value="<?= $shieldSettings['enable_comment_analysis'] ? '1' : '' ?>">
                <input type="hidden" name="enable_contact_form_analysis" value="<?= $shieldSettings['enable_contact_form_analysis'] ? '1' : '' ?>">

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="hide_author_archives" value="1" <?= $shieldSettings['hide_author_archives'] ? 'checked' : '' ?>>
                        Hide author archives entirely
                    </label>
                    <span class="lp-field__hint">
                        Every <code>/author/{slug}</code> URL 404s, regardless of
                        how many posts that person has published — including
                        real authors who have actually published something. This
                        removes a public feature (visitors can no longer browse
                        "everything by this author"), so it's off by default;
                        most sites don't need it.
                    </span>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
            </form>
        </section>

        <section class="lp-admin__panel" id="monitoring">
            <h2>Monitoring</h2>
            <p class="lp-field__hint">
                Records every blocked author-archive request (unknown username,
                zero-post author, or hidden by the setting above) — see
                <a href="<?= esc_url(admin_url('maintenance/logs') . '#enumeration-attempts') ?>">Maintenance &rsaquo; Logs</a>.
            </p>

            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>?tab=lumora-shield">
                <?= Csrf::field('lumora_shield_settings_monitoring') ?>
                <input type="hidden" name="form" value="lumora_shield_settings">
                <input type="hidden" name="csrf_action" value="lumora_shield_settings_monitoring">
                <input type="hidden" name="hide_author_archives" value="<?= $shieldSettings['hide_author_archives'] ? '1' : '' ?>">
                <input type="hidden" name="enable_comment_analysis" value="<?= $shieldSettings['enable_comment_analysis'] ? '1' : '' ?>">
                <input type="hidden" name="enable_contact_form_analysis" value="<?= $shieldSettings['enable_contact_form_analysis'] ? '1' : '' ?>">

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="enable_logging" value="1" <?= $shieldSettings['enable_logging'] ? 'checked' : '' ?>>
                        Log blocked enumeration attempts
                    </label>
                </p>

                <p class="lp-field">
                    <label for="log-retention-days">Log retention (days)</label>
                    <input type="number" id="log-retention-days" name="log_retention_days" min="1" value="<?= esc_attr((string) $shieldSettings['log_retention_days']) ?>">
                    <span class="lp-field__hint">Enumeration attempts and spam analysis records older than this are removed automatically.</span>
                </p>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="notify_on_repeated_attempts" value="1" <?= $shieldSettings['notify_on_repeated_attempts'] ? 'checked' : '' ?>>
                        Email the site admin on repeated attempts from one IP
                    </label>
                    <span class="lp-field__hint">
                        Sent to the address in Settings &rsaquo; General, at most
                        once per hour per IP, once that IP crosses the attempt
                        threshold below.
                    </span>
                </p>

                <p class="lp-field">
                    <label for="notify-threshold">Attempt threshold</label>
                    <input type="number" id="notify-threshold" name="notify_threshold" min="1" value="<?= esc_attr((string) $shieldSettings['notify_threshold']) ?>">
                    <span class="lp-field__hint">Number of blocked attempts from one IP within an hour before the email above fires.</span>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Comment Analysis</h2>
            <p class="lp-field__hint">
                Independent content and behavioral checks (excessive links,
                excessive uppercase/punctuation, hidden Unicode characters,
                repeated phrases, extremely short/long comments, prior spam
                history, posting frequency, duplicate content) that push a
                comment toward Spam via the same <code>comment_is_spam</code>
                filter Akismet already uses. Any one check tripping is enough to
                flag Spam — this can only push a comment <em>toward</em> Spam,
                never un-spam one another check already flagged. Each flagged
                comment is recorded with the checks that caught it — see
                <a href="<?= esc_url(admin_url('maintenance/logs') . '#spam-events') ?>">Maintenance &rsaquo; Logs</a>.
            </p>

            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>?tab=lumora-shield">
                <?= Csrf::field('lumora_shield_settings_comment_analysis') ?>
                <input type="hidden" name="form" value="lumora_shield_settings">
                <input type="hidden" name="csrf_action" value="lumora_shield_settings_comment_analysis">
                <input type="hidden" name="hide_author_archives" value="<?= $shieldSettings['hide_author_archives'] ? '1' : '' ?>">
                <input type="hidden" name="enable_logging" value="<?= $shieldSettings['enable_logging'] ? '1' : '' ?>">
                <input type="hidden" name="log_retention_days" value="<?= (int) $shieldSettings['log_retention_days'] ?>">
                <input type="hidden" name="notify_on_repeated_attempts" value="<?= $shieldSettings['notify_on_repeated_attempts'] ? '1' : '' ?>">
                <input type="hidden" name="notify_threshold" value="<?= (int) $shieldSettings['notify_threshold'] ?>">
                <input type="hidden" name="enable_contact_form_analysis" value="<?= $shieldSettings['enable_contact_form_analysis'] ? '1' : '' ?>">

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="enable_comment_analysis" value="1" <?= $shieldSettings['enable_comment_analysis'] ? 'checked' : '' ?>>
                        Enable Comment Analysis
                    </label>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Contact Form Protection</h2>
            <p class="lp-field__hint">
                The Contact Forms plugin already has its own CSRF, honeypot,
                submission-timing, per-IP rate limiting, optional CAPTCHA
                (reCAPTCHA/Turnstile), and optional Akismet checks — the one gap
                left is a content check for excessive links, uppercase,
                punctuation, hidden Unicode characters, or unusually short/long
                submissions. This reuses the exact same content checks Comment
                Analysis applies to comments, run instead against every
                submitted field value joined together, via the Contact Forms
                plugin's own <code>contact_form_is_spam</code> filter. Only
                applies while the Contact Forms plugin is active.
            </p>

            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>?tab=lumora-shield">
                <?= Csrf::field('lumora_shield_settings_contact_form') ?>
                <input type="hidden" name="form" value="lumora_shield_settings">
                <input type="hidden" name="csrf_action" value="lumora_shield_settings_contact_form">
                <input type="hidden" name="hide_author_archives" value="<?= $shieldSettings['hide_author_archives'] ? '1' : '' ?>">
                <input type="hidden" name="enable_logging" value="<?= $shieldSettings['enable_logging'] ? '1' : '' ?>">
                <input type="hidden" name="log_retention_days" value="<?= (int) $shieldSettings['log_retention_days'] ?>">
                <input type="hidden" name="notify_on_repeated_attempts" value="<?= $shieldSettings['notify_on_repeated_attempts'] ? '1' : '' ?>">
                <input type="hidden" name="notify_threshold" value="<?= (int) $shieldSettings['notify_threshold'] ?>">
                <input type="hidden" name="enable_comment_analysis" value="<?= $shieldSettings['enable_comment_analysis'] ? '1' : '' ?>">

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="enable_contact_form_analysis" value="1" <?= $shieldSettings['enable_contact_form_analysis'] ? 'checked' : '' ?>>
                        Enable Contact Form Protection
                    </label>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Trusted Senders &amp; Sign-up Rules</h2>
            <p class="lp-field__hint">
                Keep regular commenters out of the Spam folder, and decide which
                addresses and sign-up names Lumora Shield treats as spam. A
                trusted sender skips every Lumora Shield check (Akismet and the
                Discussion settings still apply).
            </p>

            <form method="post" action="<?= esc_url(admin_url('settings/security')) ?>?tab=lumora-shield">
                <?= Csrf::field('lumora_shield_settings_lists') ?>
                <input type="hidden" name="form" value="lumora_shield_settings">
                <input type="hidden" name="csrf_action" value="lumora_shield_settings_lists">

                <p class="lp-field">
                    <label for="shield-trusted-ips">Trusted IP addresses</label>
                    <textarea id="shield-trusted-ips" name="trusted_ips" rows="4"><?= esc_html((string) $shieldSettings['trusted_ips']) ?></textarea>
                    <span class="lp-field__hint">One address or range (for example 203.0.113.0/24) per line. Lines starting with # are notes.</span>
                </p>

                <p class="lp-field">
                    <label for="shield-trusted-emails">Trusted email addresses and domains</label>
                    <textarea id="shield-trusted-emails" name="trusted_emails" rows="4"><?= esc_html((string) $shieldSettings['trusted_emails']) ?></textarea>
                    <span class="lp-field__hint">One per line: a full address (jane@example.com) or a domain (example.com, which also covers its subdomains). Use this for co-authors and regular commenters.</span>
                </p>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="block_disposable_email" value="1" <?= $shieldSettings['block_disposable_email'] ? 'checked' : '' ?>>
                        Send comments and contact form messages from disposable email services to Spam
                    </label>
                    <span class="lp-field__hint">Sign-ups from these services are already refused. This applies the same list to comments and contact forms; the message still reaches the Spam folder where you can restore it.</span>
                </p>

                <p class="lp-field">
                    <label for="shield-spam-usernames">Spam words in sign-up names</label>
                    <textarea id="shield-spam-usernames" name="spam_username_keywords" rows="5"><?= esc_html((string) $shieldSettings['spam_username_keywords']) ?></textarea>
                    <span class="lp-field__hint">One word per line, 3 characters or more. A new sign-up whose username or display name contains one is dropped silently, so a bot can't learn what tripped it. Clear the box to switch this off.</span>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
            </form>
        </section>
    </div>
    <?php endif; ?>
</div>
