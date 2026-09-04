<?php

/**
 * Thumbnail generation: derives resized copies of image uploads using GD.
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

use Closure;
use GdImage;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;
use RuntimeException;
use Throwable;

/**
 * Thumbnail Generation: derives resized copies of image uploads using
 * GD only (no Imagick dependency, since GD ships on effectively all
 * shared hosting). A focused sibling of MediaService, not logic bolted
 * onto it, since thumbnailing is a distinct concern with its own storage table.
 *
 * WebP/AVIF are only produced when the running GD build supports them —
 * skipped with a logged notice otherwise, never a hard failure.
 * Metadata stripping needs no separate step: GD's encoders never carry
 * EXIF/ICC chunks through from the source.
 */
final class ThumbnailService
{
    /**
     * @var array<string, array{width: int, height: int, mode: string, enabled: bool}>
     */
    private const DEFAULT_SIZES = [
        'small' => ['width' => 150, 'height' => 150, 'mode' => 'crop', 'enabled' => true],
        'medium' => ['width' => 300, 'height' => 300, 'mode' => 'fit', 'enabled' => true],
        'large' => ['width' => 1024, 'height' => 1024, 'mode' => 'fit', 'enabled' => true],
    ];

    private const DEFAULT_MAX_PIXELS = 25_000_000;

    private readonly Closure $readExif;

    /**
     * Per-request memoization for thumbnailsFor() — rendering one item's
     * featured image through the theme API looks up the same media id's
     * thumbnail rows more than once per item. generateOne()/
     * deleteForMedia() evict an id's entry whenever its rows change.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $thumbnailsForCache = [];

    /**
     * @param Closure(string): (array<string, mixed>|false)|null $readExif
     *     Overrides EXIF reading — defaults to exif_read_data() only when
     *     the exif extension is loaded (shared hosting frequently omits
     *     it). Exists so tests can exercise orientation handling without
     *     a real EXIF-embedded fixture. isRotatedByExif()/
     *     applyExifOrientation() call $readExif directly and must not
     *     re-gate on function_exists('exif_read_data') themselves, or an
     *     injected closure would be ignored when the extension isn't loaded.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly string $uploadsPath,
        private readonly string $uploadsUrl,
        private readonly PressConfig $config,
        private readonly HookManager $hooks,
        private readonly MediaService $media,
        private readonly string $logDirectory,
        ?Closure $readExif = null,
    ) {
        $this->readExif = $readExif ?? static fn (string $path): array|false =>
            function_exists('exif_read_data') ? @exif_read_data($path) : false;
    }

    /**
     * @return array<string, array{width: int, height: int, mode: string, enabled: bool}>
     */
    public function sizes(): array
    {
        $sizes = [];

        foreach (self::DEFAULT_SIZES as $name => $defaults) {
            $sizes[$name] = [
                'width' => max(1, (int) $this->config->option("thumbnail_size_{$name}_width", (string) $defaults['width'])),
                'height' => max(1, (int) $this->config->option("thumbnail_size_{$name}_height", (string) $defaults['height'])),
                'mode' => $this->config->option("thumbnail_size_{$name}_mode", $defaults['mode']) === 'crop' ? 'crop' : 'fit',
                'enabled' => $this->config->option("thumbnail_size_{$name}_enabled", '1') !== '0',
            ];
        }

        /** @var array<string, array{width: int, height: int, mode: string, enabled: bool}> $sizes */
        $sizes = $this->hooks->applyFilters('thumbnail_sizes', $sizes);

        return $sizes;
    }

