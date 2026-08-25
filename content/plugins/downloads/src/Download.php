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
 * $url, $fileSizeBytes, and $targetUrl are all resolved by
 * DownloadService::hydrate() — a File download's $url is its Media
 * item's download endpoint and $fileSizeBytes comes from that same row
 * ($targetUrl stays null); a Url download's $url is its Redirect's own
 * source path (not the raw external target, so hit-counting via
 * RedirectService::recordHit() still applies), $fileSizeBytes stays
 * null, and $targetUrl is the real external destination — the value an
 * edit form needs to show/submit, since re-submitting $url itself would
 * point the redirect at this site's own local URL instead of the real
 * external destination.
 */
final class Download
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $description,
        // Reuses posts/pages' own ContentFormat enum and
        // `{table}.description_format` column convention (LPP-010)
        // rather than inventing a parallel one — the Description field
        // is edited with the exact same shared editor component.
        public readonly ContentFormat $descriptionFormat,
        public readonly ?int $folderId,
        public readonly DownloadType $type,
        public readonly ?int $mediaId,
        // A separate representative image, distinct from $mediaId's own
        // file — the same concept Simple Download Monitor's post-
        // thumbnail metabox represents on a source `sdm_downloads` post
        // (a WordPress import, LPP-004, is currently the only writer of
        // this field). Meaningful for either $type: a File download's
        // own file may not be an image at all (a .zip has nothing to
        // preview), and a Url download has no local file whatsoever.
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
