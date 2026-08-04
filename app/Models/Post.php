<?php

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
         * LP-022 SEO overrides — null means "use $title"/"derive from
         * $excerpt or $content" respectively; see the_seo_title()/
         * the_seo_description() in include/helpers.php for that fallback
         * chain.
         */
        public readonly ?string $metaTitle = null,
        public readonly ?string $metaDescription = null,
        public readonly PostVisibility $visibility = PostVisibility::Public,
        public readonly bool $isSticky = false,
        public readonly ?DateTimeImmutable $unpublishAt = null,
    ) {
    }

    /**
     * Whether the post is currently visible to an anonymous/public site
     * visitor: published outright (or scheduled with a published_at time
     * that has already passed), not yet past its unpublish_at time (if
     * any — LP-008's "Schedule unpublishing"), and not Private (LP-008's
     * "Private posts" — see isVisibleToViewer() for the version that
     * additionally allows a permitted logged-in user to see a Private
     * post).
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
     * visible when $canViewPrivate is true — the caller (SiteController)
     * decides that by checking the current user's edit_posts capability
     * or authorship, since Post itself has no notion of "the current
     * viewer."
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
