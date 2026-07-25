<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\Post;

/**
 * Builds the data behind the site-wide RSS/Atom feed (LP-013) — channel
 * metadata plus the list of feed items. Deliberately returns plain arrays
 * of raw data (post entity, author name, description, optional full
 * content) rather than XML; SiteController is responsible for formatting
 * that data into RSS 2.0/Atom 1.0 markup, same separation PostService/
 * ThemeRenderer already have for HTML.
 *
 * Takes HookManager via constructor injection rather than calling the
 * global apply_filters() bridge directly, matching UpdateService's
 * convention (see HookManager\Hooks's own docblock: "Internal services
 * receive HookManager via constructor injection instead of using this
 * bridge directly"). MediaService/ThumbnailService (LP-040, for optional
 * feed item thumbnails/enclosures) are injected the same way, rather than
 * going through the FeaturedImages static bridge that theme templates
 * use — that bridge exists for procedural template helpers with no route
 * to the Kernel, which doesn't apply here.
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
     * not-yet-due scheduled posts (LP-013's "respect private/unpublished
     * content" requirement).
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
     * @return array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}
     */
    private function buildItem(Post $post, bool $fullContent): array
    {
        $author = $this->users->findById($post->authorId);
        $thumbnailUrl = null;
        $thumbnailType = null;
        $thumbnailLength = null;

        // Optional per the ticket's own wording (LP-040) — gated by
        // feed_featured_images, default on.
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
            'description' => $post->excerpt !== '' ? $post->excerpt : make_excerpt($post->content),
            'content' => $fullContent ? $post->content : null,
            'thumbnailUrl' => $thumbnailUrl,
            'thumbnailType' => $thumbnailType,
            'thumbnailLength' => $thumbnailLength,
        ];

        return $this->hooks->applyFilters('feed_item', $item, $post);
    }
}