    /**
     * No-ops for non-image media. Wraps every size in its own try/catch —
     * a corrupt or unsupported source image is logged and recorded in
     * `skipped`, never thrown, so one bad file can't abort a bulk run.
     *
     * @param array<string, mixed> $media
     * @return array{generated: array<string, array{width: int, height: int, path: string}>, skipped: array<string, string>}
     */
    public function generate(array $media): array
    {
        $generated = [];
        $skipped = [];

        if (!str_starts_with((string) $media['mime_type'], 'image/')) {
            return ['generated' => $generated, 'skipped' => $skipped];
        }

        $mediaId = (int) $media['id'];
        $sourcePath = rtrim($this->uploadsPath, '/') . '/' . $media['file_path'];

        if (!is_file($sourcePath)) {
            return ['generated' => $generated, 'skipped' => $skipped];
        }

        $configuredMaxPixels = (int) $this->config->option('thumbnail_max_pixels', (string) self::DEFAULT_MAX_PIXELS);
        $maxPixels = (int) $this->hooks->applyFilters('thumbnail_max_pixels', $configuredMaxPixels);
        $dimensions = @getimagesize($sourcePath);

        if ($dimensions === false) {
            $this->log("Media #{$mediaId}: unable to read image dimensions, skipping all sizes.");

            foreach ($this->sizes() as $name => $size) {
                if ($size['enabled']) {
                    $skipped[$name] = 'Unable to read image dimensions.';
                }
            }

            return ['generated' => $generated, 'skipped' => $skipped];
        }

        [$sourceWidth, $sourceHeight] = $dimensions;

        if ($sourceWidth * $sourceHeight > $maxPixels) {
            $reason = "Source image ({$sourceWidth}x{$sourceHeight}) exceeds the {$maxPixels}-pixel safeguard.";
            $this->log("Media #{$mediaId}: {$reason}");

            foreach ($this->sizes() as $name => $size) {
                if ($size['enabled']) {
                    $skipped[$name] = $reason;
                }
            }

            return ['generated' => $generated, 'skipped' => $skipped];
        }

        foreach ($this->sizes() as $sizeName => $size) {
            if (!$size['enabled']) {
                continue;
            }

            try {
                $result = $this->generateOne($media, $sourcePath, $sourceWidth, $sourceHeight, $sizeName, $size);

                if ($result === null) {
                    $skipped[$sizeName] = 'Source image is smaller than the target size (upscaling is not allowed).';

                    continue;
                }

                $generated[$sizeName] = $result;
                $this->hooks->doAction('thumbnail_generated', $mediaId, $sizeName, $result['path']);
            } catch (Throwable $exception) {
                $skipped[$sizeName] = $exception->getMessage();
                $this->log("Media #{$mediaId}, size \"{$sizeName}\": {$exception->getMessage()}");
                $this->hooks->doAction('thumbnail_generation_failed', $mediaId, $sizeName, $exception->getMessage());
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Deletes any existing thumbnails for this media id, then regenerates.
     *
     * @return array{generated: array<string, array{width: int, height: int, path: string}>, skipped: array<string, string>}
     */
    public function regenerate(int $mediaId): array
    {
        $media = $this->media->find($mediaId);

        if ($media === null) {
            return ['generated' => [], 'skipped' => []];
        }

        $this->deleteForMedia($mediaId);

        return $this->generate($media);
    }

    public function deleteForMedia(int $mediaId): void
    {
        foreach ($this->thumbnailsFor($mediaId) as $thumbnail) {
            $path = rtrim($this->uploadsPath, '/') . '/' . $thumbnail['file_path'];

            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE media_id = :media_id', ['media_id' => $mediaId]);

        unset($this->thumbnailsForCache[$mediaId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function thumbnailsFor(int $mediaId): array
    {
        if (array_key_exists($mediaId, $this->thumbnailsForCache)) {
            return $this->thumbnailsForCache[$mediaId];
        }

        return $this->thumbnailsForCache[$mediaId] = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE media_id = :media_id ORDER BY size_name ASC',
            ['media_id' => $mediaId],
        );
    }

    /**
     * Bulk form of thumbnailsFor() — one query for every id rather than
     * one per id, so building a per-image size list across a whole
     * library doesn't scale badly with an N+1 query per image.
     *
     * @param array<int, int> $mediaIds
     * @return array<int, array<int, array<string, mixed>>> media_id => its thumbnail rows
     */
    public function thumbnailsForMany(array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_map('intval', $mediaIds)));

        if ($mediaIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($mediaIds as $i => $mediaId) {
            $key = "media_id_{$i}";
            $placeholders[] = ':' . $key;
            $params[$key] = $mediaId;
        }

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE media_id IN (' . implode(',', $placeholders) . ') ORDER BY size_name ASC',
            $params,
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['media_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $media
     */
    public function url(array $media, string $size): ?string
    {
        $row = $this->database->fetchOne(
            'SELECT file_path FROM ' . $this->table() . ' WHERE media_id = :media_id AND size_name = :size_name',
            ['media_id' => (int) $media['id'], 'size_name' => $size],
        );

        return $row === null ? null : rtrim($this->uploadsUrl, '/') . '/' . $row['file_path'];
    }

    /**
     * Manually-cropped featured image, keyed by the exact crop rectangle rather than a named
     * size, producing a single output with no responsive srcset variants. Not tracked in
     * `media_thumbnails`; deterministic, content-addressed file naming means identical crops
     * share one file. Changing/clearing a crop leaves the old output file on disk.
     *
     * @param array<string, mixed> $media
     * @param array{x: int, y: int, width: int, height: int} $crop Pixel rectangle against the
     *     original dimensions — out-of-bounds values are clamped, never rejected.
     * @return array{url: string, width: int, height: int}|null
     */
    public function generateFeaturedCrop(array $media, array $crop): ?array
    {
        if (!str_starts_with((string) $media['mime_type'], 'image/')) {
            return null;
        }

        $mediaId = (int) $media['id'];
        $sourcePath = rtrim($this->uploadsPath, '/') . '/' . $media['file_path'];

        if (!is_file($sourcePath)) {
            return null;
        }

        $dimensions = @getimagesize($sourcePath);

        if ($dimensions === false) {
            return null;
        }

        [$sourceWidth, $sourceHeight] = $dimensions;
        [$x, $y, $width, $height] = $this->clampCrop($crop, $sourceWidth, $sourceHeight);

        $hash = substr(sha1("{$mediaId}:{$x},{$y},{$width},{$height}"), 0, 12);
        $extension = strtolower(pathinfo((string) $media['file_path'], PATHINFO_EXTENSION));
        $basename = pathinfo((string) $media['file_path'], PATHINFO_FILENAME);
        $directory = dirname((string) $media['file_path']);
        $relativePath = ($directory === '.' ? '' : $directory . '/') . "{$basename}-featured-{$hash}.{$extension}";
        $destination = rtrim($this->uploadsPath, '/') . '/' . $relativePath;
        $url = rtrim($this->uploadsUrl, '/') . '/' . $relativePath;

        if (is_file($destination)) {
            $existingDimensions = @getimagesize($destination);

            if ($existingDimensions !== false) {
                return ['url' => $url, 'width' => $existingDimensions[0], 'height' => $existingDimensions[1]];
            }
        }

        $mimeType = (string) $media['mime_type'];

        try {
            $output = $this->cropResizeAndEncode($sourcePath, $mimeType, $x, $y, $width, $height, $this->featuredCropMaxWidth(), $destination);
        } catch (Throwable $exception) {
            $this->log("Media #{$mediaId}: failed to generate manual featured-image crop: {$exception->getMessage()}");

            return null;
        }

        return ['url' => $url, 'width' => $output['width'], 'height' => $output['height']];
    }

    /**
     * Crops $sourceMedia into a brand-new, independent Media Library item — unlike
     * generateFeaturedCrop()'s cache file, this is reachable from Media Manager and needs
     * its own row, thumbnails, and uniquely-named file. The original file is never touched.
     *
     * @param array<string, mixed> $sourceMedia
     * @param array{x: int, y: int, width: int, height: int} $crop
     * @return array<string, mixed>|null null if $sourceMedia isn't an image, its file is
     *     missing, or the crop/encode step failed
     */
    public function createCroppedFeaturedMedia(array $sourceMedia, array $crop, int $uploadedByUserId, ?int $folderId = null): ?array
    {
        $mimeType = (string) $sourceMedia['mime_type'];

        if (!str_starts_with($mimeType, 'image/')) {
            return null;
        }

        $sourceMediaId = (int) $sourceMedia['id'];
        $sourcePath = rtrim($this->uploadsPath, '/') . '/' . $sourceMedia['file_path'];

        if (!is_file($sourcePath)) {
            return null;
        }

        $dimensions = @getimagesize($sourcePath);

        if ($dimensions === false) {
            return null;
        }

        [$sourceWidth, $sourceHeight] = $dimensions;
        [$x, $y, $width, $height] = $this->clampCrop($crop, $sourceWidth, $sourceHeight);

        $extension = strtolower(pathinfo((string) $sourceMedia['file_path'], PATHINFO_EXTENSION));
        $basename = pathinfo((string) $sourceMedia['file_path'], PATHINFO_FILENAME);
        $directory = dirname((string) $sourceMedia['file_path']);
        $fileName = $basename . '-crop-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $relativePath = ($directory === '.' ? '' : $directory . '/') . $fileName;
        $destination = rtrim($this->uploadsPath, '/') . '/' . $relativePath;

        try {
            $output = $this->cropResizeAndEncode($sourcePath, $mimeType, $x, $y, $width, $height, $this->featuredCropMaxWidth(), $destination);
        } catch (Throwable $exception) {
            $this->log("Media #{$sourceMediaId}: failed to create cropped featured media: {$exception->getMessage()}");

            return null;
        }

        $newMedia = $this->media->registerExistingFile(
            relativePath: $relativePath,
            fileName: $fileName,
            mimeType: $mimeType,
            fileSize: (int) (filesize($destination) ?: 0),
            width: $output['width'],
            height: $output['height'],
            uploadedByUserId: $uploadedByUserId,
            folderId: $folderId,
        );

        $sourceAltText = (string) ($sourceMedia['alt_text'] ?? '');

        if ($sourceAltText !== '') {
            $this->media->updateMetadata((int) $newMedia['id'], $sourceAltText, null, null, null);
            $newMedia = $this->media->find((int) $newMedia['id']) ?? $newMedia;
        }

        $this->generate($newMedia);

        return $newMedia;
    }

    /**
     * The output width every manually-cropped featured image is capped
     * to — the `featured_image_crop_size` option, naming one of the
     * currently enabled thumbnail sizes. Falls back to `large`, then a
     * fixed 1024px, if the configured size was since disabled or removed.
     */
    private function featuredCropMaxWidth(): int
    {
        $sizes = $this->sizes();
        $configuredName = (string) $this->config->option('featured_image_crop_size', 'large');

        if (isset($sizes[$configuredName]) && $sizes[$configuredName]['enabled']) {
            return max(1, $sizes[$configuredName]['width']);
        }

        return max(1, $sizes['large']['width'] ?? 1024);
    }

    /**
     * Clamps a requested crop rectangle to the source image's actual
     * dimensions — out-of-bounds values are clamped, never rejected
     * outright, so a stale crop against a since-replaced image with
     * different dimensions still produces *something* sane rather than
     * silently doing nothing.
     *
     * @param array{x: int, y: int, width: int, height: int} $crop
     * @return array{0: int, 1: int, 2: int, 3: int} x, y, width, height
     */
    private function clampCrop(array $crop, int $sourceWidth, int $sourceHeight): array
    {
        $x = max(0, min((int) $crop['x'], $sourceWidth - 1));
        $y = max(0, min((int) $crop['y'], $sourceHeight - 1));
        $width = max(1, min((int) $crop['width'], $sourceWidth - $x));
        $height = max(1, min((int) $crop['height'], $sourceHeight - $y));

        return [$x, $y, $width, $height];
    }

    /**
     * Shared crop + EXIF-rotate + resample + encode pipeline behind both
     * generateFeaturedCrop() and createCroppedFeaturedMedia() — never
     * upscaled beyond the crop's own width, capped to $maxWidth.
     *
     * @return array{width: int, height: int}
     */
    private function cropResizeAndEncode(string $sourcePath, string $mimeType, int $x, int $y, int $width, int $height, int $maxWidth, string $destination): array
    {
        $source = $this->loadImage($sourcePath, $mimeType);

        if ($this->isRotatedByExif($sourcePath, $mimeType)) {
            $source = $this->applyExifOrientation($source, $sourcePath);
        }

        $outputWidth = min($width, max(1, $maxWidth));
        $outputHeight = max(1, (int) round($outputWidth * ($height / $width)));

        $canvas = imagecreatetruecolor($outputWidth, $outputHeight);
        $this->preserveTransparency($canvas);
        imagecopyresampled($canvas, $source, 0, 0, $x, $y, $outputWidth, $outputHeight, $width, $height);
        imagedestroy($source);

        $this->encode($canvas, $destination, $mimeType);
        imagedestroy($canvas);

        return ['width' => $outputWidth, 'height' => $outputHeight];
    }

    /**
     * Re-encodes an image file in place through GD's decode/encode pipeline — a lossy
     * recompression pass (GD only, no cwebp/mozjpeg). Always opt-in per call site. GIFs are
     * skipped, since GD only reads a GIF's first frame and would destroy the animation.
     *
     * @return bool true if the file was rewritten, false if left as-is
     */
    public function optimizeInPlace(string $path, string $mimeType): bool
    {
        if ($mimeType === 'image/gif' || !is_file($path)) {
            return false;
        }

        try {
            $canvas = $this->loadImage($path, $mimeType);
        } catch (Throwable) {
            return false;
        }

        $originalSize = filesize($path);
        $temporaryPath = $path . '.optimize-' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $this->encode($canvas, $temporaryPath, $mimeType);
        } catch (Throwable $exception) {
            imagedestroy($canvas);
            @unlink($temporaryPath);
            $this->log("Failed to optimize \"{$path}\": {$exception->getMessage()}");

            return false;
        }

        imagedestroy($canvas);

        $optimizedSize = is_file($temporaryPath) ? filesize($temporaryPath) : false;

        if ($optimizedSize === false || $originalSize === false || $optimizedSize >= $originalSize) {
            @unlink($temporaryPath);

            return false;
        }

        return rename($temporaryPath, $path);
    }

    /**
     * Removes `media_thumbnails` rows (and their files) whose parent
     * media row no longer exists — a safety net, not the primary cleanup
     * mechanism (deleteForMedia() keeps files/rows in lockstep normally).
     */
    public function deleteOrphaned(): int
    {
        $orphans = $this->database->fetchAll(
            'SELECT t.* FROM ' . $this->table() . ' t
             LEFT JOIN ' . $this->mediaTable() . ' m ON m.id = t.media_id
             WHERE m.id IS NULL',
        );

        foreach ($orphans as $orphan) {
            $path = rtrim($this->uploadsPath, '/') . '/' . $orphan['file_path'];

            if (is_file($path)) {
                unlink($path);
            }

            $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => (int) $orphan['id']]);
        }

        return count($orphans);
    }

    /**
     * Processes one batch of image media for bulk regeneration. Callers
     * loop this across requests, incrementing $offset by $batchSize each
     * time until `done` is true — no queue/cron infrastructure exists here.
     *
     * @return array{processed: int, total: int, done: bool}
     */
    public function queueForBulkRegeneration(bool $missingOnly, int $offset, int $batchSize = 10): array
    {
        $result = $this->media->query(['type' => 'image'], $batchSize, $offset);
        $total = $result['total'];
        $processed = 0;

        foreach ($result['items'] as $item) {
            $mediaId = (int) $item['id'];

            if ($missingOnly && $this->hasAllEnabledSizes($mediaId)) {
                continue;
            }

            $this->regenerate($mediaId);
            $processed++;
        }

        return [
            'processed' => count($result['items']),
            'total' => $total,
            'done' => ($offset + $batchSize) >= $total,
        ];
    }

    private function hasAllEnabledSizes(int $mediaId): bool
    {
        $existing = array_column($this->thumbnailsFor($mediaId), 'size_name');
        $enabledNames = array_keys(array_filter($this->sizes(), static fn (array $size): bool => $size['enabled']));

        return array_diff($enabledNames, $existing) === [];
    }

    /**
     * @param array<string, mixed> $media
     * @param array{width: int, height: int, mode: string, enabled: bool} $size
     * @return array{width: int, height: int, path: string}|null null means
     *     "correctly skipped" (upscale prevention), not a failure.
     */
    private function generateOne(array $media, string $sourcePath, int $sourceWidth, int $sourceHeight, string $sizeName, array $size): ?array
    {
        if ($sourceWidth <= $size['width'] && $sourceHeight <= $size['height']) {
            return null;
        }

        $mimeType = (string) $media['mime_type'];
        $source = $this->loadImage($sourcePath, $mimeType);

        if ($this->isRotatedByExif($sourcePath, $mimeType)) {
            $source = $this->applyExifOrientation($source, $sourcePath);
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
        }

        [$targetWidth, $targetHeight] = $this->targetDimensions($sourceWidth, $sourceHeight, $size);

        if ($size['mode'] === 'crop') {
            $canvas = $this->resizeCrop($source, $sourceWidth, $sourceHeight, $size['width'], $size['height']);
            $targetWidth = $size['width'];
            $targetHeight = $size['height'];
        } else {
            $canvas = $this->resizeFit($source, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight);
        }

        imagedestroy($source);

        if ($this->config->option('thumbnail_sharpen', '0') === '1') {
            $this->sharpen($canvas);
        }

        $extension = strtolower(pathinfo((string) $media['file_path'], PATHINFO_EXTENSION));
        $basename = pathinfo((string) $media['file_path'], PATHINFO_FILENAME);
        $directory = dirname((string) $media['file_path']);
        $relativePath = ($directory === '.' ? '' : $directory . '/') . "{$basename}-{$sizeName}.{$extension}";
        $destination = rtrim($this->uploadsPath, '/') . '/' . $relativePath;

        $this->encode($canvas, $destination, $mimeType);
        imagedestroy($canvas);

        // Delete-then-insert rather than an upsert: a previous row may point at a differently-named file, and an UPDATE-only upsert would orphan it on disk. Also keeps this portable to SQLite.
        $existing = $this->database->fetchOne(
            'SELECT file_path FROM ' . $this->table() . ' WHERE media_id = :media_id AND size_name = :size_name',
            ['media_id' => (int) $media['id'], 'size_name' => $sizeName],
        );

        if ($existing !== null) {
            $existingPath = rtrim($this->uploadsPath, '/') . '/' . $existing['file_path'];

            if ($existingPath !== $destination && is_file($existingPath)) {
                unlink($existingPath);
            }

            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE media_id = :media_id AND size_name = :size_name',
                ['media_id' => (int) $media['id'], 'size_name' => $sizeName],
            );
        }

        $this->database->execute(
            'INSERT INTO ' . $this->table() . ' (media_id, size_name, file_path, width, height, created_at)
             VALUES (:media_id, :size_name, :file_path, :width, :height, :created_at)',
            [
                'media_id' => (int) $media['id'],
                'size_name' => $sizeName,
                'file_path' => $relativePath,
                'width' => $targetWidth,
                'height' => $targetHeight,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );

        unset($this->thumbnailsForCache[(int) $media['id']]);

        return ['width' => $targetWidth, 'height' => $targetHeight, 'path' => $relativePath];
    }

    /**
     * @param array{width: int, height: int, mode: string, enabled: bool} $size
     * @return array{0: int, 1: int}
     */
    private function targetDimensions(int $sourceWidth, int $sourceHeight, array $size): array
    {
        $ratio = min($size['width'] / $sourceWidth, $size['height'] / $sourceHeight);

        return [
            max(1, (int) round($sourceWidth * $ratio)),
            max(1, (int) round($sourceHeight * $ratio)),
        ];
    }

    private function resizeFit(GdImage $source, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight): GdImage
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $this->preserveTransparency($canvas);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        return $canvas;
    }

    private function resizeCrop(GdImage $source, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight): GdImage
    {
        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
        }

        $cropX = (int) (($sourceWidth - $cropWidth) / 2);
        $cropY = (int) (($sourceHeight - $cropHeight) / 2);

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $this->preserveTransparency($canvas);
        imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);

        return $canvas;
    }

    private function preserveTransparency(GdImage $canvas): void
    {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefill($canvas, 0, 0, $transparent);
        }
    }

