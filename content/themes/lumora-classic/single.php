<?php

/**
 * Default theme template for a single post.
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
 * @var \LumoraPress\Models\Post $post
 * @var array<string, mixed> $comment_data Everything comments_template() needs — see SiteController::commentTemplateData().
 */
get_header(['post' => $post]);
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <article class="lp-post">
            <?php $lpThumbnailAboveTitle = theme_option('featured_image_position') !== 'beside' && has_post_thumbnail($post); ?>
            <?php if ($lpThumbnailAboveTitle): ?>
                <div class="lp-post__thumbnail lp-post__thumbnail--hero lp-gallery">
                    <?php the_post_thumbnail_lightbox($post, size: 'large'); ?>
                </div>
            <?php endif; ?>
            <h1 class="lp-post__title"><?= esc_html($post->title) ?></h1>
            <p class="lp-post__meta">
                <?php the_author_link($post); ?>
                <?= $post->publishedAt !== null ? esc_html(the_date($post->publishedAt)) : '' ?>
                <?php if (post_categories($post) !== []): ?>
                    <span class="lp-post__categories">&middot; Filed under <?php the_post_categories($post); ?></span>
                <?php endif; ?>
            </p>
            <?php if (!$lpThumbnailAboveTitle && has_post_thumbnail($post)): ?>
                <div class="lp-post__thumbnail lp-gallery">
                    <?php the_post_thumbnail_lightbox($post, size: 'large'); ?>
                </div>
            <?php endif; ?>
            <div class="lp-post__content"><?php the_content($post); ?></div>
            <?php if (post_has_tags($post)): ?>
                <ul class="lp-post__tags">
                    <?php foreach (get_the_tags($post) as $postTag): ?>
                        <li class="lp-post__tags-item"><a href="<?= esc_url(tag_permalink($postTag)) ?>"><?= esc_html($postTag->name) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php $postEditUrl = edit_post_link($post); ?>
            <?php if ($postEditUrl !== null): ?>
                <p class="lp-post__edit-link"><a href="<?= esc_url($postEditUrl) ?>">Edit this post</a></p>
            <?php endif; ?>
        </article>
        <?php the_related_posts($post); ?>
        <?php comments_template($comment_data); ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
