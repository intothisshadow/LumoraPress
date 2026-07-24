<?php

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

final class Comment
{
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly ?int $parentId,
        public readonly ?int $userId,
        public readonly string $guestName,
        public readonly string $guestEmail,
        public readonly ?string $guestUrl,
        public readonly string $content,
        public readonly CommentStatus $status,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
