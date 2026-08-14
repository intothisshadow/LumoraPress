<?php

/**
 * Creates a Post from an ImportedPost DTO, wiring categories/tags/custom fields and recording provenance (LPP-004/LPP-005 Phase 1).
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

    public function import(string $batchId, string $source, ImportedPost $data): Post
    {
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

        if ($data->categories !== []) {
            $categoryIds = array_map(
                fn (string $name): int => $this->categories->findOrCreateByName($name)->id,
                $data->categories,
            );
            $this->categories->assignToPost($post->id, $categoryIds);
        }

        if ($data->tags !== []) {
            $this->tags->assignToPost($post->id, $data->tags);
        }

        if ($data->meta !== []) {
            $this->posts->replaceMetaForPost($post->id, $data->meta);
        }

        $this->registry->record($batchId, $source, 'post', $post->id, $data->externalId);

        return $post;
    }
}
