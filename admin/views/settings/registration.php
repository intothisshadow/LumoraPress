<?php

/**
 * The admin Settings > Registration screen: whether visitors can sign up, and the spam protections in front of it.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\RegistrationSpamCheck;
use LumoraPress\Core\Security\RegistrationThrottle;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$registration = $kernel->registration;
$throttle = $kernel->registrationThrottle;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'registration_settings' && Csrf::verify('registration_settings', $token)) {
        $kernel->config->setOption('users_can_register', ($_POST['users_can_register'] ?? '') === '1' ? '1' : '0');
        // Only roles that can't publish are ever stored; anything else is Subscriber.
        $kernel->config->setOption('registration_default_role', ($_POST['registration_default_role'] ?? '') === 'contributor' ? 'contributor' : 'subscriber');
        $kernel->config->setOption('registration_require_approval', ($_POST['registration_require_approval'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('registration_comment_hold_hours', (string) max(0, min(720, (int) ($_POST['registration_comment_hold_hours'] ?? 24))));
        $kernel->config->setOption('registration_ip_limit', (string) max(0, min(100, (int) ($_POST['registration_ip_limit'] ?? RegistrationThrottle::DEFAULT_IP_LIMIT))));
        $kernel->config->setOption('registration_flood_limit', (string) max(0, min(10000, (int) ($_POST['registration_flood_limit'] ?? RegistrationThrottle::DEFAULT_FLOOD_LIMIT))));
        $kernel->config->setOption('registration_pause_minutes', (string) max(1, min(1440, (int) ($_POST['registration_pause_minutes'] ?? RegistrationThrottle::DEFAULT_PAUSE_MINUTES))));
        $kernel->config->setOption('registration_block_disposable', ($_POST['registration_block_disposable'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('registration_use_akismet', ($_POST['registration_use_akismet'] ?? '') === '1' ? '1' : '0');

        foreach (['registration_domain_blocklist', 'registration_domain_allowlist'] as $listKey) {
            $kernel->config->setOption($listKey, implode("\n", RegistrationSpamCheck::parseDomains(is_string($_POST[$listKey] ?? null) ? mb_substr($_POST[$listKey], 0, 5000) : '')));
        }

        $question = trim(mb_substr(is_string($_POST['registration_question'] ?? null) ? $_POST['registration_question'] : '', 0, 150));
        $answer = trim(mb_substr(is_string($_POST['registration_answer'] ?? null) ? $_POST['registration_answer'] : '', 0, 100));
        // A question nobody can answer would lock everyone out, so it is only kept with an answer.
        $kernel->config->setOption('registration_question', $answer === '' ? '' : $question);
        $kernel->config->setOption('registration_answer', $question === '' ? '' : $answer);

        header('Location: ' . admin_url('settings/registration') . '?saved=1' . ($question !== '' && $answer === '' ? '&question_dropped=1' : ''));
        exit;
    }

    if ($form === 'registration_resume' && Csrf::verify('registration_resume', $token)) {
        $throttle->resume();
        header('Location: ' . admin_url('settings/registration') . '?resumed=1');
        exit;
    }
}

$registration->purgeExpired();

$config = $kernel->config;
$secondsPaused = $throttle->secondsPaused();
$waitingForApproval = count($registration->awaitingApproval());
$waitingForEmail = $registration->countPending() - $waitingForApproval;
$akismetAvailable = $kernel->akismet->isEnabled();
?>
<h1 class="lp-admin__title">Registration</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['question_dropped'])): ?>
    <div class="lp-alert lp-alert--error">The question wasn't saved because it has no answer. Enter both, or leave both empty.</div>
<?php endif; ?>

<?php if (isset($_GET['resumed'])): ?>
    <div class="lp-alert lp-alert--success">Sign-ups resumed.</div>
<?php endif; ?>

<?php if ($secondsPaused > 0): ?>
    <div class="lp-alert lp-alert--warning">
        Sign-ups are paused for another <?= (int) ceil($secondsPaused / 60) ?> minute(s) because of a burst of submissions.
        <form method="post" action="<?= esc_url(admin_url('settings/registration')) ?>" class="lp-admin__inline-form">
            <?= Csrf::field('registration_resume') ?>
            <input type="hidden" name="form" value="registration_resume">
            <button type="submit" class="lp-button">Resume Now</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($config->option('comment_require_registration', '0') === '1' && !$registration->isEnabled()): ?>
    <div class="lp-alert lp-alert--warning">Comments currently require an account, but sign-ups are off, so only existing users can comment.</div>
<?php endif; ?>

<form method="post" action="<?= esc_url(admin_url('settings/registration')) ?>">
    <?= Csrf::field('registration_settings') ?>
    <input type="hidden" name="form" value="registration_settings">

    <section class="lp-admin__panel">
        <h2>Sign-ups</h2>
        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="users_can_register" value="1" <?= $registration->isEnabled() ? 'checked' : '' ?>>
                Let visitors sign up for an account
            </label>
            <span class="lp-field__hint">Off by default. When on, the log-in page links to a sign-up form at <code><?= esc_html(home_url('admin/register')) ?></code>. Nothing becomes an account until the visitor confirms their email address, so sign-ups from bots never reach your Users list.</span>
        </div>

        <p class="lp-field">
            <label for="registration-default-role">Role for new accounts</label>
            <select id="registration-default-role" name="registration_default_role">
                <option value="subscriber" <?= $registration->defaultRole()->value === 'subscriber' ? 'selected' : '' ?>>Subscriber (can comment and edit their own profile)</option>
                <option value="contributor" <?= $registration->defaultRole()->value === 'contributor' ? 'selected' : '' ?>>Contributor (can also write posts for review)</option>
            </select>
            <span class="lp-field__hint">Only roles that can't publish are offered here.</span>
        </p>

        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="registration_require_approval" value="1" <?= $registration->requiresApproval() ? 'checked' : '' ?>>
                An administrator must approve each account
            </label>
            <span class="lp-field__hint">After confirming their email, the visitor waits for you to approve them on the Users screen. You get a notification for each one.</span>
        </div>

        <p class="lp-field">
            <label for="registration-hold-hours">Hold comments from new accounts for (hours)</label>
            <input type="number" id="registration-hold-hours" name="registration_comment_hold_hours" min="0" max="720" value="<?= esc_attr((string) $config->option('registration_comment_hold_hours', '24')) ?>">
            <span class="lp-field__hint">A new account can comment straight away, but for this long its comments wait for your approval, even if returning commenters are normally approved automatically. 0 turns it off.</span>
        </p>

        <p class="lp-field__hint"><?= (int) $waitingForEmail ?> sign-up(s) waiting for an email confirmation, <?= (int) $waitingForApproval ?> waiting for approval. Unconfirmed sign-ups are deleted after 48 hours.</p>
    </section>

    <section class="lp-admin__panel">
        <h2>Limits</h2>
        <p class="lp-field">
            <label for="registration-ip-limit">Sign-up attempts per IP address, per hour</label>
            <input type="number" id="registration-ip-limit" name="registration_ip_limit" min="0" max="100" value="<?= esc_attr((string) $config->option('registration_ip_limit', (string) RegistrationThrottle::DEFAULT_IP_LIMIT)) ?>">
            <span class="lp-field__hint">0 means no limit. Every submission counts, whether or not it was valid.</span>
        </p>
        <p class="lp-field">
            <label for="registration-flood-limit">Pause sign-ups after this many attempts in an hour, from all addresses</label>
            <input type="number" id="registration-flood-limit" name="registration_flood_limit" min="0" max="10000" value="<?= esc_attr((string) $config->option('registration_flood_limit', (string) RegistrationThrottle::DEFAULT_FLOOD_LIMIT)) ?>">
            <span class="lp-field__hint">Bot networks switch addresses, so a limit per address isn't enough. When this total is passed, sign-ups pause by themselves and you get a notification. 0 turns the pause off.</span>
        </p>
        <p class="lp-field">
            <label for="registration-pause-minutes">How long a pause lasts (minutes)</label>
            <input type="number" id="registration-pause-minutes" name="registration_pause_minutes" min="1" max="1440" value="<?= esc_attr((string) $config->option('registration_pause_minutes', (string) RegistrationThrottle::DEFAULT_PAUSE_MINUTES)) ?>">
        </p>
    </section>

    <section class="lp-admin__panel">
        <h2>Email Addresses</h2>
        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="registration_block_disposable" value="1" <?= $config->option('registration_block_disposable', '1') !== '0' ? 'checked' : '' ?>>
                Refuse well-known disposable email services
            </label>
            <span class="lp-field__hint">Blocks addresses at services that hand out throw-away inboxes, which bots use to get past the email confirmation.</span>
        </div>
        <p class="lp-field">
            <label for="registration-domain-blocklist">Also refuse these email domains</label>
            <textarea id="registration-domain-blocklist" name="registration_domain_blocklist" rows="4" spellcheck="false"><?= esc_html((string) $config->option('registration_domain_blocklist', '')) ?></textarea>
            <span class="lp-field__hint">One domain per line, such as <code>example.com</code>. A subdomain of a listed domain is refused too. Lines starting with <code>#</code> are ignored.</span>
        </p>
        <p class="lp-field">
            <label for="registration-domain-allowlist">Only allow these email domains</label>
            <textarea id="registration-domain-allowlist" name="registration_domain_allowlist" rows="3" spellcheck="false"><?= esc_html((string) $config->option('registration_domain_allowlist', '')) ?></textarea>
            <span class="lp-field__hint">Leave it empty to allow any domain. Fill it in only for a closed community, for example your organisation's own domain.</span>
        </p>
    </section>

    <section class="lp-admin__panel">
        <h2>Extra Checks</h2>
        <p class="lp-field">
            <label for="registration-question">A question for visitors to answer</label>
            <input type="text" id="registration-question" name="registration_question" maxlength="150" value="<?= esc_attr((string) $config->option('registration_question', '')) ?>" placeholder="What is the name of this site's cat?">
            <span class="lp-field__hint">A quiet alternative to a CAPTCHA: a question only your real readers can answer. Leave both fields empty to skip it.</span>
        </p>
        <p class="lp-field">
            <label for="registration-answer">The answer</label>
            <input type="text" id="registration-answer" name="registration_answer" maxlength="100" value="<?= esc_attr((string) $config->option('registration_answer', '')) ?>" autocomplete="off">
            <span class="lp-field__hint">Capitals and extra spaces are ignored.</span>
        </p>
        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="registration_use_akismet" value="1" <?= $config->option('registration_use_akismet', '0') === '1' ? 'checked' : '' ?> <?= $akismetAvailable ? '' : 'disabled' ?>>
                Check sign-ups with Akismet
            </label>
            <span class="lp-field__hint"><?= $akismetAvailable ? 'Sends the username, email address, and IP address to Akismet, using the key you set up for comments.' : 'Turn on Akismet under Settings › Security first.' ?></span>
        </div>
        <p class="lp-field__hint">Addresses you block on Settings › Discussion are refused here as well.</p>
    </section>

    <p><button type="submit" class="lp-button lp-button--primary">Save</button></p>
</form>
