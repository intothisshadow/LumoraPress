<?php

/**
 * Default theme template for a static page.
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
/**
 * @var \LumoraPress\Models\Page $page
 * @var array<int, \LumoraPress\Models\Page> $page_ancestors
 * @var array<string, mixed> $comment_data Everything comments_template() needs — see SiteController::commentTemplateDataForPage().
 */
get_header(['page' => $page]);
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <article class="lp-page">
            <?php $lpThumbnailAboveTitle = theme_option('featured_image_position') !== 'beside' && has_post_thumbnail($page); ?>
            <?php if ($lpThumbnailAboveTitle): ?>
                <div class="lp-post__thumbnail lp-post__thumbnail--hero lp-gallery">
                    <?php the_post_thumbnail_lightbox($page, size: 'large'); ?>
                </div>
            <?php endif; ?>
            <?php the_page_breadcrumbs($page, $page_ancestors ?? []); ?>
            <h1 class="lp-page-title"><?= esc_html($page->title) ?></h1>
            <?php if (!$lpThumbnailAboveTitle && has_post_thumbnail($page)): ?>
                <div class="lp-post__thumbnail lp-gallery">
                    <?php the_post_thumbnail_lightbox($page, size: 'large'); ?>
                </div>
            <?php endif; ?>
            <div class="lp-post__content"><?= render_content($page->content, $page->contentFormat) ?></div>
            <?php $pageEditUrl = edit_page_link($page); ?>
            <?php if ($pageEditUrl !== null): ?>
                <p class="lp-post__edit-link"><a href="<?= esc_url($pageEditUrl) ?>">Edit this page</a></p>
            <?php endif; ?>
        </article>
        <?php comments_template($comment_data); ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
