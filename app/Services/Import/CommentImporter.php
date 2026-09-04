<?php

/**
 * Creates, skips, or overwrites a Comment from an ImportedComment DTO, resolving its parent through a caller-maintained id map and recording provenance.
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

use LumoraPress\Models\Comment;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\ContentImportRegistry;
use RuntimeException;

/**
 * Ordering contract: comments are imported after every post they belong
 * to (ImportedComment::$postId is already a resolved local id). Threading
 * follows PageImporter's same caller-maintained-map pattern for parent
 * replies — see that class's docblock — since a reply can reference a
 * sibling comment that has no local id yet within the same batch.
 */
final class CommentImporter
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly ContentImportRegistry $registry,
    ) {
    }

    /**
     * $existingContentMode is null except on a deliberate re-import. Overwrite is narrower
     * here than for Post/Page/Media: CommentService has no combined update(), only
     * updateContent()/updateStatus(), so author/parent/date are left as they were on first import.
     *
     * @param array<string, int> $externalIdToLocalId
     */
    public function import(string $batchId, string $source, ImportedComment $data, array $externalIdToLocalId = [], ?ExistingContentMode $existingContentMode = null): Comment
    {
        $existingId = $existingContentMode !== null && $data->externalId !== null
            ? $this->registry->existingLocalId($source, 'comment', $data->externalId)
            : null;

        if ($existingId !== null) {
            $existing = $this->comments->findById($existingId);

            if ($existing === null) {
                throw new RuntimeException("Comment external id \"{$data->externalId}\" was previously imported as #{$existingId}, but that comment no longer exists.");
            }

            if ($existingContentMode === ExistingContentMode::Skip) {
                return $existing;
            }

            $this->comments->updateContent($existingId, $data->content);

            return $this->comments->updateStatus($existingId, $data->status);
        }

        $parentId = $data->parentExternalId !== null
            ? ($externalIdToLocalId[$data->parentExternalId] ?? null)
            : null;

        $comment = $this->comments->create(
            postId: $data->postId,
            parentId: $parentId,
            userId: $data->userId,
            guestName: $data->guestName,
            guestEmail: $data->guestEmail,
            guestUrl: $data->guestUrl,
            content: $data->content,
            status: $data->status,
            ipAddress: null,
            userAgent: null,
            commentedAt: $data->commentedAt,
        );

        $this->registry->record($batchId, $source, 'comment', $comment->id, $data->externalId);

        return $comment;
    }
}