    /**
     * A fixed unsharp-style convolution kernel — a toggle, not a
     * configurable-strength filter, to keep this bounded.
     */
    private function sharpen(GdImage $canvas): void
    {
        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        $divisor = 8;
        imageconvolution($canvas, $matrix, $divisor, 0);
    }

    private function loadImage(string $path, string $mimeType): GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? imagecreatefromavif($path) : false,
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException("Unsupported or corrupt image (\"{$mimeType}\").");
        }

        return $image;
    }

    private function isRotatedByExif(string $path, string $mimeType): bool
    {
        if ($mimeType !== 'image/jpeg') {
            return false;
        }

        $exif = ($this->readExif)($path);

        return is_array($exif) && isset($exif['Orientation']) && (int) $exif['Orientation'] !== 1;
    }

    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        $exif = ($this->readExif)($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated === false) {
            return $image;
        }

        if ($rotated !== $image) {
            imagedestroy($image);
        }

        return $rotated;
    }

    private function encode(GdImage $canvas, string $destination, string $mimeType): void
    {
        $jpegQuality = max(0, min(100, (int) $this->config->option('thumbnail_jpeg_quality', '82')));
        $webpQuality = max(0, min(100, (int) $this->config->option('thumbnail_webp_quality', '80')));

        $success = match ($mimeType) {
            'image/jpeg' => imagejpeg($canvas, $destination, $jpegQuality),
            'image/png' => imagepng($canvas, $destination),
            'image/gif' => imagegif($canvas, $destination),
            'image/webp' => function_exists('imagewebp') ? imagewebp($canvas, $destination, $webpQuality) : false,
            'image/avif' => function_exists('imageavif') ? imageavif($canvas, $destination) : false,
            default => false,
        };

        if ($success === false) {
            throw new RuntimeException("Unable to write the generated thumbnail to \"{$destination}\".");
        }
    }

    private function log(string $message): void
    {
        $logFile = rtrim($this->logDirectory, '/') . '/thumbnails.log';

        if (!is_dir($this->logDirectory) || !is_writable($this->logDirectory)) {
            return;
        }

        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $logFile);
    }

    private function table(): string
    {
        return $this->tablePrefix . 'media_thumbnails';
    }

    private function mediaTable(): string
    {
        return $this->tablePrefix . 'media';
    }
}
