<?php

/**
 * Thumbnail generation (LP-001): derives resized copies of image uploads using GD.
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
 * Thumbnail Generation (LP-001): derives resized copies of image uploads
 * using GD only (no Imagick dependency, since GD ships on effectively all
 * shared hosting — the audience this project targets). Deliberately a
 * sibling of MediaService rather than logic bolted onto it: MediaService's
 * own docblock scopes it to "validated uploads, folders, metadata,
 * search", and thumbnailing is a distinct concern with its own storage
 * table, exactly the "focused service classes" rule in CLAUDE.md.
 *
 * There is no upload/delete hook in this codebase yet (MediaUsageChecker
 * and FolderService are also called explicitly from admin/views/media.php,
 * not via do_action), so generate()/deleteForMedia() are likewise called
 * explicitly from there rather than wired through a new event.
 *
 * WebP/AVIF are only ever produced when the running GD build actually
 * supports them (checked via function_exists()) — skipped with a logged
 * notice otherwise, never a hard failure. Metadata stripping needs no
 * separate step: GD's image* encoders never carry EXIF/ICC chunks through
 * from the source image, so every generated thumbnail is already clean.
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
     * Per-request memoization for thumbnailsFor() (LP-008 Performance) —
     * rendering one item's featured image through the theme API
     * (has_post_thumbnail()/the_post_thumbnail()/
     * the_post_thumbnail_lightbox() in include/media-functions.php) looks
     * up the same media id's thumbnail rows more than once per item. One
     * instance of this service lives for the whole request (built once in
     * bootstrap.php), so caching by media id here collapses those repeats
     * into a single query. generateOne()/deleteForMedia() evict an id's
     * entry whenever its rows actually change.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $thumbnailsForCache = [];

    /**
     * @param Closure(string): (array<string, mixed>|false)|null $readExif
     *     Overrides EXIF reading — defaults to exif_read_data(). Exists so
     *     tests can exercise orientation handling without needing a real
     *     EXIF-embedded fixture file, the same DI-for-testability pattern
     *     MediaService's $moveUploadedFile and RequirementsCheck's
     *     $extensionLoaded use.
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
        $this->readExif = $readExif ?? static fn (string $path): array|false => @exif_read_data($path);
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
     * one per id, for callers building a per-image size list across a
     * whole media library at once (LP-075's "Attachment Display
     * Settings" step needs every image's available sizes up front, and
     * an N+1 query per image there would scale badly on a library with
     * hundreds of uploads).
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
     * Manually-cropped featured image (LP-040) — a distinct concern from
     * the automatic per-size thumbnails above: this is keyed by the exact
     * crop rectangle requested (a post/page's own editorial choice), not a
     * named size, and deliberately produces a single output only, with no
     * responsive srcset variants (see media-functions.php's
     * post_thumbnail_url()) — a manual crop is a one-off decision, not a
     * systematic size like "medium"/"large". Not tracked in
     * `media_thumbnails` (that table is keyed by (media_id, size_name),
     * one row per named size — a crop has neither) — deterministic,
     * content-addressed file naming is used instead: the output filename
     * is derived from the crop rectangle itself, so two posts cropping the
     * same image identically naturally share one file, a changed crop
     * produces a new file, and an existing file for the same rectangle is
     * reused rather than regenerated. Changing or clearing a crop leaves
     * its old output file on disk (no reference-counting to know it's
     * safe to delete) — an accepted, documented gap, the same class of
     * disk-cleanup debt this project already accepts elsewhere (e.g.
     * ThemeFileEditor's backup pruning is time/count-based, not exact).
     *
     * @param array<string, mixed> $media
     * @param array{x: int, y: int, width: int, height: int} $crop Pixel
     *     rectangle against the media's original (post-EXIF-rotation)
     *     dimensions — out-of-bounds values are clamped, never rejected
     *     outright, so a stale crop against a since-replaced image with
     *     different dimensions still produces *something* sane rather
     *     than silently doing nothing.
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

        $x = max(0, min((int) $crop['x'], $sourceWidth - 1));
        $y = max(0, min((int) $crop['y'], $sourceHeight - 1));
        $width = max(1, min((int) $crop['width'], $sourceWidth - $x));
        $height = max(1, min((int) $crop['height'], $sourceHeight - $y));

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
            $source = $this->loadImage($sourcePath, $mimeType);

            if ($this->isRotatedByExif($sourcePath, $mimeType)) {
                $source = $this->applyExifOrientation($source, $sourcePath);
            }

            // Capped to the "large" size's configured width so a crop of a
            // huge source image doesn't produce an equally huge featured
            // image file — never upscaled beyond the crop's own size.
            $maxWidth = max(1, $this->sizes()['large']['width'] ?? 1024);
            $outputWidth = min($width, $maxWidth);
            $outputHeight = max(1, (int) round($outputWidth * ($height / $width)));

            $canvas = imagecreatetruecolor($outputWidth, $outputHeight);
            $this->preserveTransparency($canvas);
            imagecopyresampled($canvas, $source, 0, 0, $x, $y, $outputWidth, $outputHeight, $width, $height);
            imagedestroy($source);

            $this->encode($canvas, $destination, $mimeType);
            imagedestroy($canvas);
        } catch (Throwable $exception) {
            $this->log("Media #{$mediaId}: failed to generate manual featured-image crop: {$exception->getMessage()}");

            return null;
        }

        return ['url' => $url, 'width' => $outputWidth, 'height' => $outputHeight];
    }

    /**
     * Re-encodes an image file in place through GD's own decode/encode
     * pipeline — LP-041's "Optimize images after import", a lossy
     * recompression pass, not a dedicated lossless optimizer: no cwebp/
     * mozjpeg/etc. tooling exists in this codebase (GD only, see this
     * class's own docblock), so this reuses the exact same quality-
     * controlled encoders ($jpegQuality/$webpQuality via encode()) that
     * thumbnails already use. Always opt-in per call site — nothing here
     * runs automatically; the FTP Media Import screen only calls this when
     * an administrator checks "Optimize images after import" for that
     * specific import.
     *
     * GIFs are skipped outright: GD's decoder only reads a GIF's first
     * frame, so round-tripping an animated GIF through it would silently
     * destroy the animation. The file is only overwritten if the
     * recompressed version actually comes out smaller — a source that's
     * already well-compressed (or was uploaded below the configured
     * quality already) is left untouched rather than risking a
     * quality-for-nothing swap.
     *
     * @return bool true if the file was rewritten, false if it was left
     *     as-is (unsupported/corrupt source, a GIF, or no size win)
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
     * Removes `media_thumbnails` rows (and their files) whose parent media
     * row no longer exists. DB-level reconciliation only — not a
     * filesystem tree walk — since deleteForMedia() already keeps files
     * and rows in lockstep on the normal delete path; this exists as a
     * safety net, not the primary cleanup mechanism.
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
     * (the admin view) loop this across requests, incrementing $offset by
     * $batchSize each time until `done` is true — no queue/cron
     * infrastructure exists in this codebase, so this is the same
     * "batch-per-request" shape as everything else here.
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

        // Delete-then-insert rather than an upsert: a previous row for
        // this (media_id, size_name) may point at a differently-named
        // file (e.g. the source extension changed on re-upload), and an
        // UPDATE-only upsert would silently orphan that old file on disk
        // while the DB moved on. Explicitly removing the old row+file
        // first keeps them in lockstep, the same guarantee
        // deleteForMedia() provides. Also keeps this portable to SQLite
        // (no MySQL-only ON DUPLICATE KEY UPDATE), matching this
        // codebase's SqliteDatabaseFactory-based unit test convention.
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
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
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
