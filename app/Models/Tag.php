<?php

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

final class Tag
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $description,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
