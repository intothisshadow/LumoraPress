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
