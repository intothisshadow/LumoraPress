<?php

/**
 * Default theme template for category/tag/date/author archive listings.
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
/** @var string|null $page_title */
/** @var array<int, \LumoraPress\Models\Post> $posts */
/** @var array{page: int, totalPages: int}|null $pagination */
/** @var string|null $archive_description */
get_header();
$posts ??= [];
$archive_description ??= null;
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title"><?= esc_html($page_title ?? 'Archive') ?></h1>

        <?php if ($archive_description !== null && $archive_description !== ''): ?>
            <p class="lp-archive__description"><?= esc_html($archive_description) ?></p>
        <?php endif; ?>

        <?php if ($posts === []): ?>
            <p class="lp-empty-state">No content found in this archive yet.</p>
        <?php else: ?>
            <div class="lp-post-list lp-gallery">
                <?php foreach ($posts as $post): ?>
                    <article class="lp-post-list__item">
                        <?php if (theme_option('show_featured_image_in_listings') !== '0' && has_post_thumbnail($post)): ?>
                            <div class="lp-post-list__thumbnail">
                                <?php the_post_thumbnail_lightbox($post, 'small'); ?>
                            </div>
                        <?php endif; ?>
                        <div class="lp-post-list__body">
                            <h2 class="lp-post-list__title">
                                <a href="<?= esc_url(post_permalink($post)) ?>"><?= esc_html($post->title) ?></a>
                            </h2>
                            <p class="lp-post-list__meta">
                                <?php the_author_link($post); ?>
                                <?= $post->publishedAt !== null ? esc_html(the_date($post->publishedAt)) : '' ?>
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

            <?php if (isset($pagination)) {
                render_pagination($pagination);
            } ?>
        <?php endif; ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
