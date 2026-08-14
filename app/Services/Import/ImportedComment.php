<?php

/**
 * A plain data holder describing one comment to create via CommentImporter (LPP-004/LPP-005 Phase 1), independent of where the data came from.
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

use LumoraPress\Models\CommentStatus;

/**
 * $postId is an already-resolved local id (Posts are always imported
 * before Comments — see CommentImporter's ordering-contract docblock —
 * so the caller already has it). $parentExternalId is deliberately NOT
 * resolved yet: within one comment batch, a reply can reference a
 * sibling comment that doesn't have a local id until its own import()
 * call runs, so CommentImporter::import() takes a separate
 * $externalIdToLocalId map and resolves this field through it at call
 * time, the same pattern PageImporter uses for parent pages.
 */
final class ImportedComment
{
    public function __construct(
        public readonly int $postId,
        public readonly string $content,
        public readonly string $guestName,
        public readonly string $guestEmail,
        public readonly CommentStatus $status,
        public readonly ?string $guestUrl = null,
        public readonly ?int $userId = null,
        public readonly ?string $parentExternalId = null,
        /**
         * See ImportedPost::$externalId's docblock — a WXR comment's own
         * original id, for resolving other comments' parentExternalId
         * against it.
         */
        public readonly ?string $externalId = null,
    ) {
    }
}
