<?php

/**
 * Creates or reuses a User from an ImportedUser DTO, recording provenance only for newly created accounts (LPP-004/LPP-005 Phase 1).
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
 * Re-running the same import (a re-uploaded WXR file, a second Dummy
 * Content generation) must not create a second account for the same
 * WordPress author or duplicate a username collision — importOrReuse()
 * checks for an existing username/email match first and returns that
 * account unchanged when found.
 *
 * Deliberately only registers *newly created* users into
 * ContentImportRegistry, never a reused existing one: a reused account
 * wasn't created by this batch, so a later "remove everything this
 * batch created" must never be able to delete it. See
 * ContentImportRegistry's own docblock on why deletion always goes
 * through the caller's own tracked ids, never a blanket query.
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
        $user = $this->users->create($data->username, $data->email, $password, $data->role, $data->displayName);

        $this->registry->record($batchId, $source, 'user', $user->id, $data->externalId);

        return $user;
    }
}
