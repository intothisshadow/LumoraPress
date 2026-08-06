<?php

/**
 * Installer success screen shown once install completes.
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
/** @var string $installUrl */
/** @var string $baseUrl */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation Complete &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_attr($baseUrl) ?>admin/assets/css/admin.css">
</head>
<body class="lp-admin-login">
    <main class="lp-login lp-install">
        <h1 class="lp-login__brand">Lumora Press is installed</h1>
        <p>Sit down. Write. Publish.</p>
        <!-- INSTALLER_CLEANUP_STATUS -->
        <p><a class="lp-button lp-button--primary" href="<?= esc_attr($baseUrl) ?>admin/">Log In to the Admin Area</a></p>
    </main>
</body>
</html>
