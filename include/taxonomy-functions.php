<?php

/**
 * A post's tags and related-posts theme helpers (LP-011), backed by TagService/PostService via the ActiveTags/ActivePosts static bridges.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */

declare(strict_types=1);

use LumoraPress\Core\Theme\ActivePosts;
use LumoraPress\Core\Theme\ActiveTags;
use LumoraPress\Models\Post;
use LumoraPress\Models\Tag;

/**
 * A post's own tags, and the posts related to it by shared tags — reads
 * TagService/PostService via the ActiveTags/ActivePosts static bridges.
 */

if (!function_exists('get_the_tags')) {
    /**
     * The Tags $post is assigned to, alphabetical. Empty array for a
     * tagless post, never null.
     *
     * @return array<int, Tag>
     */
    function get_the_tags(Post $post): array
    {
        return ActiveTags::tags()->tagsForPost($post->id);
    }
}

if (!function_exists('post_has_tags')) {
    function post_has_tags(Post $post): bool
    {
        return get_the_tags($post) !== [];
    }
}

if (!function_exists('the_tags')) {
    /**
     * get_the_tags(), rendered as a $sep-joined list of links wrapped in
     * $before/$after. Outputs nothing for a tagless post.
     */
    function the_tags(Post $post, string $before = '', string $sep = ', ', string $after = ''): void
    {
        $links = array_map(
            static fn (Tag $tag): string => '<a href="' . esc_url(tag_permalink($tag)) . '">' . esc_html($tag->name) . '</a>',
            get_the_tags($post),
        );

        if ($links === []) {
            return;
        }

        echo $before . implode($sep, $links) . $after;
    }
}

if (!function_exists('get_related_posts')) {
    /**
     * Up to $limit other publicly visible posts that share at least one
     * tag with $post, most-shared-tags-first. Empty array for a tagless
     * post.
     *
     * @return array<int, Post>
     */
    function get_related_posts(Post $post, int $limit = 5): array
    {
        $tagIds = array_map(static fn (Tag $tag): int => $tag->id, get_the_tags($post));

        return ActivePosts::posts()->relatedByTags($post->id, $tagIds, $limit);
    }
}

if (!function_exists('the_related_posts')) {
    /**
     * get_related_posts(), rendered as a titled `.lp-related-posts` list
     * of linked titles. Outputs nothing when there are no related posts.
     */
    function the_related_posts(Post $post, int $limit = 5, string $title = 'Related Posts'): void
    {
        $related = get_related_posts($post, $limit);

        if ($related === []) {
            return;
        }

        echo '<section class="lp-related-posts">';
        echo '<h2 class="lp-related-posts__title">' . esc_html($title) . '</h2>';
        echo '<ul class="lp-related-posts__list">';

        foreach ($related as $relatedPost) {
            echo '<li class="lp-related-posts__item">';

            if (theme_option('show_featured_image_in_listings') !== '0' && has_post_thumbnail($relatedPost)) {
                echo '<div class="lp-related-posts__thumbnail">';
                the_post_thumbnail($relatedPost, 'small');
                echo '</div>';
            }

            echo '<a class="lp-related-posts__link" href="' . esc_url(post_permalink($relatedPost)) . '">' . esc_html($relatedPost->title) . '</a>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</section>';
    }
}
