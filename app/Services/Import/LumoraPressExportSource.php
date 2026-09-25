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

    private function __construct(
        private readonly string $directory,
        private readonly ExportContent $content,
    ) {
    }

    /**
     * Extracts into a fresh directory under $workingDirectory; call
     * cleanup() once the import has copied what it needs.
     */
    public static function open(string $zipPath, string $workingDirectory): self
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The uploaded file is not a valid ZIP archive.');
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
        } catch (\Throwable $exception) {
            self::removeDirectory($directory);

            throw $exception;
        } finally {
            $zip->close();
        }

        return new self($directory, $content);
    }

    public function content(): ExportContent
    {
        return $this->content;
    }

    public function cleanup(): void
    {
        self::removeDirectory($this->directory);
    }

    /**
     * @return array{0: string, 1: string} the entry-name prefix everything else sits under, and the manifest's contents
     */
    private static function readManifest(ZipArchive $zip): array
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
