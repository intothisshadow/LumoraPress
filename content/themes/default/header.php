<?php
/** @var string|null $page_title */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($page_title) && $page_title !== null && $page_title !== '' ? esc_html($page_title) . ' ‹ ' : '' ?><?= esc_html(site_name()) ?></title>
    <link rel="stylesheet" href="<?= esc_url(theme_url('style.css')) ?>">
    <link rel="alternate" type="application/rss+xml" title="<?= esc_attr(site_name()) ?> &raquo; Feed" href="<?= esc_url(home_url('feed')) ?>">
    <link rel="alternate" type="application/atom+xml" title="<?= esc_attr(site_name()) ?> &raquo; Atom Feed" href="<?= esc_url(home_url('feed/atom')) ?>">
    <?php if (favicon_url() !== null): ?>
        <link rel="icon" href="<?= esc_url(favicon_url()) ?>">
    <?php endif; ?>
    <?php if (custom_css() !== ''): ?>
        <style><?= custom_css() ?></style>
    <?php endif; ?>
</head>
<body class="lp-site">
<a class="lp-skip-link" href="#lp-content">Skip to content</a>
<header class="lp-site-header">
    <div class="lp-site-header__inner">
        <p class="lp-site-header__brand">
            <a href="<?= esc_url(site_url()) ?>">
                <?php if (site_logo_url() !== null): ?>
                    <img class="lp-site-header__logo" src="<?= esc_url(site_logo_url()) ?>" alt="<?= esc_attr(site_name()) ?>">
                <?php else: ?>
                    <?= esc_html(site_name()) ?>
                <?php endif; ?>
            </a>
        </p>
        <nav class="lp-site-header__nav" aria-label="Primary">
            <?php nav_menu('primary'); ?>
        </nav>
        <form class="lp-search-form" role="search" method="get" action="<?= esc_url(site_url('search')) ?>">
            <label class="lp-search-form__label" for="lp-search-q">Search</label>
            <input class="lp-search-form__input" type="search" id="lp-search-q" name="q" value="<?= esc_attr((string) ($_GET['q'] ?? '')) ?>" placeholder="Search&hellip;">
            <button class="lp-search-form__button" type="submit">Search</button>
        </form>
    </div>
</header>
<div class="lp-site-body">
