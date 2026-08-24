<?php

/**
 * The admin Settings > Privacy screen: the site's privacy policy page.
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

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * New Settings > Privacy sub-page — names an existing Page as the site's
 * privacy policy, the same "point at an existing Page rather than invent
 * a second content type" approach Settings > Reading's homepage/posts
 * page selectors already use. privacy_policy_url() (a new template tag,
 * include/theme-functions.php) is the only thing that reads this value —
 * whether/where a theme links to it is left to the theme's own markup,
 * matching LPP-004's WordPress Importer, which can now write this key
 * on import.
 */
$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'privacy_policy_settings' && Csrf::verify('privacy_policy_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $privacyPolicyPageIdInput = (int) ($_POST['privacy_policy_page_id'] ?? 0);
    $kernel->config->setOption('privacy_policy_page_id', $privacyPolicyPageIdInput > 0 ? (string) $privacyPolicyPageIdInput : '');

    header('Location: ' . admin_url('settings/privacy') . '?saved=1');
    exit;
}

$privacyPolicyPageId = (int) $kernel->config->option('privacy_policy_page_id', '0');
$pageOptions = $kernel->pages->listAllForParentSelect();
?>
<h1 class="lp-admin__title">Privacy</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
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
