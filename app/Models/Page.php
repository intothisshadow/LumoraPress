<?php

/**
 * The Page domain model.
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

final class Page
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly PageStatus $status,
        public readonly int $authorId,
        public readonly ?int $parentId,
        public readonly ?int $featuredImageId,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ContentFormat $contentFormat = ContentFormat::Plain,
        /** @var array{x: int, y: int, width: int, height: int}|null */
        public readonly ?array $featuredImageCrop = null,
        /**
         * SEO overrides — see Post's identical fields for the fallback
         * chain (the_seo_title()/the_seo_description() in include/helpers.php).
         */
        public readonly ?string $metaTitle = null,
        public readonly ?string $metaDescription = null,
        public readonly ?DateTimeImmutable $trashedAt = null,
        /**
         * Position among siblings sharing the same parentId — lower sorts
         * first. Only meaningful within one parentId group; not a global
         * ordering across the whole table.
         */
        public readonly int $menuOrder = 0,
        public readonly PageVisibility $visibility = PageVisibility::Public,
        public readonly bool $commentsOpen = true,
    ) {
    }

    /**
     * Visible to an anonymous visitor: published (or due) and not
     * Private. See isVisibleToViewer() for the logged-in-staff version.
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->visibility !== PageVisibility::Public) {
            return false;
        }

        return $this->isPublishedOrDue();
    }

    /**
     * Same as isPubliclyVisible(), but a Private page also counts as
     * visible when $canViewPrivate is true — the caller decides that,
     * since Page has no notion of "the current viewer."
     */
    public function isVisibleToViewer(bool $canViewPrivate): bool
    {
        if (!$this->isPublishedOrDue()) {
            return false;
        }

        return $this->visibility === PageVisibility::Public || $canViewPrivate;
    }

    private function isPublishedOrDue(): bool
    {
        if ($this->status === PageStatus::Published) {
            return true;
        }

        return $this->status === PageStatus::Scheduled
            && $this->publishedAt !== null
            && $this->publishedAt <= new DateTimeImmutable();
    }
}
