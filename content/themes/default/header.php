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

/*
 * LP-022: a post/page's own meta_title/meta_description override (set on
 * its editor screen) always wins when present. Falls through to the
 * excerpt/content-derived description above, then the site-wide default
 * (LP-042) — same three-tier chain meta_description() already
 * documented, just with the new per-item override slotted in first.
 */
$seo_title = $og_item?->metaTitle ?? $page_title ?? null;
$seo_description = $og_item?->metaDescription ?? ($og_description !== '' ? $og_description : null);
$meta_description = $seo_description ?? meta_description();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $seo_title !== null && $seo_title !== '' ? esc_html($seo_title) . ' ‹ ' : '' ?><?= esc_html(site_name()) ?></title>
    <?php if ($meta_description !== ''): ?>
        <meta name="description" content="<?= esc_attr($meta_description) ?>">
    <?php endif; ?>
    <?php if (search_engines_discouraged()): ?>
        <meta name="robots" content="noindex,nofollow">
    <?php endif; ?>
    <link rel="canonical" href="<?= esc_url(canonical_url()) ?>">
    <?php if (theme_option('google_fonts_url') !== ''): ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="<?= esc_url(theme_option('google_fonts_url')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= esc_url(theme_url('style.css')) ?>">
    <?php if (theme_options_css() !== ''): ?>
        <style nonce="<?= esc_attr(csp_style_nonce()) ?>"><?= theme_options_css() ?></style>
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="<?= esc_attr(site_name()) ?> &raquo; Feed" href="<?= esc_url(home_url('feed')) ?>">
    <link rel="alternate" type="application/atom+xml" title="<?= esc_attr(site_name()) ?> &raquo; Atom Feed" href="<?= esc_url(home_url('feed/atom')) ?>">
    <?php if (favicon_url() !== null): ?>
        <link rel="icon" href="<?= esc_url(favicon_url()) ?>">
    <?php endif; ?>
    <?php if ($og_item !== null): ?>
        <meta property="og:type" content="article">
        <meta property="og:site_name" content="<?= esc_attr(site_name()) ?>">
        <meta property="og:title" content="<?= esc_attr($og_item->metaTitle ?? $og_item->title) ?>">
        <?php if ($og_url !== null): ?>
            <meta property="og:url" content="<?= esc_url($og_url) ?>">
        <?php endif; ?>
        <?php if ($seo_description !== null): ?>
            <meta property="og:description" content="<?= esc_attr($seo_description) ?>">
        <?php endif; ?>
        <?php if (has_post_thumbnail($og_item)): ?>
            <meta property="og:image" content="<?= esc_url((string) post_thumbnail_url($og_item, 'large', absolute: true)) ?>">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="<?= esc_url((string) post_thumbnail_url($og_item, 'large', absolute: true)) ?>">
        <?php elseif (default_og_image_url() !== null): ?>
            <meta property="og:image" content="<?= esc_url((string) default_og_image_url()) ?>">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="<?= esc_url((string) default_og_image_url()) ?>">
        <?php else: ?>
            <meta name="twitter:card" content="summary">
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($og_item instanceof \LumoraPress\Models\Post): ?>
        <?php
        /*
         * LP-022 structured data — BlogPosting for a single post only
         * (the one view with a clear author-less-but-dated "article"
         * shape; pages/archives/search have no equivalent schema.org type
         * worth forcing). Author name is deliberately omitted: there is
         * no author-display-name helper bridged to themes yet (unlike
         * SiteBranding/FeaturedImages), and inventing one is out of scope
         * for this ticket — schema.org's `author` property is
         * recommended, not required.
         */
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $og_item->metaTitle ?? $og_item->title,
            'url' => $og_url,
            'mainEntityOfPage' => $og_url,
        ];

        if ($seo_description !== null) {
            $jsonLd['description'] = $seo_description;
        }

        if ($og_item->publishedAt !== null) {
            $jsonLd['datePublished'] = $og_item->publishedAt->format('c');
        }

        $jsonLd['dateModified'] = $og_item->updatedAt->format('c');

        if (has_post_thumbnail($og_item)) {
            $jsonLd['image'] = [post_thumbnail_url($og_item, 'large', absolute: true)];
        }

        $jsonLd['publisher'] = ['@type' => 'Organization', 'name' => site_name()];
        ?>
        <?php // Slashes deliberately left escaped (json_encode's default) so a title/description containing the literal text "</script>" can never break out of this tag. ?>
        <script type="application/ld+json"><?= json_encode($jsonLd) ?></script>
    <?php endif; ?>
    <?php if (custom_css() !== ''): ?>
        <style nonce="<?= esc_attr(csp_style_nonce()) ?>"><?= custom_css() ?></style>
    <?php endif; ?>
    <?php
    /*
     * LPP-002: the classic WordPress wp_head()-equivalent extension point —
     * lets a plugin (currently just Font Awesome) print its own <link>/
     * <style> tags into <head> without this theme needing to know it
     * exists. Fires unconditionally, same as get_header()/get_footer()
     * above; a plugin with nothing to add here simply never hooks it.
     */
    do_action('head_assets');
    ?>
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
            <?php if (site_tagline() !== ''): ?>
                <span class="lp-site-header__tagline"><?= esc_html(site_tagline()) ?></span>
            <?php endif; ?>
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
