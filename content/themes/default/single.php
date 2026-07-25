<?php
/**
 * @var \LumoraPress\Models\Post $post
 * @var array<int, array{comment: \LumoraPress\Models\Comment, children: array<mixed>}> $comment_tree
 * @var bool $comments_open
 * @var int $comment_count
 * @var \LumoraPress\Models\User|null $current_user
 */
get_header(['post' => $post]);
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <article class="lp-post">
            <h1 class="lp-post__title"><?= esc_html($post->title) ?></h1>
            <p class="lp-post__meta">
                <?= $post->publishedAt !== null ? esc_html($post->publishedAt->format('F j, Y')) : '' ?>
            </p>
            <?php if (has_post_thumbnail($post)): ?>
                <div class="lp-post__thumbnail lp-gallery">
                    <?php the_post_thumbnail_lightbox($post, size: 'large', largeSize: 'large'); ?>
                </div>
            <?php endif; ?>
            <div class="lp-post__content"><?= nl2br(esc_html($post->content)) ?></div>
        </article>
        <?php comments_template(['post' => $post, 'comment_tree' => $comment_tree, 'comments_open' => $comments_open, 'comment_count' => $comment_count, 'current_user' => $current_user]); ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
