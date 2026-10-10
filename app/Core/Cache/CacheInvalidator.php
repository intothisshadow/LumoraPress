<?php

/**
 * Decides how much of the cache a content change needs to purge.
 *
 * @package LumoraPress
 * @subpackage Cache
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.23.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Cache;

use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;

/**
 * Purges nothing when a change can't alter what an anonymous visitor sees
 * (a draft save, a spam comment), a few tags when the change is provably
 * confined, and everything otherwise. Widgets, menus and options put the
 * same content on every cached page, so "everything" stays the default
 * whenever confinement can't be shown.
 */
final class CacheInvalidator
{
    /**
     * Widget types whose output never includes comment data. A widget
     * outside this list (Recent Comments, Statistics, a plugin's own) may
     * show comments on any page, so it rules out a confined purge.
     */
    private const COMMENT_INDEPENDENT_WIDGETS = [
        'text', 'custom_html', 'search', 'nav_menu', 'pages', 'categories',
        'recent_posts', 'archives', 'tag_cloud', 'meta', 'social_links',
    ];

    public function __construct(
        private readonly CacheManager $cache,
        private readonly PressConfig $config,
    ) {
    }

    public function postSaved(Post $post, ?Post $previous = null): void
    {
        if ($post->isPubliclyVisible() || ($previous?->isPubliclyVisible() ?? false)) {
            $this->cache->purgeAll();
        }
    }

    public function pageSaved(Page $page, ?Page $previous = null): void
    {
        if ($page->isPubliclyVisible() || ($previous?->isPubliclyVisible() ?? false)) {
            $this->cache->purgeAll();
        }
    }

    public function commentPosted(Comment $comment): void
    {
        if ($comment->status === CommentStatus::Approved) {
            $this->commentVisibilityChanged($comment);
        }
    }

    public function commentStatusChanged(Comment $comment, ?CommentStatus $previousStatus = null): void
    {
        if ($comment->status === CommentStatus::Approved || $previousStatus === CommentStatus::Approved) {
            $this->commentVisibilityChanged($comment);
        }
    }

    /**
     * Listings show a post's comment count and a page shows its own
     * comments; a single post's page is never cached, so it needs no tag.
     */
    private function commentVisibilityChanged(Comment $comment): void
    {
        if (!$this->onlyCommentIndependentWidgetsPlaced()) {
            $this->cache->purgeAll();

            return;
        }

        $tags = ['posts'];

        if ($comment->pageId !== null) {
            $tags[] = 'page_' . $comment->pageId;
        }

        $this->cache->purgeTags($tags);
    }

    private function onlyCommentIndependentWidgetsPlaced(): bool
    {
        $decoded = json_decode((string) $this->config->option('widgets_config', '{}'), true);

        foreach (is_array($decoded) ? $decoded : [] as $sidebarId => $sidebarWidgets) {
            // Widgets parked in the inactive area are never rendered.
            if ($sidebarId === WidgetManager::INACTIVE_SIDEBAR_ID) {
                continue;
            }

            foreach (is_array($sidebarWidgets) ? $sidebarWidgets : [] as $widget) {
                $type = is_array($widget) ? ($widget['type'] ?? null) : null;

                if (!is_string($type) || !in_array($type, self::COMMENT_INDEPENDENT_WIDGETS, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
