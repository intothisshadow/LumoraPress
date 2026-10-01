<?php

/**
 * Opens a Lumora Press export ZIP, extracting its manifest and media files for the native importer.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Import;

use JsonException;
use LumoraPress\Services\Export\ExportContent;
use LumoraPress\Services\Export\ExportManifest;
use LumoraPress\Services\Export\LumoraPressZipWriter;
use RuntimeException;
use ZipArchive;

/**
 * The archive is untrusted: only files the manifest names under uploads/
 * are ever extracted — never arbitrary entries — and each lands at a
 * path ExportManifest has already stripped of traversal segments. An
 * archive someone re-zipped inside one extra top-level folder is accepted
 * too, since that's an easy mistake to make by hand.
 */
final class LumoraPressExportSource
{
    public const SOURCE = 'lumora_press_export';

    /** Guards against a manifest crafted to exhaust memory on json_decode(). */
    private const MAX_MANIFEST_BYTES = 256 * 1024 * 1024;

    private ?string $lastExtracted = null;

    private function __construct(
        private readonly string $directory,
        private readonly ExportContent $content,
        private readonly ?ZipArchive $lazyZip = null,
        private readonly string $lazyPrefix = '',
        private readonly ?string $lazyUploadsFolder = null,
    ) {
    }

    /**
     * Like open(), but extracts nothing up front: each media file is taken
     * out of the archive (or read from $uploadsFolder) only when
     * resolveMedia() is called for it, so an import can be spread over
     * several requests. The caller keeps the ZIP in place until it's done,
     * and calls cleanup() at the end of every request.
     */
    public static function openStaged(string $zipPath, string $workingDirectory, ?string $uploadsFolder = null): self
    {
        $resolvedUploadsFolder = $uploadsFolder !== null && trim($uploadsFolder) !== '' ? self::resolveUploadsFolder($uploadsFolder) : null;
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That file is not a valid ZIP archive.');
        }

        try {
            [$prefix, $manifestJson] = self::readManifest($zip);

            try {
                $data = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('The export\'s manifest.json is damaged and could not be read.');
            }

            if (!is_array($data)) {
                throw new RuntimeException('The export\'s manifest.json is damaged and could not be read.');
            }

            $directory = rtrim($workingDirectory, '/') . '/files';

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create a temporary folder for the import.');
            }

