<?php
/** @var array<int, string> $errors */
/** @var string $suggestedPrefix */
/** @var string $installUrl */
/** @var string $baseUrl */

use LumoraPress\Core\Security\Csrf;
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
        <p class="lp-install__step">Step 1 of 2 &mdash; Database Connection</p>

        <?php foreach ($errors as $error): ?>
            <p class="lp-alert lp-alert--error" role="alert"><?= esc_html($error) ?></p>
        <?php endforeach; ?>

        <form method="post" action="<?= esc_attr($installUrl) ?>">
            <input type="hidden" name="step" value="1">
            <?= Csrf::field('install_step1') ?>

            <p class="lp-field">
                <label for="db_host">Database Host</label>
                <input type="text" id="db_host" name="db_host" value="<?= esc_attr((string) ($_POST['db_host'] ?? '127.0.0.1')) ?>" required>
            </p>
            <p class="lp-field">
                <label for="db_port">Database Port</label>
                <input type="number" id="db_port" name="db_port" value="<?= esc_attr((string) ($_POST['db_port'] ?? '3306')) ?>" required>
            </p>
            <p class="lp-field">
                <label for="db_name">Database Name</label>
                <input type="text" id="db_name" name="db_name" value="<?= esc_attr((string) ($_POST['db_name'] ?? '')) ?>" required>
            </p>
            <p class="lp-field">
                <label for="db_user">Database User</label>
                <input type="text" id="db_user" name="db_user" value="<?= esc_attr((string) ($_POST['db_user'] ?? '')) ?>" required>
            </p>
            <p class="lp-field">
                <label for="db_password">Database Password</label>
                <input type="password" id="db_password" name="db_password">
            </p>
            <p class="lp-field">
                <label for="table_prefix">Table Prefix</label>
                <input type="text" id="table_prefix" name="table_prefix" value="<?= esc_attr((string) ($_POST['table_prefix'] ?? $suggestedPrefix)) ?>" required pattern="[A-Za-z0-9_]+">
                <span class="lp-field__hint">A random prefix has been generated for you. You may change it, but avoid guessable values like <code>wp_</code> or <code>lp_</code>.</span>
            </p>

            <p class="lp-field">
                <button type="submit" class="lp-button lp-button--primary">Continue</button>
            </p>
        </form>
    </main>
</body>
</html>
