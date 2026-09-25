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
final class LumoraPressZipWriter implements ExportFormatWriter
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

        if (!is_dir($this->workingDirectory) && !mkdir($this->workingDirectory, 0755, true) && !is_dir($this->workingDirectory)) {
            throw new RuntimeException('Unable to create the export directory.');
        }

        $path = rtrim($this->workingDirectory, '/') . '/' . bin2hex(random_bytes(16)) . '.zip';
        $manifest = json_encode(ExportManifest::encode($content), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the export file.');
        }

        $zip->addFromString(self::MANIFEST_NAME, $manifest);
        $missing = 0;

        if ($content->includesUploads) {
            foreach ($content->media as $media) {
                $filePath = ExportManifest::safeRelativePath($content->mediaFiles[(int) $media->externalId]['filePath'] ?? '');

                if ($filePath === '' || !is_file($media->absolutePath)) {
                    $missing++;
                    continue;
                }

                $entryName = self::UPLOADS_PREFIX . $filePath;
                $zip->addFile($media->absolutePath, $entryName);

                if (in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true)) {
                    $zip->setCompressionName($entryName, ZipArchive::CM_STORE);
                }
            }
        }

        // close() is where ZipArchive actually reads every added file, so
        // a failure (disk full, unreadable file) only surfaces here.
        if (!$zip->close()) {
            if (is_file($path)) {
                unlink($path);
            }

            throw new RuntimeException('Unable to finish writing the export file.');
        }

        if ($missing > 0) {
            $this->notices[] = "{$missing} media file" . ($missing === 1 ? ' was' : 's were') . " missing from this site's uploads folder, so only " . ($missing === 1 ? 'its details were' : 'their details were') . ' exported.';
        }

        return $path;
    }

    public function notices(): array
    {
        return $this->notices;
    }
}