            return new self($directory, ExportManifest::decode($data, $directory), $zip, $prefix, $resolvedUploadsFolder);
        } catch (\Throwable $exception) {
            $zip->close();

            throw $exception;
        }
    }

    /**
     * Makes one media file readable for the importer: extracted from the
     * archive when it holds the file, otherwise found in the uploads
     * folder, otherwise returned unchanged (the importer reports it missing).
     * The previously extracted file is deleted first, since the importer
     * has already copied it.
     */
    public function resolveMedia(ImportedMedia $media, ExportContent $content): ImportedMedia
    {
        if ($this->lazyZip === null) {
            return $media;
        }

        if ($this->lastExtracted !== null && is_file($this->lastExtracted)) {
            unlink($this->lastExtracted);
        }

        $this->lastExtracted = null;
        $relativePath = ExportManifest::safeRelativePath($content->mediaFiles[(int) $media->externalId]['filePath'] ?? '');

        if ($relativePath === '') {
            return $media;
        }

        $entry = $this->lazyZip->statName($this->lazyPrefix . LumoraPressZipWriter::UPLOADS_PREFIX . $relativePath);

        if ($entry !== false) {
            $target = $this->directory . '/' . $relativePath;

            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Unable to create a temporary folder for the import.');
            }

            $in = $this->lazyZip->getStream($entry['name']);
            $out = fopen($target, 'wb');

            if ($in === false || $out === false) {
                throw new RuntimeException("Unable to extract \"{$relativePath}\" from the export.");
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $this->lastExtracted = $target;

            return self::withPath($media, $target);
        }

        if ($this->lazyUploadsFolder !== null) {
            $candidate = realpath($this->lazyUploadsFolder . '/' . $relativePath);

            if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, rtrim($this->lazyUploadsFolder, '/') . '/')) {
                return self::withPath($media, $candidate);
            }
        }

        return $media;
    }

    private static function withPath(ImportedMedia $media, string $absolutePath): ImportedMedia
    {
        return new ImportedMedia(
            absolutePath: $absolutePath,
            uploadedByUserId: $media->uploadedByUserId,
            fileName: $media->fileName,
            altText: $media->altText,
            caption: $media->caption,
            description: $media->description,
            folderId: $media->folderId,
            externalId: $media->externalId,
            uploadedAt: $media->uploadedAt,
            relativeDirectory: $media->relativeDirectory,
        );
    }

    /**
     * Extracts into a fresh directory under $workingDirectory; call
     * cleanup() once the import has copied what it needs.
     *
     * $uploadsFolder is an optional server folder holding the exporting
     * site's uploads (its content/uploads, copied across by hand), for an
     * export made without uploaded files because building one with them
     * was too much for the host. A media file bundled in the ZIP wins;
     * otherwise it's read from there.
     */
    public static function open(string $zipPath, string $workingDirectory, ?string $uploadsFolder = null): self
    {
        $resolvedUploadsFolder = $uploadsFolder !== null && trim($uploadsFolder) !== '' ? self::resolveUploadsFolder($uploadsFolder) : null;
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That file is not a valid ZIP archive.');
        }

        $directory = rtrim($workingDirectory, '/') . '/' . bin2hex(random_bytes(16));

        try {
            [$prefix, $manifestJson] = self::readManifest($zip);

            try {
                $data = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('The export\'s manifest.json is damaged and could not be read.');
            }

            if (!is_array($data)) {
                throw new RuntimeException('The export\'s manifest.json is damaged and could not be read.');
            }

            $content = ExportManifest::decode($data, $directory . '/uploads');

            if (!mkdir($directory . '/uploads', 0755, true) && !is_dir($directory . '/uploads')) {
                throw new RuntimeException('Unable to create a temporary folder for the import.');
            }

            self::extractMediaFiles($zip, $prefix, $content, $directory . '/uploads');

            if ($resolvedUploadsFolder !== null) {
                $content = self::pointMissingFilesAt($content, $resolvedUploadsFolder);
            }
        } catch (\Throwable $exception) {
            self::removeDirectory($directory);

            throw $exception;
        } finally {
            $zip->close();
        }

        return new self($directory, $content);
    }

    /**
     * Resolves an admin-typed server path to an export archive, for
     * files too large to upload through the browser. Returns the real
     * path; open() still decides whether it's genuinely an export.
     */
    public static function resolveServerPath(string $path): string
    {
        $path = trim($path);
        $resolved = $path !== '' ? realpath($path) : false;

        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('No file was found at that path on this server.');
        }

        if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('That file isn\'t a .zip file.');
        }

        if (!is_readable($resolved)) {
            throw new RuntimeException('The web server isn\'t allowed to read that file. Check its permissions.');
        }

        return $resolved;
    }

    /**
     * Every .zip directly inside $directories that is a Lumora Press
     * export, for the Import screen's "Discover" list. Only each
     * archive's file list is read, never its contents.
     *
     * @param array<int, string> $directories
     * @return array<int, string> real paths, sorted, at most $limit
     */
    public static function discoverArchives(array $directories, int $limit = 50): array
    {
        $found = [];

        foreach ($directories as $directory) {
            // Plain '*' filtered here rather than GLOB_BRACE, which musl-based PHP builds lack.
            foreach (glob(rtrim($directory, '/') . '/*') ?: [] as $candidate) {
                $resolved = strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'zip' ? realpath($candidate) : false;

                if ($resolved === false || isset($found[$resolved]) || !is_file($resolved) || !is_readable($resolved)) {
                    continue;
                }

                $zip = new ZipArchive();

                if ($zip->open($resolved, ZipArchive::RDONLY) !== true) {
                    continue;
                }

                try {
                    self::manifestPrefix($zip);
                    $found[$resolved] = true;
                } catch (RuntimeException) {
                    // Some other kind of ZIP; not offered.
                } finally {
                    $zip->close();
                }

                if (count($found) >= $limit) {
                    break 2;
                }
            }
        }

        $paths = array_keys($found);
        sort($paths);

        return $paths;
    }

    /**
     * @throws RuntimeException when $path isn't an existing, readable folder
     */
    public static function resolveUploadsFolder(string $path): string
    {
        $resolved = realpath(trim($path));

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('No folder was found at the uploaded files location you entered.');
        }

        if (!is_readable($resolved)) {
            throw new RuntimeException('The web server isn\'t allowed to read the uploaded files folder. Check its permissions.');
        }

        return $resolved;
    }

        public function content(): ExportContent
    {
        return $this->content;
    }

    public function cleanup(): void
    {
        $this->lazyZip?->close();
        self::removeDirectory($this->directory);
    }

    /**
     * @return array{0: string, 1: string} the entry-name prefix everything else sits under, and the manifest's contents
     */
    private static function readManifest(ZipArchive $zip): array
    {
        $prefix = self::manifestPrefix($zip);
        $stat = $zip->statName($prefix . LumoraPressZipWriter::MANIFEST_NAME);

        if ($stat === false || $stat['size'] > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('The export\'s manifest.json is too large to read.');
        }

        $json = $zip->getFromName($prefix . LumoraPressZipWriter::MANIFEST_NAME);

        if ($json === false) {
            throw new RuntimeException('The export\'s manifest.json could not be read.');
        }

        return [$prefix, $json];
    }

    /**
     * Where manifest.json sits: the archive root, or inside exactly one
     * top-level folder. Reads only the archive's file list.
     */
    private static function manifestPrefix(ZipArchive $zip): string
    {
        $prefix = '';

        if ($zip->locateName(LumoraPressZipWriter::MANIFEST_NAME) === false) {
            $candidates = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (preg_match('#^([^/]+/)' . preg_quote(LumoraPressZipWriter::MANIFEST_NAME, '#') . '$#', $name, $matches) === 1) {
                    $candidates[] = $matches[1];
                }
            }

            if (count($candidates) !== 1 || str_contains($candidates[0], '..')) {
                throw new RuntimeException('This ZIP file is not a Lumora Press export (no manifest.json was found).');
            }

            $prefix = $candidates[0];
        }

        return $prefix;
    }

    private static function extractMediaFiles(ZipArchive $zip, string $prefix, ExportContent $content, string $uploadsDirectory): void
    {
        $entries = [];
        $totalBytes = 0;

        foreach ($content->mediaFiles as $file) {
            $relativePath = ExportManifest::safeRelativePath($file['filePath']);
            $stat = $relativePath !== '' ? $zip->statName($prefix . LumoraPressZipWriter::UPLOADS_PREFIX . $relativePath) : false;

            if ($stat !== false) {
                $entries[$relativePath] = $stat['name'];
                $totalBytes += (int) $stat['size'];
            }
        }

        $freeBytes = disk_free_space($uploadsDirectory);

        // Twice the size: the files are extracted here, then copied again into the uploads folder.
        if ($freeBytes !== false && $totalBytes * 2 > $freeBytes) {
            throw new RuntimeException('There isn\'t enough free disk space on this server to import this export\'s media files.');
        }

        foreach ($entries as $relativePath => $entryName) {
            $target = $uploadsDirectory . '/' . $relativePath;

            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Unable to create a temporary folder for the import.');
            }

            $in = $zip->getStream($entryName);
            $out = fopen($target, 'wb');

            if ($in === false || $out === false) {
                throw new RuntimeException("Unable to extract \"{$relativePath}\" from the export.");
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
    }

    /**
     * The manifest's paths are untrusted: each is stripped of traversal
     * segments first, and the file it resolves to (symlinks followed)
     * must still sit inside $folder, or it's treated as missing.
     */
    private static function pointMissingFilesAt(ExportContent $content, string $folder): ExportContent
    {
        $media = [];
        $prefix = rtrim($folder, '/') . '/';

        foreach ($content->media as $item) {
            $relativePath = ExportManifest::safeRelativePath($content->mediaFiles[(int) $item->externalId]['filePath'] ?? '');
            $candidate = !is_file($item->absolutePath) && $relativePath !== '' ? realpath($folder . '/' . $relativePath) : false;

            $media[] = $candidate !== false && is_file($candidate) && str_starts_with($candidate, $prefix)
                ? new ImportedMedia(
                    absolutePath: $candidate,
                    uploadedByUserId: $item->uploadedByUserId,
                    fileName: $item->fileName,
                    altText: $item->altText,
                    caption: $item->caption,
                    description: $item->description,
                    folderId: $item->folderId,
                    externalId: $item->externalId,
                    uploadedAt: $item->uploadedAt,
                    relativeDirectory: $item->relativeDirectory,
                )
                : $item;
        }

        return $content->withMedia($media);
    }

        private static function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && !is_link($itemPath)) {
                self::removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
