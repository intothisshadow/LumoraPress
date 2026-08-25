<?php

/**
 * Creates, skips, or overwrites a Post from an ImportedPost DTO, wiring categories/tags/custom fields and recording provenance (LPP-004/LPP-005 Phase 1).
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Import;

use LumoraPress\Models\Post;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\PostService;
use LumoraPress\Services\TagService;
use RuntimeException;

/**
 * A thin wrapper over PostService/CategoryService/TagService, built so
 * both a future WXR parser (LPP-004) and the Dummy Content generator
 * (LPP-005) can turn an ImportedPost into a real row through the same
 * path rather than duplicating PostService::create()'s call shape and
 * the category/tag/meta follow-up calls it doesn't accept inline.
 *
 * Ordering contract (see ImportedPost's own docblock too): the caller
 * must resolve $data->authorId and $data->featuredImageId to real local
 * ids *before* calling import() — this class never creates a user or
 * media item itself, only a post.
 */
final class PostImporter
{
    public function __construct(
        private readonly PostService $posts,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly ContentImportRegistry $registry,
    ) {
    }

    /**
     * $existingContentMode is null on every call site except a
     * deliberate re-import against a source already imported once
     * before (see ExistingContentMode's own docblock) — null always
     * creates a new post exactly as before, regardless of whether
     * $data->externalId happens to match a previous import; only a
     * non-null mode ever looks that match up at all.
     */
    public function import(string $batchId, string $source, ImportedPost $data, ?ExistingContentMode $existingContentMode = null): Post
    {
        $existingId = $existingContentMode !== null && $data->externalId !== null
            ? $this->registry->existingLocalId($source, 'post', $data->externalId)
            : null;

        if ($existingId !== null) {
            $existing = $this->posts->findById($existingId);

            if ($existing === null) {
                throw new RuntimeException("Post external id \"{$data->externalId}\" was previously imported as #{$existingId}, but that post no longer exists.");
            }

            if ($existingContentMode === ExistingContentMode::Skip) {
                return $existing;
            }

            $post = $this->posts->update(
                id: $existingId,
                title: $data->title,
                content: $data->content,
                excerpt: $data->excerpt,
                status: $data->status,
                publishedAt: $data->publishedAt,
                featuredImageId: $data->featuredImageId,
                slug: $data->slug,
                commentsOpen: $data->commentsOpen,
                contentFormat: $data->contentFormat,
                featuredImageCrop: $data->featuredImageCrop,
                visibility: $data->visibility,
                isSticky: $data->isSticky,
                unpublishAt: $data->unpublishAt,
            );

            $this->assignTaxonomyAndMeta($post->id, $data);

            return $post;
        }

        $post = $this->posts->create(
            title: $data->title,
            content: $data->content,
            excerpt: $data->excerpt,
            authorId: $data->authorId,
            status: $data->status,
            publishedAt: $data->publishedAt,
            featuredImageId: $data->featuredImageId,
            slug: $data->slug,
            commentsOpen: $data->commentsOpen,
            contentFormat: $data->contentFormat,
            featuredImageCrop: $data->featuredImageCrop,
            visibility: $data->visibility,
            isSticky: $data->isSticky,
            unpublishAt: $data->unpublishAt,
        );

        $this->assignTaxonomyAndMeta($post->id, $data);

        $this->registry->record($batchId, $source, 'post', $post->id, $data->externalId);

        return $post;
    }

    /**
     * Called unconditionally, even with empty categories/tags/meta —
     * CategoryService::assignToPost()/TagService::assignToPost()/
     * PostService::replaceMetaForPost() all delete-then-reinsert, so an
     * empty array is a real, meaningful "clear whatever was there
     * before" on an Overwrite (a source category/tag/custom field that
     * was removed since the last import must actually disappear here
     * too, not linger from the row's previous import) and a harmless
     * no-op on a fresh Create.
     */
    private function assignTaxonomyAndMeta(int $postId, ImportedPost $data): void
    {
        $categoryIds = array_map(
            fn (string $name): int => $this->categories->findOrCreateByName($name)->id,
            $data->categories,
        );
        $this->categories->assignToPost($postId, $categoryIds);
        $this->tags->assignToPost($postId, $data->tags);
        $this->posts->replaceMetaForPost($postId, $data->meta);
    }
}
