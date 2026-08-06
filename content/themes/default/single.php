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
 * @var array<int, array{comment: \LumoraPress\Models\Comment, children: array<mixed>}> $comment_tree
 * @var bool $comments_open
 * @var int $comment_count
 * @var \LumoraPress\Models\User|null $current_user
 * @var array{total: int, page: int, perPage: int, totalPages: int} $comment_pagination
 * @var bool $comment_pagination_enabled
 * @var int $comment_max_nesting_level
 * @var bool $avatars_enabled
 * @var string $avatar_rating
 * @var string $avatar_default
 * @var bool $comment_cookies_consent_enabled
 * @var string $comment_saved_guest_name
 * @var string $comment_saved_guest_email
 * @var string $comment_saved_guest_url
 * @var bool $comment_author_name_required
 * @var bool $comment_author_email_required
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
                    <?php the_post_thumbnail_lightbox($post, size: 'large', largeSize: 'large'); ?>
                </div>
            <?php endif; ?>
            <div class="lp-post__content"><?= render_content($post->content, $post->contentFormat) ?></div>
        </article>
        <?php
        comments_template([
            'post' => $post,
            'comment_tree' => $comment_tree,
            'comments_open' => $comments_open,
            'comment_count' => $comment_count,
            'current_user' => $current_user,
            'comment_pagination' => $comment_pagination,
            'comment_pagination_enabled' => $comment_pagination_enabled,
            'comment_max_nesting_level' => $comment_max_nesting_level,
            'avatars_enabled' => $avatars_enabled,
            'avatar_rating' => $avatar_rating,
            'avatar_default' => $avatar_default,
            'comment_cookies_consent_enabled' => $comment_cookies_consent_enabled,
            'comment_saved_guest_name' => $comment_saved_guest_name,
            'comment_saved_guest_email' => $comment_saved_guest_email,
            'comment_saved_guest_url' => $comment_saved_guest_url,
            'comment_author_name_required' => $comment_author_name_required,
            'comment_author_email_required' => $comment_author_email_required,
        ]);
        ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
