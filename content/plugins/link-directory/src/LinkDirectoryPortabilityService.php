<?php

/**
 * Exports and imports this installation's Link Directory categories and links as a single JSON file, for copying a directory between two Lumora Press installs.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.17.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use LumoraPress\Models\ContentFormat;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\ThumbnailService;
use RuntimeException;

/**
 * Deliberately simpler than SettingsPortabilityService's own stage()/
 * inspectStaged()/finalize() upload flow (app/Services/SettingsPortabilityService.php):
 * importing here only ever adds new categories/links, never overwrites an
 * existing option value, so there's nothing destructive a confirmation
 * screen needs to guard against — one direct import() call is enough.
 *
 * A category's own id is included in the export purely so import() can
 * rebuild parent/child relationships between categories in the *same*
 * file — it's discarded once that remapping is done and never matched
 * against ids already on the destination install.
 *
 * A thumbnail or button is a Media id, meaningless on another install, so
 * the image itself travels inside the file (base64) and import recreates it
 * as a new Media item. Without a MediaService, export omits images and
 * import skips them.
 */
final class LinkDirectoryPortabilityService
{
    private const FORMAT_VERSION = 1;

    // Keeps one oversized file from ballooning the export (base64 adds a third) or an import's memory use.
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private readonly LinkDirectoryCategoryService $categories,
        private readonly LinkService $links,
        private readonly string $appVersion,
        private readonly ?MediaService $media = null,
        private readonly ?ThumbnailService $thumbnails = null,
        private readonly string $uploadsPath = '',
        private readonly int $uploadedByUserId = 0,
    ) {
    }

    public function export(): string
    {
        $categoryRows = array_map(
            static fn (array $row): array => [
                'id' => $row['category']->id,
                'name' => $row['category']->name,
                'parentId' => $row['category']->parentId,
            ],
            $this->categories->listAllForTree(),
        );

        $linkRows = array_map(
            fn (Link $link): array => [
                'title' => $link->title,
                'url' => $link->url,
                'description' => $link->description,
                'descriptionFormat' => $link->descriptionFormat->value,
                'categoryId' => $link->categoryId,
                'thumbnail' => $this->exportImage($link->thumbnailMediaId),
                'button' => $this->exportImage($link->buttonMediaId),
            ],
            $this->links->listAllForExport(),
        );

        $payload = [
            'lumoraPressLinkDirectoryExport' => true,
            'formatVersion' => self::FORMAT_VERSION,
            'exportedAt' => date('c'),
            'exportedFromVersion' => $this->appVersion,
            'categories' => $categoryRows,
            'links' => $linkRows,
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Creates every category and link in $json on this install. Categories
     * are processed in the export's own order — listAllForTree() (the
     * export's data source) always lists a parent before its children, so
     * by the time a child row is reached, $idMap already has its parent's
     * freshly-created id. A parent id the map doesn't recognize (a
     * hand-edited or truncated file) falls back to a top-level category
     * rather than failing the whole import.
     *
     * @return array{categoriesCreated: int, linksCreated: int, imagesImported: int, imagesSkipped: int}
     */
    public function import(string $json): array
    {
        $decoded = json_decode($json, true);

        if (
            !is_array($decoded)
            || ($decoded['lumoraPressLinkDirectoryExport'] ?? false) !== true
            || !isset($decoded['categories']) || !is_array($decoded['categories'])
            || !isset($decoded['links']) || !is_array($decoded['links'])
        ) {
            throw new RuntimeException('That file is not a valid Link Directory export.');
        }

        /** @var array<int, int> $idMap exported category id => newly-created id */
        $idMap = [];
        $categoriesCreated = 0;

        foreach ($decoded['categories'] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || trim($row['name']) === '') {
                continue;
            }

            $oldParentId = $row['parentId'] ?? null;
            $newParentId = is_int($oldParentId) && isset($idMap[$oldParentId]) ? $idMap[$oldParentId] : null;

            $created = $this->categories->create(trim($row['name']), $newParentId);
            $categoriesCreated++;

            if (is_int($row['id'] ?? null)) {
                $idMap[$row['id']] = $created->id;
            }
        }

        $linksCreated = 0;
        $imagesImported = 0;
        $imagesSkipped = 0;
        /** @var array<string, int> $imageCache image hash => Media id, so one badge shared by several links is stored once */
        $imageCache = [];

        foreach ($decoded['links'] as $row) {
            if (
                !is_array($row)
                || !is_string($row['title'] ?? null) || trim($row['title']) === ''
                || !is_string($row['url'] ?? null) || trim($row['url']) === ''
            ) {
                continue;
            }

            $oldCategoryId = $row['categoryId'] ?? null;
            $newCategoryId = is_int($oldCategoryId) && isset($idMap[$oldCategoryId]) ? $idMap[$oldCategoryId] : null;
            $format = ContentFormat::tryFrom((string) ($row['descriptionFormat'] ?? '')) ?? ContentFormat::Plain;

            $mediaIds = [];

            foreach (['thumbnail', 'button'] as $imageKey) {
                if (!is_array($row[$imageKey] ?? null)) {
                    continue;
                }

                $mediaId = $this->importImage($row[$imageKey], $imageCache);

                if ($mediaId === null) {
                    $imagesSkipped++;
                } else {
                    $imagesImported++;
                }

                $mediaIds[$imageKey] = $mediaId;
            }

            $this->links->create(
                trim($row['title']),
                trim($row['url']),
                (string) ($row['description'] ?? ''),
                $format,
                $newCategoryId,
                $mediaIds['thumbnail'] ?? null,
                $mediaIds['button'] ?? null,
            );
            $linksCreated++;
        }

        return [
            'categoriesCreated' => $categoriesCreated,
            'linksCreated' => $linksCreated,
            'imagesImported' => $imagesImported,
            'imagesSkipped' => $imagesSkipped,
        ];
    }

    /**
     * @return array{fileName: string, mimeType: string, data: string}|null null when there is no image, or it can't be read or is too large
     */
    private function exportImage(?int $mediaId): ?array
    {
        if ($mediaId === null || $this->media === null) {
            return null;
        }

        $item = $this->media->find($mediaId);

        if ($item === null) {
            return null;
        }

        $path = $this->media->absolutePath($item);

        if (!is_file($path) || (int) filesize($path) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            return null;
        }

        return [
            'fileName' => (string) $item['file_name'],
            'mimeType' => (string) $item['mime_type'],
            'data' => base64_encode($bytes),
        ];
    }

    /**
     * Recreates one embedded image as a Media item. The file is untrusted
     * input, so its extension and its content-sniffed type must both be an
     * allowed image; anything else is skipped rather than failing the import.
     *
     * @param array<string, mixed> $image
     * @param array<string, int> $cache
     */
    private function importImage(array $image, array &$cache): ?int
    {
        if ($this->media === null || $this->uploadsPath === '' || !is_string($image['data'] ?? null) || !is_string($image['fileName'] ?? null)) {
            return null;
        }

        $bytes = base64_decode($image['data'], true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $hash = hash('sha256', $bytes);

        if (isset($cache[$hash])) {
            return $cache[$hash];
        }

        $extension = strtolower(pathinfo($image['fileName'], PATHINFO_EXTENSION));

        if (!in_array($extension, self::IMAGE_EXTENSIONS, true) || !$this->media->isAllowedExtension($extension)) {
            return null;
        }

        $safeName = $this->media->sanitizeFilename(pathinfo($image['fileName'], PATHINFO_FILENAME));
        $relativePath = date('Y') . '/' . date('m') . '/' . $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = rtrim($this->uploadsPath, '/') . '/' . $relativePath;

        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
            return null;
        }

        if (file_put_contents($destination, $bytes) === false) {
            return null;
        }

        $mimeType = mime_content_type($destination) ?: '';
        $dimensions = getimagesize($destination);

        if (!str_starts_with($mimeType, 'image/') || !$this->media->isAllowedMimeType($mimeType) || !is_array($dimensions)) {
            unlink($destination);

            return null;
        }

        $item = $this->media->registerExistingFile(
            relativePath: $relativePath,
            fileName: $image['fileName'],
            mimeType: $mimeType,
            fileSize: strlen($bytes),
            width: $dimensions[0],
            height: $dimensions[1],
            uploadedByUserId: $this->uploadedByUserId,
            folderId: null,
        );
        $this->thumbnails?->generate($item);

        return $cache[$hash] = (int) $item['id'];
    }
}
