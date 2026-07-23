<?php

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

final class Category
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $description,
        public readonly ?int $parentId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
