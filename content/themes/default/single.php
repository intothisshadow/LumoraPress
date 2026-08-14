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
            <h1 class="lp-post__title"><?= esc_html($post->title) ?></h1>
            <p class="lp-post__meta">
                <?php the_author_link($post); ?>
                <?= $post->publishedAt !== null ? esc_html(the_date($post->publishedAt)) : '' ?>
            </p>
            <?php if (has_post_thumbnail($post)): ?>
                <div class="lp-post__thumbnail lp-gallery">
                    <?php the_post_thumbnail_lightbox($post, size: 'large'); ?>
                </div>
            <?php endif; ?>
            <div class="lp-post__content"><?= render_content($post->content, $post->contentFormat) ?></div>
        </article>
        <?php comments_template($comment_data); ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
