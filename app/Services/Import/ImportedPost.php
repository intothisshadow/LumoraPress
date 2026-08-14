<?php

/**
 * A plain data holder describing one post to create via PostImporter (LPP-004/LPP-005 Phase 1), independent of where the data came from.
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

use DateTimeImmutable;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;

/**
 * $authorId/$featuredImageId are already-resolved local ids — the caller
 * (a WXR parser or the dummy-content generator) creates/imports the
 * author and any featured image first (see PostImporter's own ordering
 * contract) and passes the real ids here, not an external reference.
 * $categories/$tags are plain name strings, resolved to ids/rows inside
 * PostImporter — matching CategoryService::findOrCreateByName()/
 * TagService::assignToPost()'s own name-based signatures.
 */
final class ImportedPost
{
    /**
     * @param array<int, string> $categories Category names, resolved via CategoryService::findOrCreateByName().
     * @param array<int, string> $tags Tag names, passed directly to TagService::assignToPost().
     * @param array<int, array{key: string, value: string}> $meta Custom fields, passed to PostService::replaceMetaForPost().
     * @param array{x: int, y: int, width: int, height: int}|null $featuredImageCrop
     */
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly int $authorId,
        public readonly PostStatus $status,
        public readonly ?DateTimeImmutable $publishedAt = null,
        public readonly ?int $featuredImageId = null,
        public readonly ?string $slug = null,
        public readonly bool $commentsOpen = true,
        public readonly ContentFormat $contentFormat = ContentFormat::Markdown,
        public readonly ?array $featuredImageCrop = null,
        public readonly PostVisibility $visibility = PostVisibility::Public,
        public readonly bool $isSticky = false,
        public readonly ?DateTimeImmutable $unpublishAt = null,
        public readonly array $categories = [],
        public readonly array $tags = [],
        public readonly array $meta = [],
        /**
         * The source's own identifier for this post (e.g. a WXR post's
         * original WordPress <wp:post_id>) — recorded in
         * ContentImportRegistry so a later step (a comment's parent post,
         * an internal link rewrite) can resolve it back to the real local
         * id. Null for sources with no such concept (dummy content).
         */
        public readonly ?string $externalId = null,
    ) {
    }
}
