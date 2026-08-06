<?php

/**
 * The password reset form, reached via a single-use token link.
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
/** @var int|null $tokenUserId */
/** @var string $token */
/** @var string|null $error */

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
    <title>Reset Password &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_url(admin_asset_url('css/admin.css')) ?>">
</head>
<body class="lp-admin-login">
    <main class="lp-login">
        <h1 class="lp-login__brand">Lumora Press</h1>
        <?php if ($error !== null): ?>
            <p class="lp-alert lp-alert--error" role="alert"><?= esc_html($error) ?></p>
        <?php endif; ?>

        <?php if ($tokenUserId === null): ?>
            <p class="lp-alert lp-alert--error" role="alert">This password reset link is invalid or has expired.</p>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('forgot-password')) ?>">Request a new link</a>
            </p>
        <?php else: ?>
            <form class="lp-login__form" method="post" action="<?= esc_url(admin_url('reset-password')) ?>?token=<?= esc_attr(urlencode($token)) ?>">
                <?= Csrf::field('password_reset_confirm') ?>
                <p class="lp-field">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required minlength="8" autofocus autocomplete="new-password">
                </p>
                <p class="lp-field">
                    <label for="password-confirm">Confirm New Password</label>
                    <input type="password" id="password-confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                </p>
                <p class="lp-field">
                    <button type="submit" class="lp-button lp-button--primary">Reset Password</button>
                </p>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
