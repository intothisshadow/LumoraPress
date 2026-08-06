<?php

/**
 * FTP Media Import (LP-041): registers media files that already exist on the server's filesystem.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * FTP Media Import (LP-041): registers media files that already exist on
 * the server's filesystem (dropped there via FTP/SFTP/a hosting file
 * manager) into the Media Manager, without a browser upload round-trip.
 * Deliberately a sibling of MediaService, not logic bolted onto it —
 * MediaService's own docblock scopes it to "validated uploads", and this
 * is a distinct ingestion path with its own safety rules (server-path
 * validation has nothing to do with $_FILES) — but it reuses
 * MediaService's allow-list/filename-sanitizing/duplicate-hash machinery
 * rather than duplicating it (see MediaService's docblock).
 *
 * Every path this class touches is re-validated with isPathAllowed()
 * immediately before use, never trusted from an earlier request (e.g. a
 * path round-tripped through a preview form) — the same "don't trust
 * anything past the first check" posture MediaService::upload() applies
 * to MIME types (never trusts the client-supplied $file['type'], always
 * re-detects via mime_content_type()).
 */
final class MediaImportService
{
    private const DEFAULT_BATCH_SIZE = 10;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly string $uploadsPath,
        private readonly MediaService $media,
        private readonly ThumbnailService $thumbnails,
        private readonly FolderService $folders,
    ) {
    }

    /**
     * Resolves both paths with realpath() (so symlinks can't be used to
     * escape an allowed directory) and requires $path to equal, or be a
     * real filesystem descendant of, at least one entry in
     * $allowedDirectories — the same "never string-match alone" posture
     * as ThemeInstaller::isUnsafeEntryName(), but for real paths rather
     * than ZIP entry names.
     *
     * @param array<int, string> $allowedDirectories
     */
    public function isPathAllowed(string $path, array $allowedDirectories): bool
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false) {
            return false;
        }

        foreach ($allowedDirectories as $allowedDirectory) {
            $resolvedAllowed = realpath($allowedDirectory);

            if ($resolvedAllowed === false) {
                continue;
            }

            if ($resolvedPath === $resolvedAllowed || str_starts_with($resolvedPath, rtrim($resolvedAllowed, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * LP-064: lists real, immediate (non-recursive) subdirectories of
     * $scanParent as candidates for the admin to add to the allowed
     * import directories, instead of having to already know exact server
     * paths to type in blind. Deliberately shallow and non-configurable
     * to an arbitrary starting point — the caller always passes
     * dirname(LUMORA_ROOT), the one location most likely to hold an
     * FTP-dropped sibling folder on shared hosting — so this never turns
     * into a general-purpose file browser. A pure read: nothing here
     * writes to $allowedDirectories or the filesystem; alreadyAllowed is
     * just a display hint for the view.
     *
     * @param array<int, string> $allowedDirectories Existing allowed
     *     directories, used only to flag which candidates are already
     *     configured.
     * @param array<int, string> $exclude Resolved-away paths (e.g.
     *     LUMORA_ROOT itself — importing from the app's own install
     *     directory isn't a real use case).
     * @return array<int, array{path: string, alreadyAllowed: bool}>
     */
    public function discoverCandidateDirectories(
        string $scanParent,
        array $allowedDirectories = [],
        array $exclude = [],
        int $limit = 200,
    ): array {
        $resolvedParent = realpath($scanParent);

        if ($resolvedParent === false || !is_dir($resolvedParent)) {
            return [];
        }

        $entries = scandir($resolvedParent);

        if ($entries === false) {
            return [];
        }

        $resolvedExclude = array_filter(array_map('realpath', $exclude), static fn ($path): bool => $path !== false);
        $resolvedAllowed = array_filter(array_map('realpath', $allowedDirectories), static fn ($path): bool => $path !== false);

        $candidates = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $resolvedParent . '/' . $entry;
            $resolvedPath = realpath($path);

            if ($resolvedPath === false || !is_dir($resolvedPath) || in_array($resolvedPath, $resolvedExclude, true)) {
                continue;
            }

            $candidates[] = [
                'path' => $resolvedPath,
                'alreadyAllowed' => in_array($resolvedPath, $resolvedAllowed, true),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Lists candidate files under $directory (only extensions
     * MediaService::isAllowedExtension() accepts), each flagged with
     * whether its content already exists in the media library (duplicate/
     * moved/renamed-file detection — a rename doesn't change the hash).
     *
     * @param array<int, string> $allowedDirectories
     * @return array<int, array{path: string, relativePath: string, size: int, modifiedAt: string, isDuplicate: bool}>
     */
    public function scan(string $directory, bool $recursive, array $allowedDirectories): array
    {
        if (!$this->isPathAllowed($directory, $allowedDirectories)) {
            throw new RuntimeException('That directory is not in the list of allowed import directories.');
        }

        $root = realpath($directory);

        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('That directory could not be read.');
        }

        $results = [];
        $this->scanDirectory($root, $root, $recursive, $results);

        usort($results, static fn (array $a, array $b): int => strcmp($a['relativePath'], $b['relativePath']));

        return $results;
    }

    /**
     * @param array<int, array{path: string, relativePath: string, size: int, modifiedAt: string, isDuplicate: bool}> $results
     */
    private function scanDirectory(string $root, string $directory, bool $recursive, array &$results): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                if ($recursive) {
                    $this->scanDirectory($root, $path, $recursive, $results);
                }

                continue;
            }

            if (!is_file($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (!$this->media->isAllowedExtension($extension)) {
                continue;
            }

            $hash = hash_file('sha256', $path);

            $results[] = [
                'path' => $path,
                'relativePath' => ltrim(substr($path, strlen($root)), '/'),
                'size' => (int) filesize($path),
                'modifiedAt' => date('Y-m-d H:i:s', (int) filemtime($path)),
                'isDuplicate' => $hash !== false && $this->hashExists($hash),
            ];
        }
    }

    private function hashExists(string $hash): bool
    {
        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->mediaTable() . ' WHERE file_hash = :file_hash',
            ['file_hash' => $hash],
        ) !== null;
    }

    /**
     * Imports one file. Re-validates the path and the real MIME type
     * (never trusts the extension alone or anything carried over from a
     * preview request) before touching the uploads directory.
     *
     * @param array<int, string> $allowedDirectories
     * @param array<string, int> $mirroredFolderIds Memoized relative-subpath
     *     => folder id map, shared across a whole import batch by the
     *     caller so the same subdirectory isn't recreated per file.
     * @return array{status: 'imported'|'duplicate'|'failed', mediaId: ?int, reason: ?string}
     */
    public function import(
        string $absolutePath,
        int $userId,
        ?int $folderId,
        bool $mirrorStructure,
        bool $useFileModifiedDate,
        string $scanRoot,
        array $allowedDirectories,
        array &$mirroredFolderIds = [],
    ): array {
        if (!$this->isPathAllowed($absolutePath, $allowedDirectories)) {
            return ['status' => 'failed', 'mediaId' => null, 'reason' => 'Path is outside the allowed import directories.'];
        }

        $resolvedPath = realpath($absolutePath);

        if ($resolvedPath === false || !is_file($resolvedPath)) {
            return ['status' => 'failed', 'mediaId' => null, 'reason' => 'File no longer exists.'];
        }

        $extension = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));

        if (!$this->media->isAllowedExtension($extension)) {
            return ['status' => 'failed', 'mediaId' => null, 'reason' => 'File type is not allowed.'];
        }

        $mimeType = mime_content_type($resolvedPath) ?: '';

        if (!$this->media->isAllowedMimeType($mimeType)) {
            return ['status' => 'failed', 'mediaId' => null, 'reason' => 'File type could not be verified.'];
        }

        $hash = hash_file('sha256', $resolvedPath);

        if ($hash !== false && $this->hashExists($hash)) {
            return ['status' => 'duplicate', 'mediaId' => null, 'reason' => 'A file with identical content is already in the Media Manager.'];
        }

        $targetFolderId = $folderId;

        if ($mirrorStructure) {
            $resolvedScanRoot = realpath($scanRoot);
            $relativeDir = $resolvedScanRoot !== false ? trim(str_replace('\\', '/', dirname(substr($resolvedPath, strlen($resolvedScanRoot))) ?: ''), '/') : '';
            $relativeDir = $relativeDir === '.' ? '' : $relativeDir;

            if ($relativeDir !== '') {
                $targetFolderId = $this->resolveMirroredFolder($relativeDir, $folderId, $mirroredFolderIds);
            }
        }

        $year = date('Y');
        $month = date('m');
        $directory = rtrim($this->uploadsPath, '/') . "/{$year}/{$month}";

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return ['status' => 'failed', 'mediaId' => null, 'reason' => 'Unable to create the uploads directory.'];
        }

        $originalName = basename($resolvedPath);
        $safeName = $this->media->sanitizeFilename(pathinfo($originalName, PATHINFO_FILENAME));
        $filename = $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $directory . '/' . $filename;

        if (!copy($resolvedPath, $destination)) {
            return ['status' => 'failed', 'mediaId' => null, 'reason' => 'Unable to copy the file into the uploads directory.'];
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

        $relativePath = "{$year}/{$month}/{$filename}";
        $uploadedAt = $useFileModifiedDate
            ? date('Y-m-d H:i:s', (int) filemtime($resolvedPath))
            : date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->mediaTable() . '
                (file_name, file_path, mime_type, file_size, width, height, uploaded_by, folder_id, file_hash, uploaded_at)
             VALUES (:file_name, :file_path, :mime_type, :file_size, :width, :height, :uploaded_by, :folder_id, :file_hash, :uploaded_at)',
            [
                'file_name' => $originalName,
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => (int) filesize($destination),
                'width' => $width,
                'height' => $height,
                'uploaded_by' => $userId,
                'folder_id' => $targetFolderId,
                'file_hash' => $hash ?: null,
                'uploaded_at' => $uploadedAt,
            ],
        );

        $media = $this->media->find((int) $id);

        if ($media !== null && str_starts_with($mimeType, 'image/')) {
            $this->thumbnails->generate($media);
        }

        return ['status' => 'imported', 'mediaId' => (int) $id, 'reason' => null];
    }

    /**
     * Processes one batch for the admin view's redirect-loop progress UI
     * — the same shape as ThumbnailService::queueForBulkRegeneration().
     *
     * @param array<int, string> $paths
     * @param array<int, string> $allowedDirectories
     * @return array{results: array<int, array{path: string, status: string, mediaId: ?int, reason: ?string}>, processed: int, total: int, done: bool}
     */
    public function importBatch(
        array $paths,
        int $userId,
        ?int $folderId,
        bool $mirrorStructure,
        bool $useFileModifiedDate,
        string $scanRoot,
        array $allowedDirectories,
        int $offset,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
    ): array {
        $total = count($paths);
        $batch = array_slice($paths, $offset, $batchSize);
        $results = [];
        $mirroredFolderIds = [];

        foreach ($batch as $path) {
            $outcome = $this->import($path, $userId, $folderId, $mirrorStructure, $useFileModifiedDate, $scanRoot, $allowedDirectories, $mirroredFolderIds);
            $results[] = [
                'path' => $path,
                'status' => $outcome['status'],
                'mediaId' => $outcome['mediaId'],
                'reason' => $outcome['reason'],
            ];
        }

        return [
            'results' => $results,
            'processed' => count($batch),
            'total' => $total,
            'done' => ($offset + $batchSize) >= $total,
        ];
    }

    /**
     * Finds-or-creates each path segment as a folder. Looks up existing
     * folders (by name + parent) before creating one — not just a
     * per-request memoization cache, since a multi-batch import re-runs
     * this across separate HTTP requests (each with a fresh, empty
     * $mirroredFolderIds), and mirroring the same subdirectory across
     * batches must not create duplicate same-named folders. This is the
     * folder-tree equivalent of import()'s file_hash duplicate check.
     *
     * @param array<string, int> $mirroredFolderIds
     */
    private function resolveMirroredFolder(string $relativeDir, ?int $rootFolderId, array &$mirroredFolderIds): int
    {
        if (isset($mirroredFolderIds[$relativeDir])) {
            return $mirroredFolderIds[$relativeDir];
        }

        $allFolders = $this->folders->listAll();
        $segments = explode('/', $relativeDir);
        $parentId = $rootFolderId;
        $builtPath = '';

        foreach ($segments as $segment) {
            $builtPath = $builtPath === '' ? $segment : $builtPath . '/' . $segment;

            if (isset($mirroredFolderIds[$builtPath])) {
                $parentId = $mirroredFolderIds[$builtPath];

                continue;
            }

            $existing = null;

            foreach ($allFolders as $candidate) {
                if ($candidate->name === $segment && $candidate->parentId === $parentId) {
                    $existing = $candidate;

                    break;
                }
            }

            $folder = $existing ?? $this->folders->create($segment, $parentId);
            $mirroredFolderIds[$builtPath] = $folder->id;
            $parentId = $folder->id;
        }

        return $parentId;
    }

    private function mediaTable(): string
    {
        return $this->tablePrefix . 'media';
    }
}
