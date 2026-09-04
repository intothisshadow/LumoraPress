<?php

/**
 * The Post domain model.
 *
 * @package LumoraPress
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

final class Post
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly PostStatus $status,
        public readonly int $authorId,
        public readonly ?int $featuredImageId,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly bool $commentsOpen = true,
        public readonly ContentFormat $contentFormat = ContentFormat::Plain,
        public readonly ?DateTimeImmutable $trashedAt = null,
        /** @var array{x: int, y: int, width: int, height: int}|null */
        public readonly ?array $featuredImageCrop = null,
        /**
         * SEO overrides — null means "use $title"/"derive from $excerpt or
         * $content" respectively; see the_seo_title()/the_seo_description()
         * in include/helpers.php for that fallback chain.
         */
        public readonly ?string $metaTitle = null,
        public readonly ?string $metaDescription = null,
        public readonly PostVisibility $visibility = PostVisibility::Public,
        public readonly bool $isSticky = false,
        public readonly ?DateTimeImmutable $unpublishAt = null,
    ) {
    }

    /**
     * Visible to an anonymous visitor: published (or due), not past its
     * unpublish_at time, and not Private. See isVisibleToViewer() for the
     * logged-in-staff version.
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->visibility !== PostVisibility::Public) {
            return false;
        }

        return $this->isPublishedOrDue() && !$this->isPastUnpublishTime();
    }

    /**
     * Same as isPubliclyVisible(), but a Private post also counts as
     * visible when $canViewPrivate is true — the caller decides that,
     * since Post has no notion of "the current viewer."
     */
    public function isVisibleToViewer(bool $canViewPrivate): bool
    {
        if (!$this->isPublishedOrDue() || $this->isPastUnpublishTime()) {
            return false;
        }

        return $this->visibility === PostVisibility::Public || $canViewPrivate;
    }

    private function isPublishedOrDue(): bool
    {
        if ($this->status === PostStatus::Published) {
            return true;
        }

        return $this->status === PostStatus::Scheduled
            && $this->publishedAt !== null
            && $this->publishedAt <= new DateTimeImmutable();
    }

    private function isPastUnpublishTime(): bool
    {
        return $this->unpublishAt !== null && $this->unpublishAt <= new DateTimeImmutable();
    }
}
