<?php

/**
 * Installer screen shown when config/config.php already exists.
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
    <title>Already Installed &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_attr($baseUrl) ?>admin/assets/css/admin.css">
</head>
<body class="lp-admin-login">
    <main class="lp-login lp-install">
        <h1 class="lp-login__brand">Already Installed</h1>
        <p>Lumora Press has already been installed on this site.</p>
        <p><a class="lp-button lp-button--primary" href="<?= esc_attr($baseUrl) ?>">Visit Site</a></p>
    </main>
</body>
</html>
