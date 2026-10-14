<?php

/**
 * The page an emailed sign-up confirmation link opens.
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
/** @var string $confirmState one of: ready, created, awaiting, invalid */
/** @var string $confirmToken */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Confirm Your Account &lsaquo; <?= esc_html(site_name()) ?></title>
    <link rel="stylesheet" href="<?= esc_url(admin_asset_url('css/admin.css')) ?>">
</head>
<body class="lp-admin-login">
    <main class="lp-login">
        <h1 class="lp-login__brand"><?= esc_html(site_name()) ?></h1>

        <?php if ($confirmState === 'ready'): ?>
            <p>One more step: confirm that you want an account on <?= esc_html(site_name()) ?>.</p>
            <form class="lp-login__form" method="post" action="<?= esc_url(admin_url('confirm-registration') . '?token=' . urlencode($confirmToken)) ?>">
                <?= Csrf::field('registration_confirm') ?>
                <p class="lp-field">
                    <button type="submit" class="lp-button lp-button--primary">Confirm My Account</button>
                </p>
            </form>
        <?php elseif ($confirmState === 'created'): ?>
            <p class="lp-alert lp-alert--success" role="status">Your account is ready. You can log in now.</p>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('login')) ?>">Log In</a>
            </p>
        <?php elseif ($confirmState === 'awaiting'): ?>
            <p class="lp-alert lp-alert--success" role="status">Thanks, your email is confirmed. An administrator approves new accounts, and we'll email you when yours is ready.</p>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('login')) ?>">Back to Log In</a>
            </p>
        <?php else: ?>
            <p class="lp-alert lp-alert--error" role="alert">This link has expired or was already used. You can sign up again to get a new one.</p>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('register')) ?>">Sign Up</a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
