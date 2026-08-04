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
        /**
         * LP-066/LP-067: null means "use the site default editor" — see
         * EditorPreferenceService::activeEditor().
         */
        public readonly ?ContentFormat $preferredEditor = null,
    ) {
    }

    public function can(string $capability): bool
    {
        return $this->role->can($capability);
    }
}
