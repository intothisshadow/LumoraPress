<?php

/**
 * The admin Settings > Privacy screen: the site's privacy policy page, anonymous install statistics, and GDPR-style comment data requests.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Comment;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

// Anonymous Install Ping — off by default, opt-in. A separate form/CSRF
// token from the privacy-policy-page selector below, since this one also
// needs its own post-save side effect (firing an immediate first ping).
$installPingError = null;

if ($form === 'install_ping_settings' && Csrf::verify('install_ping_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $pingWasEnabled = $kernel->installPing->isEnabled();
    $kernel->config->setOption('install_ping_enabled', isset($_POST['install_ping_enabled']) ? '1' : '0');

    if (!$pingWasEnabled && $kernel->installPing->isEnabled()) {
        try {
            $kernel->installPing->sendPing();
        } catch (\Throwable) {
            // Non-fatal — the periodic check on the next admin page load
            // will retry. Doesn't affect the "Saved." confirmation below.
        }
    }

    header('Location: ' . admin_url('settings/privacy') . '?saved=1');
    exit;
}

if ($form === 'install_ping_test' && Csrf::verify('install_ping_test', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    if ($kernel->installPing->isEnabled()) {
        try {
            $kernel->installPing->sendPing();
            header('Location: ' . admin_url('settings/privacy') . '?ping_sent=1');
            exit;
        } catch (\Throwable $exception) {
            $installPingError = 'Could not reach the install ping endpoint: ' . $exception->getMessage();
        }
    } else {
        $installPingError = 'Enable the anonymous install ping first, then you can send a test ping.';
    }
}

// Names an existing Page as the site's privacy policy rather than
// inventing a second content type. privacy_policy_url() is the only
// thing that reads this value; where a theme links to it is its own choice.
if ($form === 'privacy_policy_settings' && Csrf::verify('privacy_policy_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $privacyPolicyPageIdInput = (int) ($_POST['privacy_policy_page_id'] ?? 0);
    $kernel->config->setOption('privacy_policy_page_id', $privacyPolicyPageIdInput > 0 ? (string) $privacyPolicyPageIdInput : '');

    header('Location: ' . admin_url('settings/privacy') . '?saved=1');
    exit;
}

// Comment Data Requests — GDPR-style export/erasure of a commenter's
// personal data by email. Export is a GET-triggered download (no state
// change, the same convention the Contact Forms submissions export
// already uses); erasure is a POST action since it permanently strips
// data.
$commentGdprEmail = trim((string) ($_GET['comment_gdpr_email'] ?? ($_POST['comment_gdpr_email'] ?? '')));
$commentGdprError = null;

if (isset($_GET['comment_gdpr_export'])) {
    if (filter_var($commentGdprEmail, FILTER_VALIDATE_EMAIL) === false) {
        $commentGdprError = 'Enter a valid email address to export comment data.';
    } else {
        $exportComments = $kernel->comments->findAllByEmail($commentGdprEmail);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="comment-data-' . preg_replace('/[^a-z0-9]+/i', '-', $commentGdprEmail) . '-' . date('Y-m-d') . '.json"');

        echo json_encode(array_map(static fn (Comment $exportComment): array => [
            'id' => $exportComment->id,
            'name' => $exportComment->guestName,
            'email' => $exportComment->guestEmail,
            'website' => $exportComment->guestUrl,
            'content' => $exportComment->content,
            'status' => $exportComment->status->value,
            'ip_address' => $exportComment->ipAddress,
            'posted_at' => $exportComment->createdAt->format(DATE_ATOM),
        ], $exportComments), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
} elseif ($form === 'comment_gdpr_erase' && Csrf::verify('comment_gdpr_erase', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    if (filter_var($commentGdprEmail, FILTER_VALIDATE_EMAIL) === false) {
        $commentGdprError = 'Enter a valid email address to erase comment data.';
    } else {
        $commentGdprErasedCount = $kernel->comments->anonymizeByEmail($commentGdprEmail);

        header('Location: ' . admin_url('settings/privacy') . '?comment_gdpr_erased=' . $commentGdprErasedCount);
        exit;
    }
}

$commentGdprMatches = $commentGdprEmail !== '' && filter_var($commentGdprEmail, FILTER_VALIDATE_EMAIL) !== false
    ? $kernel->comments->findAllByEmail($commentGdprEmail)
    : [];

$privacyPolicyPageId = (int) $kernel->config->option('privacy_policy_page_id', '0');
$pageOptions = $kernel->pages->listAllForParentSelect();
$installPingEnabled = $kernel->installPing->isEnabled();
$installUuid = $installPingEnabled ? $kernel->installPing->getOrCreateUuid() : (string) $kernel->config->option('install_uuid', '');
?>
<h1 class="lp-admin__title">Privacy</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if ($installPingError !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($installPingError) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['ping_sent'])): ?>
    <div class="lp-alert lp-alert--success">Test ping sent.</div>
<?php endif; ?>

<?php if ($commentGdprError !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($commentGdprError) ?></div>
<?php endif; ?>

<?php if (isset($_GET['comment_gdpr_erased'])): ?>
    <div class="lp-alert lp-alert--success"><?= (int) $_GET['comment_gdpr_erased'] ?> comment(s) had their personal data erased. Comment text was kept; the author's name, email, website, and IP address were removed.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Privacy Policy Page</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/privacy')) ?>">
        <?= Csrf::field('privacy_policy_settings') ?>
        <input type="hidden" name="form" value="privacy_policy_settings">

        <p class="lp-field">
            <label for="privacy-policy-page-id">Privacy Policy Page</label>
            <select id="privacy-policy-page-id" name="privacy_policy_page_id">
                <option value="0">(None)</option>
                <?php foreach ($pageOptions as $pageOption): ?>
                    <option value="<?= (int) $pageOption['id'] ?>" <?= $privacyPolicyPageId === (int) $pageOption['id'] ? 'selected' : '' ?>>
                        <?= esc_html($pageOption['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">The Page that explains how this site collects and uses visitor data. Available to themes via the <code>privacy_policy_url()</code> template tag.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Anonymous Install Statistics</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/privacy')) ?>">
        <?= Csrf::field('install_ping_settings') ?>
        <input type="hidden" name="form" value="install_ping_settings">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="install_ping_enabled" value="1" <?= $installPingEnabled ? 'checked' : '' ?>>
                Anonymous install ping
            </label>
            <span class="lp-field__hint">
                Off by default. When enabled, Lumora Press periodically sends a tiny, anonymous
                ping (roughly once a month, plus once immediately when you turn this on) to let
                the developer see a rough count of active installs. The ping contains exactly
                three values and nothing else: a randomly generated install ID (not tied to your
                domain, content, or any personal data), your Lumora Press version, and your PHP
                version. It uses a completely separate request from the update checker, so
                enabling or disabling either one never affects the other. If the request fails
                for any reason it fails silently — it never shows an error or blocks anything
                you're doing.
                <?php if ($installUuid !== ''): ?>
                    Install ID: <code><?= esc_html($installUuid) ?></code>
                <?php endif; ?>
            </span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>

    <?php if ($installPingEnabled): ?>
        <form method="post" action="<?= esc_url(admin_url('settings/privacy')) ?>">
            <?= Csrf::field('install_ping_test') ?>
            <input type="hidden" name="form" value="install_ping_test">
            <button type="submit" class="lp-button">Send a test ping now</button>
        </form>
    <?php endif; ?>
</section>

<section class="lp-admin__panel">
    <h2>Comment Data Requests</h2>
    <p class="lp-field__hint">Look up every comment posted under a given email address — whether left as a guest or by a signed-in account — for a GDPR-style data access or erasure request. Matching is case-insensitive.</p>

    <form method="get" action="<?= esc_url(admin_url('settings/privacy')) ?>">
        <p class="lp-field">
            <label for="comment-gdpr-email">Commenter email address</label>
            <input type="email" id="comment-gdpr-email" name="comment_gdpr_email" value="<?= esc_attr($commentGdprEmail) ?>" required>
        </p>
        <button type="submit" class="lp-button lp-button--secondary">Look Up</button>
        <button type="submit" name="comment_gdpr_export" value="1" class="lp-button lp-button--secondary">Export as JSON</button>
    </form>

    <?php if ($commentGdprEmail !== '' && $commentGdprError === null): ?>
        <p class="lp-field__hint">
            <?= count($commentGdprMatches) ?> comment(s) found for <code><?= esc_html($commentGdprEmail) ?></code>.
        </p>

        <?php if ($commentGdprMatches !== []): ?>
            <form method="post" action="<?= esc_url(admin_url('settings/privacy')) ?>" data-lp-confirm="Permanently erase this commenter's name, email, website, and IP address from every matching comment? The comment text itself is kept. This cannot be undone.">
                <?= Csrf::field('comment_gdpr_erase') ?>
                <input type="hidden" name="form" value="comment_gdpr_erase">
                <input type="hidden" name="comment_gdpr_email" value="<?= esc_attr($commentGdprEmail) ?>">
                <button type="submit" class="lp-button lp-button--danger">Erase Personal Data</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
