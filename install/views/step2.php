<?php

/**
 * Installer step 2: site details and administrator account creation.
 *
 * @package LumoraPress
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var array<int, string> $errors */
/** @var string $installUrl */
/** @var string $baseUrl */
/** @var string $detectedSiteUrl */

use LumoraPress\Core\Security\Csrf;

$timezones = DateTimeZone::listIdentifiers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_attr($baseUrl) ?>admin/assets/css/admin.css">
</head>
<body class="lp-admin-login">
    <main class="lp-login lp-install">
        <h1 class="lp-login__brand">Lumora Press</h1>
        <p class="lp-install__step">Step 2 of 2 &mdash; Site &amp; Administrator</p>

        <?php foreach ($errors as $error): ?>
            <p class="lp-alert lp-alert--error" role="alert"><?= esc_html($error) ?></p>
        <?php endforeach; ?>

        <form method="post" action="<?= esc_attr($installUrl) ?>">
            <input type="hidden" name="step" value="2">
            <?= Csrf::field('install_step2') ?>

            <p class="lp-field">
                <label for="site_name">Site Name</label>
                <input type="text" id="site_name" name="site_name" value="<?= esc_attr((string) ($_POST['site_name'] ?? '')) ?>" required>
            </p>
            <p class="lp-field">
                <label for="site_url">Website URL</label>
                <input type="url" id="site_url" name="site_url" value="<?= esc_attr((string) ($_POST['site_url'] ?? $detectedSiteUrl)) ?>" required>
                <span class="lp-field__hint">Detected automatically from this request. Change it if this server sits behind a proxy or load balancer, or the site will be reached at a different address.</span>
            </p>
            <p class="lp-field">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone" required>
                    <?php foreach ($timezones as $timezone): ?>
                        <option value="<?= esc_attr($timezone) ?>"<?= ($_POST['timezone'] ?? 'UTC') === $timezone ? ' selected' : '' ?>><?= esc_html($timezone) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="lp-field">
                <label for="locale">Language</label>
                <select id="locale" name="locale" required>
                    <option value="en">English</option>
                </select>
            </p>
            <p class="lp-field">
                <label for="admin_username">Administrator Username</label>
                <input type="text" id="admin_username" name="admin_username" value="<?= esc_attr((string) ($_POST['admin_username'] ?? '')) ?>" required autocomplete="username">
            </p>
            <p class="lp-field">
                <label for="admin_email">Administrator Email</label>
                <input type="email" id="admin_email" name="admin_email" value="<?= esc_attr((string) ($_POST['admin_email'] ?? '')) ?>" required>
            </p>
            <p class="lp-field">
                <label for="admin_password">Password</label>
                <input type="password" id="admin_password" name="admin_password" required minlength="10" autocomplete="new-password">
            </p>
            <p class="lp-field">
                <label for="admin_password_confirm">Confirm Password</label>
                <input type="password" id="admin_password_confirm" name="admin_password_confirm" required minlength="10" autocomplete="new-password">
            </p>

            <p class="lp-field">
                <button type="submit" class="lp-button lp-button--primary">Install Lumora Press</button>
            </p>
        </form>
    </main>
</body>
</html>
