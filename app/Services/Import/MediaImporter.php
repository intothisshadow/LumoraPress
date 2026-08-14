<?php

/**
 * Registers a local file as a media item from an ImportedMedia DTO and records provenance (LPP-004/LPP-005 Phase 1).
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

use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\MediaService;
use RuntimeException;

/**
 * Copies $data->absolutePath into the uploads directory (year/month
 * layout, matching MediaService::upload()'s own naming) and registers it
 * via MediaService::registerExistingFile() — deliberately not
 * MediaImportService (LP-041), whose allow-list/mirroring machinery is
 * built for the FTP-scan admin screen and would need a plugin's own
 * temp/download directory added to its allow-list for no benefit here.
 *
 * A future downloadAndImport(string $url, ...) helper (LPP-004, once its
 * WXR parser exists) would download a remote attachment URL to a local
 * temp file first, then delegate to importFromLocalFile() below — not
 * built yet since nothing calls it this phase (see the Phase 1/Phase 2
 * split in this feature's implementation plan).
 */
final class MediaImporter
{
    public function __construct(
        private readonly MediaService $media,
        private readonly string $uploadsPath,
        private readonly ContentImportRegistry $registry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function importFromLocalFile(string $batchId, string $source, ImportedMedia $data): array
    {
        if (!is_file($data->absolutePath)) {
            throw new RuntimeException("File not found: {$data->absolutePath}");
        }

        $extension = strtolower(pathinfo($data->absolutePath, PATHINFO_EXTENSION));

        if (!$this->media->isAllowedExtension($extension)) {
            throw new RuntimeException('File type is not allowed.');
        }

        $mimeType = mime_content_type($data->absolutePath) ?: '';

        if (!$this->media->isAllowedMimeType($mimeType)) {
            throw new RuntimeException('File type could not be verified.');
        }

        $displayName = $data->fileName ?? basename($data->absolutePath);
        $safeName = $this->media->sanitizeFilename(pathinfo($displayName, PATHINFO_FILENAME));
        $relativePath = date('Y') . '/' . date('m') . '/' . $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = rtrim($this->uploadsPath, '/') . '/' . $relativePath;

        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('Unable to create the uploads directory.');
        }

        if (!copy($data->absolutePath, $destination)) {
            throw new RuntimeException('Unable to copy the file into the uploads directory.');
        }

        $width = null;
        $height = null;

        if (str_starts_with($mimeType, 'image/')) {
            $dimensions = getimagesize($destination);

            if (is_array($dimensions)) {
                $width = $dimensions[0];
                $height = $dimensions[1];
            }
        }

        $media = $this->media->registerExistingFile(
            relativePath: $relativePath,
            fileName: $displayName,
            mimeType: $mimeType,
            fileSize: (int) filesize($destination),
            width: $width,
            height: $height,
            uploadedByUserId: $data->uploadedByUserId,
            folderId: $data->folderId,
        );

        if ($data->altText !== null || $data->caption !== null || $data->description !== null) {
            $this->media->updateMetadata((int) $media['id'], $data->altText, $data->caption, $data->description, null);
            $media = $this->media->find((int) $media['id']) ?? $media;
        }

        $this->registry->record($batchId, $source, 'media', (int) $media['id'], $data->externalId);

        return $media;
    }
}
