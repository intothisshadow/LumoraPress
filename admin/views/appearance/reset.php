<?php

/**
 * The admin Appearance > Reset Theme Options screen (LP-123): the destructive "Reset Everything" action, moved off the Customize screen onto its own page.
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

use LumoraPress\Controllers\Admin\ThemeCustomizerController;
use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-123: previously a section at the bottom of the flat Theme Options
 * page, sharing that page with six other routine save actions — moved to
 * its own dedicated page so a destructive whole-theme reset is no longer
 * one accidental click away from everyday editing. Resets only the
 * active theme's own values (see ThemeOptions's per-theme scoping) — a
 * different theme's Customize values are untouched.
 */
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;

if ($form === 'theme_options_reset_all') {
    $controller = new ThemeCustomizerController($kernel->themeOptions, $kernel->media);
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $result = $controller->resetAll($csrfToken);

    if ($result->redirectUrl !== null) {
        redirect($result->redirectUrl);
    }

    $error = $result->errorMessage;
}
?>
<h1 class="lp-admin__title">Appearance</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Reset Theme Options</h2>
    <p class="lp-field__hint">
        Resets every Header, Welcome Message, Body, and Footer option back to its default for the active theme.
        This cannot be undone. Other themes' own saved values are not affected.
    </p>
    <form method="post" action="<?= esc_url(admin_url('appearance/reset')) ?>" data-lp-confirm="Reset every Theme Option for the active theme back to its default? This cannot be undone.">
        <?= Csrf::field('theme_options_reset_all') ?>
        <input type="hidden" name="form" value="theme_options_reset_all">
        <button type="submit" class="lp-button lp-button--secondary">Reset All Theme Options</button>
    </form>
    <p class="lp-field__hint"><a href="<?= esc_url(admin_url('appearance/customize')) ?>">Back to Customize</a></p>
</section>
