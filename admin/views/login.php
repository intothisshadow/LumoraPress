<?php

/**
 * The admin login form.
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
    <title>Log In &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_url(admin_asset_url('css/admin.css')) ?>">
</head>
<body class="lp-admin-login">
    <main class="lp-login">
        <h1 class="lp-login__brand">Lumora Press</h1>
        <?php if (($_GET['reset'] ?? null) === 'success'): ?>
            <p class="lp-alert lp-alert--success" role="status">Your password has been reset. Please log in.</p>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <p class="lp-alert lp-alert--error" role="alert"><?= esc_html($error) ?></p>
        <?php endif; ?>
        <form class="lp-login__form" method="post" action="<?= esc_url(admin_url('login')) ?>">
            <?= Csrf::field('admin_login') ?>
            <p class="lp-field">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username">
            </p>
            <p class="lp-field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </p>
            <p class="lp-field lp-field--checkbox">
                <input type="checkbox" id="remember" name="remember" value="1">
                <label for="remember">Remember Me</label>
            </p>
            <p class="lp-field">
                <button type="submit" class="lp-button lp-button--primary">Log In</button>
            </p>
        </form>
        <p class="lp-login__links">
            <a href="<?= esc_url(admin_url('forgot-password')) ?>">Forgot password?</a>
        </p>
    </main>
</body>
</html>
