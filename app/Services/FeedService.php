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
use LumoraPress\Models\Comment;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use LumoraPress\Models\Tag;
use LumoraPress\Models\User;

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
        private readonly CommentService $comments,
        private readonly PageService $pages,
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
     * @return array{title: string, description: string}
     */
    public function tagChannel(Tag $tag): array
    {
        $siteName = (string) $this->config->option('site_name', 'Lumora Press');
        $channel = [
            'title' => $siteName . ' » ' . $tag->name,
            'description' => $tag->description,
        ];

        return $this->hooks->applyFilters('feed_tag_channel', $channel, $tag);
    }

    /**
     * Items for a single tag's feed, newest first — same shape as items(),
     * scoped through PostService::paginateByTag().
     *
     * @return array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}>
     */
    public function tagItems(Tag $tag): array
    {
        $limit = $this->itemLimit();
        $fullContent = $this->config->option('feed_full_content', '1') !== '0';
        $posts = $this->posts->paginateByTag($tag->id, 1, $limit)['posts'];

        return array_map(
            fn (Post $post): array => $this->buildItem($post, $fullContent),
            $posts,
        );
    }

    /**
     * @return array{title: string, description: string}
     */
    public function authorChannel(User $author): array
    {
        $siteName = (string) $this->config->option('site_name', 'Lumora Press');
        $channel = [
            'title' => $siteName . ' » ' . $author->displayName,
            'description' => '',
        ];

        return $this->hooks->applyFilters('feed_author_channel', $channel, $author);
    }

    /**
     * Items for a single author's feed, newest first — same shape as
     * items(), scoped through PostService::paginateByAuthor().
     *
     * @return array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}>
     */
    public function authorItems(User $author): array
    {
        $limit = $this->itemLimit();
        $fullContent = $this->config->option('feed_full_content', '1') !== '0';
        $posts = $this->posts->paginateByAuthor($author->id, 1, $limit)['posts'];

        return array_map(
            fn (Post $post): array => $this->buildItem($post, $fullContent),
            $posts,
        );
    }

    /**
     * @return array{title: string, description: string}
     */
    public function pagesChannel(): array
    {
        $siteName = (string) $this->config->option('site_name', 'Lumora Press');
        $channel = [
            'title' => $siteName . ' » Pages',
            'description' => (string) $this->config->option('feed_description', ''),
        ];

        return $this->hooks->applyFilters('feed_pages_channel', $channel);
    }

    /**
     * Published pages visible to public site visitors, most-recently
     * published first — via PageService::paginatePublishedByDate(), not
     * paginatePublished() itself, since the latter orders alphabetically
     * for its own callers (the public API listing, the sitemap) rather
     * than by date, which is what a feed reader expects.
     *
     * @return array<int, array{page: Page, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}>
     */
    public function pagesItems(): array
    {
        $limit = $this->itemLimit();
        $fullContent = $this->config->option('feed_full_content', '1') !== '0';
        $pages = $this->pages->paginatePublishedByDate(1, $limit)['pages'];

        return array_map(
            fn (Page $page): array => $this->buildPageItem($page, $fullContent),
            $pages,
        );
    }

    /**
     * @return array{title: string, description: string}
     */
    public function commentsChannel(): array
    {
        $siteName = (string) $this->config->option('site_name', 'Lumora Press');
        $channel = [
            'title' => $siteName . ' » Comments',
            'description' => (string) $this->config->option('feed_description', ''),
        ];

        return $this->hooks->applyFilters('feed_comments_channel', $channel);
    }

    /**
     * The most recent approved comments site-wide, newest first, via
     * CommentService::recentApproved() — the same query the Recent
     * Comments widget uses, so a comment awaiting moderation or on a
     * trashed/private post never surfaces here. contentTitle/contentSlug/
     * contentType pass straight through from recentApproved() rather than
     * being resolved into a link here — SiteController::commentsFeed()
     * does that, the same way it (not FeedService) resolves a Post into a
     * permalink for the site-wide post feed.
     *
     * @return array<int, array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string, description: string, content: string}>
     */
    public function commentsItems(): array
    {
        $entries = $this->comments->recentApproved($this->itemLimit());

        return array_map(
            fn (array $entry): array => [...$this->buildCommentItem($entry['comment']), ...[
                'contentTitle' => $entry['contentTitle'],
                'contentSlug' => $entry['contentSlug'],
                'contentType' => $entry['contentType'],
            ]],
            $entries,
        );
    }

    /**
     * @return array{title: string, description: string}
     */
    public function postCommentsChannel(Post $post): array
    {
        $siteName = (string) $this->config->option('site_name', 'Lumora Press');
        $channel = [
            'title' => $siteName . ' » Comments on ' . $post->title,
            'description' => '',
        ];

        return $this->hooks->applyFilters('feed_post_comments_channel', $channel, $post);
    }

    /**
     * The individual comment thread for a single post — every approved
     * comment (parent and reply alike, flattened rather than nested,
     * since a feed item has no concept of nesting), oldest first,
     * matching the thread's own on-page display order.
     *
     * @return array<int, array{comment: Comment, description: string, content: string}>
     */
    public function postCommentsItems(Post $post): array
    {
        $limit = $this->itemLimit();
        $pagination = $this->comments->paginateForPost($post->id, 1, $limit, 'asc', false);

        return array_map(
            fn (array $entry): array => $this->buildCommentItem($entry['comment']),
            $pagination['comments'],
        );
    }

    /**
     * @return array{comment: Comment, description: string, content: string}
     */
    private function buildCommentItem(Comment $comment): array
    {
        $item = [
            'comment' => $comment,
            'description' => $comment->content,
            'content' => format_comment_content($comment->content),
        ];

        return $this->hooks->applyFilters('feed_comment_item', $item, $comment);
    }

    /**
     * @return array{page: Page, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}
     */
    private function buildPageItem(Page $page, bool $fullContent): array
    {
        $author = $this->users->findById($page->authorId);
        $thumbnailUrl = null;
        $thumbnailType = null;
        $thumbnailLength = null;

        if ($this->config->option('feed_featured_images', '1') !== '0' && $page->featuredImageId !== null) {
            $media = $this->media->find($page->featuredImageId);

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
            'page' => $page,
            'authorName' => $author?->displayName,
            'description' => $page->excerpt !== '' ? $page->excerpt : make_excerpt($this->content->toPlainText($page->content, $page->contentFormat)),
            'content' => $fullContent ? $this->content->render($page->content, $page->contentFormat) : null,
            'thumbnailUrl' => $thumbnailUrl,
            'thumbnailType' => $thumbnailType,
            'thumbnailLength' => $thumbnailLength,
        ];

        return $this->hooks->applyFilters('feed_page_item', $item, $page);
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
