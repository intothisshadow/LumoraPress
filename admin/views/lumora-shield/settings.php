<?php

/**
 * The admin Lumora Shield > Settings screen, provided by the bundled Lumora Shield plugin (LPP-001).
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
use LumoraPress\Plugins\LumoraShield\LumoraShieldService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LPP-001. Only reachable while the plugin is active (admin/index.php only
 * adds this menu entry in that case), so LumoraShieldService's class is
 * guaranteed to already be loaded — PluginManager::loadActive() required
 * content/plugins/lumora-shield/lumora-shield.php earlier this same
 * request, in include/bootstrap.php. Mirrors appearance/font-awesome.php's
 * identical reasoning.
 */
$service = LumoraShieldService::instance();
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'lumora_shield_settings' && Csrf::verify('lumora_shield_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $service->saveSettings([
        'hide_author_archives' => isset($_POST['hide_author_archives']),
        'enable_logging' => isset($_POST['enable_logging']),
        'log_retention_days' => max(1, (int) ($_POST['log_retention_days'] ?? 30)),
        'notify_on_repeated_attempts' => isset($_POST['notify_on_repeated_attempts']),
        'notify_threshold' => max(1, (int) ($_POST['notify_threshold'] ?? 10)),
        'enable_comment_analysis' => isset($_POST['enable_comment_analysis']),
    ]);

    header('Location: ' . admin_url('lumora-shield/settings') . '?saved=1');
    exit;
}

$settings = $service->settings();
?>
<h1 class="lp-admin__title">Lumora Shield</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Stop User Enumeration</h2>
    <p class="lp-field__hint">
        The public author archive (<code>/author/{slug}</code>) already
        404s an author with zero published posts exactly like a
        nonexistent username — that fix has no downside, so it's always
        on, no setting needed. The one real choice left is below.
    </p>

    <form method="post" action="<?= esc_url(admin_url('lumora-shield/settings')) ?>">
        <?= Csrf::field('lumora_shield_settings') ?>
        <input type="hidden" name="form" value="lumora_shield_settings">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="hide_author_archives" value="1" <?= $settings['hide_author_archives'] ? 'checked' : '' ?>>
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

<section class="lp-admin__panel">
    <h2>Monitoring</h2>
    <p class="lp-field__hint">
        Records every blocked author-archive request (unknown username,
        zero-post author, or hidden by the setting above) — see
        <a href="<?= esc_url(admin_url('lumora-shield/logs')) ?>">Lumora Shield &rsaquo; Logs</a>.
    </p>

    <form method="post" action="<?= esc_url(admin_url('lumora-shield/settings')) ?>">
        <?= Csrf::field('lumora_shield_settings') ?>
        <input type="hidden" name="form" value="lumora_shield_settings">
        <input type="hidden" name="hide_author_archives" value="<?= $settings['hide_author_archives'] ? '1' : '' ?>">
        <input type="hidden" name="enable_comment_analysis" value="<?= $settings['enable_comment_analysis'] ? '1' : '' ?>">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="enable_logging" value="1" <?= $settings['enable_logging'] ? 'checked' : '' ?>>
                Log blocked enumeration attempts
            </label>
        </p>

        <p class="lp-field">
            <label for="log-retention-days">Log retention (days)</label>
            <input type="number" id="log-retention-days" name="log_retention_days" min="1" value="<?= esc_attr((string) $settings['log_retention_days']) ?>">
            <span class="lp-field__hint">Attempts older than this are removed automatically.</span>
        </p>

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="notify_on_repeated_attempts" value="1" <?= $settings['notify_on_repeated_attempts'] ? 'checked' : '' ?>>
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
            <input type="number" id="notify-threshold" name="notify_threshold" min="1" value="<?= esc_attr((string) $settings['notify_threshold']) ?>">
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
        never un-spam one another check already flagged.
    </p>

    <form method="post" action="<?= esc_url(admin_url('lumora-shield/settings')) ?>">
        <?= Csrf::field('lumora_shield_settings') ?>
        <input type="hidden" name="form" value="lumora_shield_settings">
        <input type="hidden" name="hide_author_archives" value="<?= $settings['hide_author_archives'] ? '1' : '' ?>">
        <input type="hidden" name="enable_logging" value="<?= $settings['enable_logging'] ? '1' : '' ?>">
        <input type="hidden" name="log_retention_days" value="<?= (int) $settings['log_retention_days'] ?>">
        <input type="hidden" name="notify_on_repeated_attempts" value="<?= $settings['notify_on_repeated_attempts'] ? '1' : '' ?>">
        <input type="hidden" name="notify_threshold" value="<?= (int) $settings['notify_threshold'] ?>">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="enable_comment_analysis" value="1" <?= $settings['enable_comment_analysis'] ? 'checked' : '' ?>>
                Enable Comment Analysis
            </label>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
    </form>
</section>
