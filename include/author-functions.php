<?php

declare(strict_types=1);

use LumoraPress\Core\Theme\Authors;
use LumoraPress\Models\Post;

/**
 * Author display/link helpers (LP-008) — reads UserService via the
 * Authors static bridge, mirroring include/media-functions.php's
 * FeaturedImages-backed helpers.
 */

if (!function_exists('author_name')) {
    function author_name(Post $post): string
    {
        $author = Authors::users()->findById($post->authorId);

        return $author?->displayName ?? 'Unknown';
    }
}

if (!function_exists('author_url')) {
    /**
     * The author's public archive URL — null if the author account no
     * longer exists (a post's author_id can outlive the user row being
     * deleted, since PostService::delete() has no such cascade).
     */
    function author_url(Post $post): ?string
    {
        $author = Authors::users()->findById($post->authorId);

        return $author !== null ? site_url('author/' . Authors::users()->authorSlug($author)) : null;
    }
}

if (!function_exists('the_author_link')) {
    /**
     * Echoes the author's display name, linked to their archive when one
     * is resolvable — falls back to plain text (no link) if the author
     * account no longer exists.
     */
    function the_author_link(Post $post): void
    {
        $url = author_url($post);
        $name = esc_html(author_name($post));

        if ($url !== null) {
            echo '<a href="' . esc_url($url) . '">' . $name . '</a>';

            return;
        }

        echo $name;
    }
}
