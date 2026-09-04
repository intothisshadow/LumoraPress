<?php

/**
 * A single downloadable item: title/description/category sitting on top of a Media item or a Redirect.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use DateTimeImmutable;
use LumoraPress\Models\ContentFormat;

/**
 * $url, $fileSizeBytes, and $targetUrl are resolved by DownloadService::hydrate().
 * For a File download, $url is the Media download endpoint and $fileSizeBytes
 * comes from that row ($targetUrl stays null). For a Url download, $url is the
 * Redirect's own source path (so hit-counting still applies) and $targetUrl is
 * the real external destination shown/submitted by the edit form.
 */
final class Download
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $description,
        // Reuses posts/pages' ContentFormat enum since Description uses the same shared editor.
        public readonly ContentFormat $descriptionFormat,
        public readonly ?int $folderId,
        // The download's own taxonomy, decoupled from $folderId (which only
        // governs where a File download's Media item sits in the Media Library).
        public readonly ?int $categoryId,
        public readonly DownloadType $type,
        public readonly ?int $mediaId,
        // Whether $mediaId's row was created for this download vs. attached from
        // an existing one — only an owned row is cleaned up on delete/replace.
        public readonly bool $mediaOwned,
        // A separate representative image, since a File's own file may not be
        // one (e.g. a .zip) and a Url download has no local file at all.
        public readonly ?int $thumbnailMediaId,
        public readonly ?int $redirectId,
        public readonly string $url,
        public readonly ?int $fileSizeBytes,
        public readonly ?string $targetUrl,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?DateTimeImmutable $trashedAt = null,
    ) {
    }

    /**
     * Computed, not stored — see DownloadStatus's own docblock for why
     * this table has no status column of its own.
     */
    public function status(): DownloadStatus
    {
        return $this->trashedAt === null ? DownloadStatus::Live : DownloadStatus::Trashed;
    }
}
