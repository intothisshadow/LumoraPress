<?php

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
         * LP-022 SEO overrides — see Post's identical fields for the
         * fallback chain (the_seo_title()/the_seo_description() in
         * include/helpers.php).
         */
        public readonly ?string $metaTitle = null,
        public readonly ?string $metaDescription = null,
    ) {
    }

    /**
     * Whether the page is currently visible to public site visitors: either
     * published outright, or scheduled with a published_at time that has
     * already passed.
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->status === PageStatus::Published) {
            return true;
        }

        return $this->status === PageStatus::Scheduled
            && $this->publishedAt !== null
            && $this->publishedAt <= new DateTimeImmutable();
    }
}
