<?php

/**
 * A plain data holder describing one user to create-or-reuse via UserImporter (LPP-004/LPP-005 Phase 1), independent of where the data came from.
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

use LumoraPress\Models\UserRole;

/**
 * No password field — UserService::create() always generates a fresh
 * hash from a plaintext string internally, and neither a WXR export nor
 * a synthetic dummy user has a real password to carry, so
 * UserImporter::importOrReuse() generates a random throwaway one itself.
 */
final class ImportedUser
{
    public function __construct(
        public readonly string $username,
        public readonly string $email,
        public readonly UserRole $role,
        public readonly ?string $displayName = null,
        /**
         * See ImportedPost::$externalId's docblock — a WXR author's
         * original WordPress user id, or null for dummy content (which
         * has no external identity to preserve).
         */
        public readonly ?string $externalId = null,
    ) {
    }
}
