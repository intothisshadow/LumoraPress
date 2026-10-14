<?php

/**
 * Tells the Notifications screen when a notification's destination can no longer be opened.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Notifications\NotificationTypes;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\User;

/**
 * A notification can outlive what it points at: the post may be deleted, or the viewer's role
 * may have changed since. Explaining that on the Notifications screen beats sending someone to
 * a 404 or a "forbidden" page.
 */
final class NotificationTargetChecker
{
    public function __construct(
        private readonly PostService $posts,
        private readonly PageService $pages,
    ) {
    }

    /**
     * @param array<string, mixed> $notification a row from NotificationService
     * @return ?string why the destination is unusable, or null when it can be opened (or there is none to check)
     */
    public function explain(array $notification, User $viewer): ?string
    {
        $type = NotificationTypes::get((string) $notification['type']);
        $url = (string) $notification['url'];

        if ($type === null || $url === '') {
            return null;
        }

        // Only admin links are permission-gated; a public permalink opens for anyone.
        $isAdminLink = str_contains((string) parse_url($url, PHP_URL_PATH), '/admin/') || str_ends_with((string) parse_url($url, PHP_URL_PATH), '/admin');

        if ($isAdminLink && $type['capability'] !== null && !$viewer->can($type['capability'])) {
            return __('You no longer have access to the page this notification points to.');
        }

        if (!$isAdminLink || $type['target'] === null) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = (int) ($query['id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        return $type['target'] === 'page_edit' ? $this->explainPage($id, $viewer) : $this->explainPost($id, $viewer);
    }

    private function explainPost(int $id, User $viewer): ?string
    {
        $post = $this->posts->findById($id);

        if ($post === null) {
            return __('This post has been deleted.');
        }

        if ($post->status === PostStatus::Trashed) {
            return __('This post is in the Trash.');
        }

        return $post->authorId === $viewer->id || $viewer->can('edit_others_posts') ? null : __('You can no longer edit this post.');
    }

    private function explainPage(int $id, User $viewer): ?string
    {
        $page = $this->pages->findById($id);

        if ($page === null) {
            return __('This page has been deleted.');
        }

        if ($page->status === PageStatus::Trashed) {
            return __('This page is in the Trash.');
        }

        return $page->authorId === $viewer->id || $viewer->can('edit_others_posts') ? null : __('You can no longer edit this page.');
    }
}
