<?php

/**
 * Creates or reuses a User from an ImportedUser DTO, recording provenance only for newly created accounts.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Import;

use LumoraPress\Models\User;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\UserService;

/**
 * Re-running the same import must not create a second account for the same author —
 * importOrReuse() checks for an existing username/email match first. Only registers newly
 * created users into ContentImportRegistry, never a reused one, so a later "remove everything
 * this batch created" can never delete an account it didn't create.
 */
final class UserImporter
{
    public function __construct(
        private readonly UserService $users,
        private readonly ContentImportRegistry $registry,
    ) {
    }

    public function importOrReuse(string $batchId, string $source, ImportedUser $data): User
    {
        $existing = $this->users->findByUsername($data->username) ?? $this->users->findByEmail($data->email);

        if ($existing !== null) {
            return $existing;
        }

        $password = bin2hex(random_bytes(16));
        $user = $this->users->create($data->username, $data->email, $password, $data->role, $data->displayName, $data->registeredAt);

        $this->registry->record($batchId, $source, 'user', $user->id, $data->externalId);

        return $user;
    }
}
