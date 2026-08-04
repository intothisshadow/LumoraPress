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
        /**
         * LP-032: non-null means this account is in the Trash (soft
         * deleted). A trashed user cannot authenticate — see
         * UserService::verifyCredentials() and Auth::user().
         */
        public readonly ?\DateTimeImmutable $trashedAt = null,
        /**
         * LP-032: an uploaded avatar's media id, or null to fall back to
         * Gravatar — see UserService::gravatarUrl().
         */
        public readonly ?int $avatarMediaId = null,
    ) {
    }

    public function can(string $capability): bool
    {
        return $this->role->can($capability);
    }
}
