<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var bool $sent */
/** @var string|null $error */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;

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
    <title>Forgot Password &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_url(admin_asset_url('css/admin.css')) ?>">
</head>
<body class="lp-admin-login">
    <main class="lp-login">
        <h1 class="lp-login__brand">Lumora Press</h1>
        <?php if ($error !== null): ?>
            <p class="lp-alert lp-alert--error" role="alert"><?= esc_html($error) ?></p>
        <?php endif; ?>

        <?php if ($sent): ?>
            <p class="lp-alert lp-alert--success" role="status">If an account exists for that email address, a password reset link has been sent.</p>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('login')) ?>">Back to Log In</a>
            </p>
        <?php else: ?>
            <p>Enter your account's email address and we'll send you a link to reset your password.</p>
            <form class="lp-login__form" method="post" action="<?= esc_url(admin_url('forgot-password')) ?>">
                <?= Csrf::field('password_reset_request') ?>
                <?= FormTiming::field() ?>

                <p class="lp-login__honeypot" aria-hidden="true">
                    <label for="reset-website">Leave this field blank</label>
                    <input type="text" id="reset-website" name="reset_website" tabindex="-1" autocomplete="off">
                </p>

                <p class="lp-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required autofocus autocomplete="email">
                </p>
                <p class="lp-field">
                    <button type="submit" class="lp-button lp-button--primary">Send Reset Link</button>
                </p>
            </form>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('login')) ?>">Back to Log In</a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
