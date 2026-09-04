<?php

/**
 * Registers, skips, or overwrites a local file as a media item from an ImportedMedia DTO and records provenance.
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
 * Copies $data->absolutePath into the uploads directory and registers it via
 * MediaService::registerExistingFile() — deliberately not MediaImportService, whose
 * allow-list/mirroring machinery is built for the FTP-scan admin screen and would need a
 * plugin's own temp/download directory added for no benefit here.
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
     * $existingContentMode is null except on a deliberate re-import — see
     * ExistingContentMode's docblock. Overwrite is narrower here than for Post/Page: it
     * never re-copies the underlying file (MediaService has no in-place replace primitive
     * for a local path), only alt text/caption/description/folder. Skip and Overwrite both
     * skip every filesystem/MIME-type check once a match is found.
     *
     * @return array<string, mixed>
     */
    public function importFromLocalFile(string $batchId, string $source, ImportedMedia $data, ?ExistingContentMode $existingContentMode = null): array
    {
        $existingId = $existingContentMode !== null && $data->externalId !== null
            ? $this->registry->existingLocalId($source, 'media', $data->externalId)
            : null;

        if ($existingId !== null) {
            $existing = $this->media->find($existingId);

            if ($existing === null) {
                throw new RuntimeException("Media external id \"{$data->externalId}\" was previously imported as #{$existingId}, but that media item no longer exists.");
            }

            if ($existingContentMode === ExistingContentMode::Skip) {
                return $existing;
            }

            // $existing['notes'] is preserved explicitly — updateMetadata()
            // has no "keep existing" fallback for any of its four
            // fields, and ImportedMedia carries no notes field of its
            // own to overwrite it with.
            $this->media->updateMetadata($existingId, $data->altText, $data->caption, $data->description, $existing['notes'] !== null ? (string) $existing['notes'] : null);

            if ($data->folderId !== (int) $existing['folder_id']) {
                $this->media->move($existingId, $data->folderId);
            }

            return $this->media->find($existingId) ?? $existing;
        }

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
        $directory = $data->relativeDirectory !== null ? $this->sanitizeRelativeDirectory($data->relativeDirectory) : '';
        $directory = $directory !== '' ? $directory : (date('Y') . '/' . date('m'));
        $relativePath = $directory . '/' . $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
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
            uploadedAt: $data->uploadedAt,
        );

        if ($data->altText !== null || $data->caption !== null || $data->description !== null) {
            $this->media->updateMetadata((int) $media['id'], $data->altText, $data->caption, $data->description, null);
            $media = $this->media->find((int) $media['id']) ?? $media;
        }

        $this->registry->record($batchId, $source, 'media', (int) $media['id'], $data->externalId);

        return $media;
    }

    /**
     * Reduces $directory to a safe relative path under the uploads root:
     * splits on '/', strips every character outside [a-zA-Z0-9-_] from
     * each segment (the same allow-list MediaService::sanitizeFilename()
     * already uses for filenames), and drops any segment that sanitizes
     * to empty — a ".." traversal segment strips to nothing and is
     * dropped, never preserved or replaced with a placeholder, so this
     * can never resolve outside $this->uploadsPath regardless of what a
     * source database's own path value contains.
     */
    private function sanitizeRelativeDirectory(string $directory): string
    {
        $segments = [];

        foreach (explode('/', $directory) as $segment) {
            $clean = trim(preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $segment) ?? '', '-');

            if ($clean !== '') {
                $segments[] = strtolower($clean);
            }
        }

        return implode('/', $segments);
    }
}
