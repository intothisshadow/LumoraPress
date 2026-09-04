<?php

/**
 * The Comment domain model.
 *
 * @package LumoraPress
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

final class Comment
{
    public function __construct(
        public readonly int $id,
        /**
         * Exactly one of $postId/$pageId is set. A nullable `page_id`
         * column exists alongside `post_id` rather than a polymorphic
         * content_id/content_type pair, to avoid migrating existing
         * comment data.
         */
        public readonly ?int $postId,
        public readonly ?int $parentId,
        public readonly ?int $userId,
        public readonly string $guestName,
        public readonly string $guestEmail,
        public readonly ?string $guestUrl,
        public readonly string $content,
        public readonly CommentStatus $status,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?int $pageId = null,
    ) {
    }
}
