<?php

/**
 * Installer screen shown when a server requirement is missing.
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
/** @var array<int, string> $requirementProblems */
/** @var string $installUrl */
/** @var string $baseUrl */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Requirements &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_attr($baseUrl) ?>admin/assets/css/admin.css">
</head>
<body class="lp-admin-login">
    <main class="lp-login lp-install">
        <h1 class="lp-login__brand">System Requirements</h1>
        <p>Lumora Press can't be installed until the following are resolved:</p>
        <ul class="lp-install__requirements">
            <?php foreach ($requirementProblems as $problem): ?>
                <li class="lp-alert lp-alert--error"><?= esc_html($problem) ?></li>
            <?php endforeach; ?>
        </ul>
        <p>Once resolved, reload this page to try again.</p>
    </main>
</body>
</html>
