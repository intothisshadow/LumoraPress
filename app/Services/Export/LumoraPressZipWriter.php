<?php

/**
 * Writes the lossless Lumora Press export format: a ZIP holding manifest.json plus the site's uploaded files.
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

namespace LumoraPress\Services\Export;

use RuntimeException;
use ZipArchive;

/**
 * Files are stored under uploads/ at their original relative path, so a
 * reader can find each one from the manifest's own file_path alone.
 */
final class LumoraPressZipWriter implements StagedExportFormatWriter
{
    public const ID = 'lumora_press';

    public const MANIFEST_NAME = 'manifest.json';

    public const UPLOADS_PREFIX = 'uploads/';

    /**
     * Already-compressed formats are stored rather than deflated again —
     * recompressing a large photo library costs real CPU time for
     * almost no size saving.
     */
    private const STORED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp3', 'm4a', 'ogg', 'mp4', 'webm', 'mov', 'zip', 'gz', 'pdf'];

    /**
     * ZipArchive rewrites the whole archive each time it is closed, so a
     * batch is sized to keep the number of rewrites small on a big library.
     */
    private const BATCH_MAX_FILES = 500;

    private const BATCH_MAX_BYTES = 256 * 1048576;

    /** @var array<int, string> */
    private array $notices = [];

    public function __construct(private readonly string $workingDirectory)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Lumora Press (.zip)';
    }

    public function description(): string
    {
        return 'A complete, lossless copy of your content for importing into another Lumora Press site (or back into this one) from Maintenance › Import.';
    }

    public function supportedContentTypes(): array
    {
        return ExportOptions::ALL_CONTENT_TYPES;
    }

    public function bundlesUploads(): bool
    {
        return true;
    }

    public function mimeType(): string
    {
        return 'application/zip';
    }

    public function downloadFileName(ExportContent $content): string
    {
        return 'lumora-press-export-' . $content->siteSlug() . '-' . $content->exportedAt->format('Y-m-d') . '.zip';
    }

    public function write(ExportContent $content, ExportOptions $options): string
    {
        $this->notices = [];

        $path = $this->newFilePath();
        $manifest = json_encode(ExportManifest::encode($content), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the export file.');
        }

        $zip->addFromString(self::MANIFEST_NAME, $manifest);
        [$queue, $missing] = $content->includesUploads ? $this->uploadQueue($content) : [[], 0];

        foreach ($queue as [$absolutePath, $entryName]) {
            $this->addEntry($zip, $absolutePath, $entryName);
        }

        // close() is where ZipArchive actually reads every added file, so
        // a failure (disk full, unreadable file) only surfaces here.
        if (!$zip->close()) {
            if (is_file($path)) {
                unlink($path);
            }

            throw new RuntimeException('Unable to finish writing the export file.');
        }

        $this->noteMissing($missing);

        return $path;
    }

    public function begin(ExportContent $content, ExportOptions $options): array
    {
        $this->notices = [];
        $path = $this->newFilePath();
        $manifest = json_encode(ExportManifest::encode($content), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the export file.');
        }

        $zip->addFromString(self::MANIFEST_NAME, $manifest);

        if (!$zip->close()) {
            throw new RuntimeException('Unable to start the export file.');
        }

        [$queue, $missing] = $content->includesUploads ? $this->uploadQueue($content) : [[], 0];

        return [
            'path' => $path,
            'fileName' => $this->downloadFileName($content),
            'total' => count($queue),
            'cursor' => 0,
            'missing' => $missing,
            'queue' => $queue,
        ];
    }

    public function addBatch(array $state): array
    {
        $path = (string) $state['path'];
        $queue = (array) $state['queue'];
        $cursor = (int) $state['cursor'];

        if ($cursor >= count($queue)) {
            return $state;
        }

        $zip = new ZipArchive();

        if (!is_file($path) || $zip->open($path) !== true) {
            throw new RuntimeException('The export file could not be reopened.');
        }

        $files = 0;
        $bytes = 0;

        while ($cursor < count($queue) && $files < self::BATCH_MAX_FILES && $bytes < self::BATCH_MAX_BYTES) {
            [$absolutePath, $entryName] = $queue[$cursor];
            $this->addEntry($zip, (string) $absolutePath, (string) $entryName);
            $bytes += (int) @filesize((string) $absolutePath);
            $files++;
            $cursor++;
        }

        if (!$zip->close()) {
            throw new RuntimeException('Unable to write the export file.');
        }

        $state['cursor'] = $cursor;

        return $state;
    }

    public function finish(array $state): string
    {
        $this->notices = [];
        $this->noteMissing((int) ($state['missing'] ?? 0));

        return (string) $state['path'];
    }

    private function newFilePath(): string
    {
        if (!is_dir($this->workingDirectory) && !mkdir($this->workingDirectory, 0755, true) && !is_dir($this->workingDirectory)) {
            throw new RuntimeException('Unable to create the export directory.');
        }

        return rtrim($this->workingDirectory, '/') . '/' . bin2hex(random_bytes(16)) . '.zip';
    }

    /**
     * @return array{0: array<int, array{0: string, 1: string}>, 1: int} the files to add as [absolute path, entry name], and how many were missing on disk
     */
    private function uploadQueue(ExportContent $content): array
    {
        $queue = [];
        $missing = 0;

        foreach ($content->media as $media) {
            $filePath = ExportManifest::safeRelativePath($content->mediaFiles[(int) $media->externalId]['filePath'] ?? '');

            if ($filePath === '' || !is_file($media->absolutePath)) {
                $missing++;
                continue;
            }

            $queue[] = [$media->absolutePath, self::UPLOADS_PREFIX . $filePath];
        }

        return [$queue, $missing];
    }

    private function addEntry(ZipArchive $zip, string $absolutePath, string $entryName): void
    {
        $zip->addFile($absolutePath, $entryName);

        if (in_array(strtolower(pathinfo($entryName, PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true)) {
            $zip->setCompressionName($entryName, ZipArchive::CM_STORE);
        }
    }

    private function noteMissing(int $missing): void
    {
        if ($missing > 0) {
            $this->notices[] = "{$missing} media file" . ($missing === 1 ? ' was' : 's were') . " missing from this site's uploads folder, so only " . ($missing === 1 ? 'its details were' : 'their details were') . ' exported.';
        }
    }

    public function notices(): array
    {
        return $this->notices;
    }
}
