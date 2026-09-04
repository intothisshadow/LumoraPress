<?php

/**
 * Author display/link helpers (LP-008) for theme templates, backed by UserService via the Authors static bridge.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Core\Theme\Authors;
use LumoraPress\Models\Post;

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

        return $author !== null ? preview_theme_link(site_url('author/' . Authors::users()->authorSlug($author))) : null;
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
