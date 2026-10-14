<?php

/**
 * The public sign-up form.
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
/** @var list<string> $registerErrors */
/** @var bool $registerSent */
/** @var string|null $registerNotice */
/** @var array{username: string, email: string, display_name: string} $registerValues */
/** @var string $registerQuestion */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormHoneypot;
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
    <title>Sign Up &lsaquo; <?= esc_html(site_name()) ?></title>
    <link rel="stylesheet" href="<?= esc_url(admin_asset_url('css/admin.css')) ?>">
</head>
<body class="lp-admin-login">
    <main class="lp-login">
        <h1 class="lp-login__brand"><?= esc_html(site_name()) ?></h1>

        <?php if ($registerNotice !== null): ?>
            <p class="lp-alert lp-alert--error" role="alert"><?= esc_html($registerNotice) ?></p>
        <?php endif; ?>

        <?php if ($registerErrors !== []): ?>
            <div class="lp-alert lp-alert--error" role="alert">
                <ul>
                    <?php foreach ($registerErrors as $registerError): ?>
                        <li><?= esc_html($registerError) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($registerSent): ?>
            <p class="lp-alert lp-alert--success" role="status">Check your email. If the details were accepted, we've sent a message with a link to finish signing up. The link works for 48 hours.</p>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('login')) ?>">Back to Log In</a>
            </p>
        <?php elseif ($registerNotice === null): ?>
            <p>Create an account to comment and manage your own profile.</p>
            <form class="lp-login__form" method="post" action="<?= esc_url(admin_url('register')) ?>">
                <?= Csrf::field('registration') ?>
                <?= FormTiming::field() ?>
                <?= FormHoneypot::field('register', 'lp-login__honeypot') ?>

                <p class="lp-field">
                    <label for="register-username">Username</label>
                    <input type="text" id="register-username" name="username" value="<?= esc_attr($registerValues['username']) ?>" required autofocus autocomplete="username" minlength="3" maxlength="60">
                    <span class="lp-field__hint">3 to 60 characters. You use this to log in.</span>
                </p>
                <p class="lp-field">
                    <label for="register-email">Email</label>
                    <input type="email" id="register-email" name="email" value="<?= esc_attr($registerValues['email']) ?>" required autocomplete="email" maxlength="191">
                    <span class="lp-field__hint">We'll email a link to confirm it. Nothing is created until you follow it.</span>
                </p>
                <p class="lp-field">
                    <label for="register-display-name">Display name <span class="lp-field__optional">(optional)</span></label>
                    <input type="text" id="register-display-name" name="display_name" value="<?= esc_attr($registerValues['display_name']) ?>" autocomplete="nickname" maxlength="100">
                    <span class="lp-field__hint">The name shown beside your comments. It can't contain links.</span>
                </p>
                <p class="lp-field">
                    <label for="register-password">Password</label>
                    <input type="password" id="register-password" name="password" required autocomplete="new-password" minlength="10">
                    <span class="lp-field__hint">At least 10 characters.</span>
                </p>
                <p class="lp-field">
                    <label for="register-password-confirm">Confirm password</label>
                    <input type="password" id="register-password-confirm" name="password_confirm" required autocomplete="new-password" minlength="10">
                </p>
                <?php if ($registerQuestion !== ''): ?>
                    <p class="lp-field">
                        <label for="register-answer"><?= esc_html($registerQuestion) ?></label>
                        <input type="text" id="register-answer" name="registration_answer" required autocomplete="off" maxlength="200">
                    </p>
                <?php endif; ?>
                <p class="lp-field">
                    <button type="submit" class="lp-button lp-button--primary">Sign Up</button>
                </p>
            </form>
            <p class="lp-login__links">
                <a href="<?= esc_url(admin_url('login')) ?>">Already have an account? Log in</a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
