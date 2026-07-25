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
    ) {
    }

    /**
     * Whether the post is currently visible to public site visitors: either
     * published outright, or scheduled with a published_at time that has
     * already passed.
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->status === PostStatus::Published) {
            return true;
        }

        return $this->status === PostStatus::Scheduled
            && $this->publishedAt !== null
            && $this->publishedAt <= new DateTimeImmutable();
    }
}
