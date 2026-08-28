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
        'enabled' => isset($_POST['enabled']),
        'protect_user_enumeration' => isset($_POST['protect_user_enumeration']),
        'hide_author_archives' => isset($_POST['hide_author_archives']),
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
        Closes a username-existence oracle in the public author archive
        (<code>/author/{slug}</code>): without this, requesting a real
        username's archive page always returns a normal page (even if
        that person has never published anything), while a made-up
        username 404s — letting an attacker confirm which usernames
        exist by trying a list of guesses.
    </p>

    <form method="post" action="<?= esc_url(admin_url('lumora-shield/settings')) ?>">
        <?= Csrf::field('lumora_shield_settings') ?>
        <input type="hidden" name="form" value="lumora_shield_settings">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
                Enable Lumora Shield
            </label>
        </p>

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="protect_user_enumeration" value="1" <?= $settings['protect_user_enumeration'] ? 'checked' : '' ?>>
                Stop user enumeration via author archives
            </label>
            <span class="lp-field__hint">
                An author's archive page 404s exactly like a nonexistent
                username whenever they have zero published posts.
                A real author's page — anyone who has actually published
                something — keeps working normally; their name is already
                public on their own posts, so hiding it too would break a
                legitimate feature without closing any real gap.
            </span>
        </p>

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="hide_author_archives" value="1" <?= $settings['hide_author_archives'] ? 'checked' : '' ?>>
                Hide author archives entirely
            </label>
            <span class="lp-field__hint">
                Every <code>/author/{slug}</code> URL 404s, regardless of
                how many posts that person has published. Stronger than
                the option above, but removes a public feature (visitors
                can no longer browse "everything by this author") — most
                sites only need the option above.
            </span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
    </form>
</section>
