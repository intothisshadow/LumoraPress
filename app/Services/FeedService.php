<?php

/**
 * Builds the data behind the site-wide RSS/Atom feed.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\Category;
use LumoraPress\Models\Post;

/**
 * Builds the data behind the site-wide RSS/Atom feed — channel metadata plus feed items.
 * Returns plain arrays of raw data rather than XML; SiteController formats that into RSS
 * 2.0/Atom 1.0 markup, the same separation PostService/ThemeRenderer have for HTML.
 *
 * Takes HookManager via constructor injection, matching UpdateService's convention, rather
 * than the global apply_filters() bridge. MediaService/ThumbnailService are injected the
 * same way, not via the FeaturedImages static bridge theme templates use for that purpose.
 */
final class FeedService
{
    private const DEFAULT_ITEM_LIMIT = 10;

    private const MAX_ITEM_LIMIT = 100;

    public function __construct(
        private readonly PostService $posts,
        private readonly UserService $users,
        private readonly PressConfig $config,
        private readonly HookManager $hooks,
        private readonly MediaService $media,
        private readonly ThumbnailService $thumbnails,
        private readonly ContentRenderer $content,
    ) {
    }

    /**
     * @return array{title: string, description: string}
     */
    public function channel(): array
    {
        $channel = [
            'title' => (string) $this->config->option('site_name', 'Lumora Press'),
            'description' => (string) $this->config->option('feed_description', ''),
        ];

        return $this->hooks->applyFilters('feed_channel', $channel);
    }

    /**
     * Items visible to public site visitors, newest first — reuses
     * PostService::paginatePublished(), which already excludes drafts and
     * not-yet-due scheduled posts.
     *
     * @return array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}>
     */
    public function items(): array
    {
        $limit = $this->itemLimit();
        $fullContent = $this->config->option('feed_full_content', '1') !== '0';
        $posts = $this->posts->paginatePublished(1, $limit)['posts'];

        return array_map(
            fn (Post $post): array => $this->buildItem($post, $fullContent),
            $posts,
        );
    }

    public function itemLimit(): int
    {
        $configured = (int) $this->config->option('feed_item_limit', (string) self::DEFAULT_ITEM_LIMIT);

        return max(1, min(self::MAX_ITEM_LIMIT, $configured));
    }

    /**
     * @return array{title: string, description: string}
     */
    public function categoryChannel(Category $category): array
    {
        $siteName = (string) $this->config->option('site_name', 'Lumora Press');
        $channel = [
            'title' => $siteName . ' » ' . $category->name,
            'description' => $category->description,
        ];

        return $this->hooks->applyFilters('feed_category_channel', $channel, $category);
    }

    /**
     * Items for a single category's feed, newest first — same shape as
     * items(), scoped through PostService::paginateByCategory() (which
     * already excludes drafts/not-yet-due scheduled posts, same as
     * paginatePublished() does for the site-wide feed).
     *
     * @return array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}>
     */
    public function categoryItems(Category $category): array
    {
        $limit = $this->itemLimit();
        $fullContent = $this->config->option('feed_full_content', '1') !== '0';
        $posts = $this->posts->paginateByCategory($category->id, 1, $limit)['posts'];

        return array_map(
            fn (Post $post): array => $this->buildItem($post, $fullContent),
            $posts,
        );
    }

    /**
     * @return array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}
     */
    private function buildItem(Post $post, bool $fullContent): array
    {
        $author = $this->users->findById($post->authorId);
        $thumbnailUrl = null;
        $thumbnailType = null;
        $thumbnailLength = null;

        // Gated by feed_featured_images, default on.
        if ($this->config->option('feed_featured_images', '1') !== '0' && $post->featuredImageId !== null) {
            $media = $this->media->find($post->featuredImageId);

            if ($media !== null) {
                $thumbnailUrl = $this->thumbnails->url($media, 'medium') ?? $this->media->url($media);
                $thumbnailUrl = str_starts_with($thumbnailUrl, 'http://') || str_starts_with($thumbnailUrl, 'https://')
                    ? $thumbnailUrl
                    : SiteUrl::get() . '/' . ltrim($thumbnailUrl, '/');
                $thumbnailType = (string) $media['mime_type'];
                $thumbnailLength = (int) $media['file_size'];
            }
        }

        $item = [
            'post' => $post,
            'authorName' => $author?->displayName,
            'description' => $post->excerpt !== '' ? $post->excerpt : make_excerpt($this->content->toPlainText($post->content, $post->contentFormat)),
            'content' => $fullContent ? $this->content->render($post->content, $post->contentFormat) : null,
            'thumbnailUrl' => $thumbnailUrl,
            'thumbnailType' => $thumbnailType,
            'thumbnailLength' => $thumbnailLength,
        ];

        return $this->hooks->applyFilters('feed_item', $item, $post);
    }
}
