<?php

/**
 * A single link directory entry: a title, external URL, category, optional thumbnail, and description.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use DateTimeImmutable;
use LumoraPress\Models\ContentFormat;

final class Link
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $url,
        public readonly string $description,
        // Reuses posts/pages' ContentFormat enum since Description uses the same shared renderer.
        public readonly ContentFormat $descriptionFormat,
        public readonly ?int $categoryId,
        // A representative screenshot/logo for the linked site, entirely
        // optional — unlike Downloads' thumbnailMediaId, there's no local
        // file this could otherwise fall back to.
        public readonly ?int $thumbnailMediaId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?DateTimeImmutable $trashedAt = null,
    ) {
    }

    /**
     * Computed, not stored — see LinkStatus's own docblock for why this
     * table has no status column of its own.
     */
    public function status(): LinkStatus
    {
        return $this->trashedAt === null ? LinkStatus::Live : LinkStatus::Trashed;
    }
}
