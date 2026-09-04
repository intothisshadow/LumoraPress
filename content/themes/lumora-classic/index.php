<?php

/**
 * Default theme template for the homepage post listing.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var array<int, \LumoraPress\Models\Post> $posts */
/** @var array{page: int, totalPages: int} $pagination */
/** @var string|null $page_title */
get_header();
?>
<?php if ($page_title === null): ?>
    <?php
    /*
     * WebSite structured data — only on the genuine homepage. This
     * template is shared with the "Posts page" at its own URL, which
     * always passes a non-null $page_title, so that check distinguishes
     * the two here.
     */
    $websiteJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => site_name(),
        'url' => home_url(),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => home_url('search') . '?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
    ?>
    <script type="application/ld+json"><?= json_encode($websiteJsonLd) ?></script>
<?php endif; ?>
<?php $lpHomeLayout = theme_option('lumora_classic_home_layout') === 'classic' ? 'classic' : 'magazine'; ?>
<div id="lp-content" class="lp-content lp-content--home lp-content--home--<?= esc_attr($lpHomeLayout) ?> lp-layout">
    <main class="lp-main">
        <?php if ($page_title === null && $posts !== []): ?>
            <header class="lp-main-header">
                <p class="lp-eyebrow">The latest from the archive</p>
                <h1 class="lp-page-title">Stories worth keeping</h1>
                <p class="lp-main-header__intro">A considered collection of notes, discoveries, and details from the Lumora Press archive.</p>
            </header>
        <?php endif; ?>
        <?php if ($posts === []): ?>
            <p class="lp-empty-state">No posts have been published yet.</p>
        <?php else: ?>
            <div class="lp-post-list lp-gallery">
                <?php foreach ($posts as $post): ?>
                    <article class="lp-post-list__item">
                        <?php if (theme_option('show_featured_image_in_listings') !== '0' && has_post_thumbnail($post)): ?>
                            <div class="lp-post-list__thumbnail">
                                <?php the_post_thumbnail_lightbox($post, 'large'); ?>
                            </div>
                        <?php endif; ?>
                        <div class="lp-post-list__body">
                            <h2 class="lp-post-list__title">
                                <a href="<?= esc_url(post_permalink($post)) ?>"><?= esc_html($post->title) ?></a>
                            </h2>
                            <p class="lp-post-list__meta">
                                <?php the_author_link($post); ?>
                                <?= $post->publishedAt !== null ? esc_html(the_date($post->publishedAt)) : '' ?>
                                <?php if (post_categories($post) !== []): ?>
                                    <span class="lp-post-list__categories">&middot; Filed under <?php the_post_categories($post); ?></span>
                                <?php endif; ?>
                            </p>
                            <?php $moreTagContent = get_the_content_up_to_more_tag($post); ?>
                            <?php if ($moreTagContent !== null): ?>
                                <div class="lp-post-list__content lp-post__content"><?= $moreTagContent ?></div>
                                <a class="lp-post-list__more" href="<?= esc_url(post_permalink($post)) ?>"><?= esc_html(theme_option('read_more_text')) ?></a>
                            <?php elseif (theme_option('post_display_mode') === 'full'): ?>
                                <div class="lp-post-list__content lp-post__content"><?php the_content($post); ?></div>
                            <?php else: ?>
                                <?php $postListingExcerpt = get_the_excerpt($post); ?>
                                <?php if ($postListingExcerpt !== ''): ?>
                                    <p class="lp-post-list__excerpt"><?= esc_html($postListingExcerpt) ?></p>
                                <?php endif; ?>
                                <a class="lp-post-list__more" href="<?= esc_url(post_permalink($post)) ?>"><?= esc_html(theme_option('read_more_text')) ?></a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php render_pagination($pagination); ?>
        <?php endif; ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
