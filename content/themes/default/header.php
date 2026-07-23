<?php
/** @var string|null $page_title */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($page_title) && $page_title !== null && $page_title !== '' ? esc_html($page_title) . ' ‹ ' : '' ?>Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_url(theme_url('style.css')) ?>">
</head>
<body class="lp-site">
<a class="lp-skip-link" href="#lp-content">Skip to content</a>
<header class="lp-site-header">
    <div class="lp-site-header__inner">
        <p class="lp-site-header__brand"><a href="<?= esc_url(site_url()) ?>">Lumora Press</a></p>
        <nav class="lp-site-header__nav" aria-label="Primary">
            <?php nav_menu('primary'); ?>
        </nav>
    </div>
</header>
<div class="lp-site-body">
