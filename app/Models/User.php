<?php

declare(strict_types=1);

namespace LumoraPress\Models;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $displayName,
        public readonly UserRole $role,
    ) {
    }

    public function can(string $capability): bool
    {
        return $this->role->can($capability);
    }
}
