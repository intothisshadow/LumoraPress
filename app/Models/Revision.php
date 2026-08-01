<?php

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

final class Revision
{
    public function __construct(
        public readonly int $id,
        public readonly RevisionableType $contentType,
        public readonly int $contentId,
        public readonly string $title,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly ContentFormat $contentFormat,
        public readonly int $authorId,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
