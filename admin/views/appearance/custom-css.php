<?php

/**
 * The admin Appearance > Custom CSS screen.
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
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'custom_css' && Csrf::verify('custom_css', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('custom_css', (string) ($_POST['custom_css'] ?? ''));

    header('Location: ' . admin_url('appearance/custom-css') . '?saved=1');
    exit;
}
?>
<h1 class="lp-admin__title">Appearance</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Custom CSS</h2>
    <form method="post" action="<?= esc_url(admin_url('appearance/custom-css')) ?>">
        <?= Csrf::field('custom_css') ?>
        <input type="hidden" name="form" value="custom_css">

        <p class="lp-field">
            <label for="custom-css">Additional CSS, applied on every public page</label>
            <textarea id="custom-css" name="custom_css" rows="12" class="lp-code-textarea"><?= esc_html((string) $kernel->config->option('custom_css', '')) ?></textarea>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
