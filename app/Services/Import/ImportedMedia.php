<?php

/**
 * A plain data holder describing one media file to register via MediaImporter (LPP-004/LPP-005 Phase 1), independent of where the data came from.
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

/**
 * $absolutePath must already point at a real, already-written local
 * file — a WXR importer downloads the remote attachment URL to disk
 * itself first (MediaImporter::downloadAndImport() does exactly that,
 * then delegates to the same local-file path this DTO describes), and
 * the dummy-content generator writes its own GD-rendered placeholder
 * image before building this. There is no "fetch from a URL" mode on
 * the DTO itself, by design — see MediaImporter's own docblock.
 */
final class ImportedMedia
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly int $uploadedByUserId,
        public readonly ?string $fileName = null,
        public readonly ?string $altText = null,
        public readonly ?string $caption = null,
        public readonly ?string $description = null,
        public readonly ?int $folderId = null,
        /**
         * See ImportedPost::$externalId's docblock — a WXR attachment's
         * original WordPress id, or null for dummy content.
         */
        public readonly ?string $externalId = null,
    ) {
    }
}
