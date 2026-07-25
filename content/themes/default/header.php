<?php
/** @var string|null $page_title */
/** @var \LumoraPress\Models\Post|null $post */
/** @var \LumoraPress\Models\Page|null $page */

/*
 * Open Graph / Twitter Card meta tags (LP-040) — only rendered for a
 * single post/page view, the only views with one canonical "primary
 * image". $post/$page only arrive here because single.php/page.php now
 * call get_header(['post' => $post]) / get_header(['page' => $page]) —
 * see get_header()'s docblock in include/theme.php for why every other
 * caller (index/archive/search/404) still works unchanged calling it bare.
 */
$og_item = $post ?? $page ?? null;
$og_url = $og_item instanceof \LumoraPress\Models\Post
    ? home_url('post/' . $og_item->slug)
    : ($og_item instanceof \LumoraPress\Models\Page ? home_url('page/' . $og_item->slug) : null);
$og_description = $og_item !== null
    ? ($og_item->excerpt !== '' ? $og_item->excerpt : make_excerpt(content_plain_text($og_item->content, $og_item->contentFormat)))
    : '';
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
    <?php if ($og_item !== null): ?>
        <meta property="og:type" content="article">
        <meta property="og:site_name" content="<?= esc_attr(site_name()) ?>">
        <meta property="og:title" content="<?= esc_attr($og_item->title) ?>">
        <?php if ($og_url !== null): ?>
            <meta property="og:url" content="<?= esc_url($og_url) ?>">
        <?php endif; ?>
        <?php if ($og_description !== ''): ?>
            <meta property="og:description" content="<?= esc_attr($og_description) ?>">
        <?php endif; ?>
        <?php if (has_post_thumbnail($og_item)): ?>
            <meta property="og:image" content="<?= esc_url((string) post_thumbnail_url($og_item, 'large', absolute: true)) ?>">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="<?= esc_url((string) post_thumbnail_url($og_item, 'large', absolute: true)) ?>">
        <?php else: ?>
            <meta name="twitter:card" content="summary">
        <?php endif; ?>
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
