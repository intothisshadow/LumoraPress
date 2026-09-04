<?php

/**
 * CRUD for the downloads table: the admin-facing identity/metadata layer sitting on top of Media items and Redirects.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use DateTimeImmutable;
use InvalidArgumentException;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\MediaStatsService;
use LumoraPress\Services\RedirectService;
use LumoraPress\Services\ThumbnailService;
use RuntimeException;

/**
 * Doesn't reimplement file storage or URL redirection: a File download's
 * bytes are a normal MediaService-managed Media row; a Url download's link
 * is a normal RedirectService-managed Redirect row. This table only adds a
 * real title/description and a place to list/edit downloads by category.
 *
 * $folderId is passed straight through to the underlying Media/Redirect row
 * so a download stays visible to code that queries Media/Redirects by
 * folder_id directly (e.g. the WordPress Importer's own shortcode).
 * $categoryId is this download's real, separate categorization.
 */
final class DownloadService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly MediaService $media,
        private readonly RedirectService $redirects,
        private readonly DownloadCategoryService $categories,
        private readonly ?ThumbnailService $thumbnails = null,
        // Optional so this class stays constructible without MediaStatsService
        // wherever a caller has no need for downloadCount()'s File-type branch.
        private readonly ?MediaStatsService $mediaStats = null,
    ) {
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file required (and only used) when $type is File
     */
    public function create(
        string $title,
        string $description,
        ?int $folderId,
        DownloadType $type,
        ?array $file,
        ?string $externalUrl,
        int $uploadedByUserId,
        ContentFormat $descriptionFormat = ContentFormat::Plain,
        ?int $categoryId = null,
    ): Download {
        if ($type === DownloadType::File) {
            if ($file === null) {
                throw new InvalidArgumentException('A file is required for a file-type download.');
            }

            $media = $this->media->upload($file, $uploadedByUserId, $folderId);
            $this->media->updateMetadata((int) $media['id'], null, null, $description, null);
            $this->thumbnails?->generate($media);
            $mediaId = (int) $media['id'];
            $redirectId = null;
        } else {
            $externalUrl = trim((string) $externalUrl);

            if ($externalUrl === '') {
                throw new InvalidArgumentException('A URL is required for a url-type download.');
            }

            $redirect = $this->redirects->create($this->generateUniqueSourcePath($title), $externalUrl, 301, $folderId);
            $redirectId = (int) $redirect['id'];
            $mediaId = null;
        }

        return $this->recordExisting($title, $description, $folderId, $type, $mediaId, $redirectId, $descriptionFormat, categoryId: $categoryId);
    }

    /**
     * Inserts a `downloads` row for a Media/Redirect that already exists —
     * the shared tail create() uses, and the entry point for a caller (e.g.
     * a WordPress import) that created that row itself and only needs the
     * identity/metadata layer attached on top.
     */
    public function recordExisting(string $title, string $description, ?int $folderId, DownloadType $type, ?int $mediaId, ?int $redirectId, ContentFormat $descriptionFormat = ContentFormat::Plain, ?int $thumbnailMediaId = null, bool $mediaOwned = true, ?int $categoryId = null): Download
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, description, description_format, folder_id, category_id, type, media_id, media_owned, thumbnail_media_id, redirect_id, created_at, updated_at)
             VALUES (:title, :description, :description_format, :folder_id, :category_id, :type, :media_id, :media_owned, :thumbnail_media_id, :redirect_id, :created_at, :updated_at)',
            [
                'title' => $title,
                'description' => $description,
                'description_format' => $descriptionFormat->value,
                'folder_id' => $folderId,
                'category_id' => $categoryId,
                'type' => $type->value,
                'media_id' => $mediaId,
                'media_owned' => $mediaOwned ? 1 : 0,
                'thumbnail_media_id' => $thumbnailMediaId,
                'redirect_id' => $redirectId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $download = $this->findById((int) $id);

        if ($download === null) {
            throw new RuntimeException('Failed to load the download that was just created.');
        }

        return $download;
    }

    /**
     * $externalUrl only applies to an already Url-typed download — swapping
     * a file or converting between types is replaceFile()/convertToFile()/
     * convertToUrl()'s job, called separately. $descriptionFormat left null
     * keeps the existing stored format.
     */
    public function update(int $id, string $title, string $description, ?int $folderId, ?string $externalUrl, ?ContentFormat $descriptionFormat = null, ?int $categoryId = null): bool
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return false;
        }

        if ($row['type'] === DownloadType::Url->value && $row['redirect_id'] !== null && $externalUrl !== null && trim($externalUrl) !== '') {
            $redirect = $this->redirects->find((int) $row['redirect_id']);

            if ($redirect !== null) {
                $this->redirects->update((int) $row['redirect_id'], (string) $redirect['source_path'], trim($externalUrl), (int) $redirect['status_code']);
            }
        }

        if ($row['type'] === DownloadType::File->value && $row['media_id'] !== null) {
            $this->media->updateMetadata((int) $row['media_id'], null, null, $description, null);
        }

        $resolvedDescriptionFormat = $descriptionFormat ?? ContentFormat::tryFrom((string) ($row['description_format'] ?? '')) ?? ContentFormat::Plain;

        return $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, description = :description, description_format = :description_format, folder_id = :folder_id, category_id = :category_id, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'description' => $description,
                'description_format' => $resolvedDescriptionFormat->value,
                'folder_id' => $folderId,
                'category_id' => $categoryId,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;
    }

    /**
     * "Add from server": attaches an already-uploaded Media item as a
     * File-typed download's file instead of uploading a new one.
     */
    public function createFromExistingMedia(string $title, string $description, ?int $folderId, int $mediaId, ContentFormat $descriptionFormat = ContentFormat::Plain, ?int $categoryId = null): Download
    {
        if ($this->media->find($mediaId) === null) {
            throw new InvalidArgumentException('The selected file could not be found.');
        }

        // mediaOwned: false — this Media row predates the download,
        // so delete()/replaceFile() must never delete it on its account.
        return $this->recordExisting($title, $description, $folderId, DownloadType::File, $mediaId, null, $descriptionFormat, mediaOwned: false, categoryId: $categoryId);
    }

    /**
     * Repoints a File-typed download at a different, already-existing Media
     * item; never creates one itself. No-op for a Url-typed download.
     *
     * The old Media row is deleted only when it was owned and no other
     * download still references it (duplicate() shares rather than copies
     * rows, so this must not orphan a sibling). $thumbnailMediaId is left
     * untouched since swapping the file doesn't invalidate it.
     */
    public function replaceFile(int $id, int $newMediaId, bool $newMediaOwned = true): bool
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        if ($row === null || $row['type'] !== DownloadType::File->value) {
            return false;
        }

        if ($this->media->find($newMediaId) === null) {
            return false;
        }

        $oldMediaId = $row['media_id'] !== null ? (int) $row['media_id'] : null;
        $oldMediaOwned = (int) ($row['media_owned'] ?? 1) === 1;

        $updated = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET media_id = :media_id, media_owned = :media_owned, updated_at = :updated_at WHERE id = :id',
            ['media_id' => $newMediaId, 'media_owned' => $newMediaOwned ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;

        if ($updated && $oldMediaOwned && $oldMediaId !== null && $oldMediaId !== $newMediaId && !$this->referencedByAnotherDownload('media_id', $oldMediaId, $id)) {
            $this->media->delete($oldMediaId);
        }

        return $updated;
    }

    /**
     * Converts a Url-typed download into a File-typed one. $newMediaId/
     * $newMediaOwned work like replaceFile()'s own.
     *
     * The old Redirect is deleted when no other download still references
     * it — no ownership check is needed since every Redirect a download has
     * was created specifically for it (unlike Media, none is ever attached
     * pre-existing). No-op for a download that's already File-typed.
     */
    public function convertToFile(int $id, int $newMediaId, bool $newMediaOwned = true): bool
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        if ($row === null || $row['type'] !== DownloadType::Url->value) {
            return false;
        }

        if ($this->media->find($newMediaId) === null) {
            return false;
        }

        $oldRedirectId = $row['redirect_id'] !== null ? (int) $row['redirect_id'] : null;

        $updated = $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET type = :type, media_id = :media_id, media_owned = :media_owned, redirect_id = NULL, updated_at = :updated_at
              WHERE id = :id',
            [
                'type' => DownloadType::File->value,
                'media_id' => $newMediaId,
                'media_owned' => $newMediaOwned ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;

        if ($updated && $oldRedirectId !== null && !$this->referencedByAnotherDownload('redirect_id', $oldRedirectId, $id)) {
            $this->redirects->delete($oldRedirectId);
        }

        return $updated;
    }

    /**
     * Converts a File-typed download into a Url-typed one, convertToFile()'s
     * mirror. Creates a fresh Redirect via generateUniqueSourcePath(); never
     * renames an existing Redirect's source path.
     *
     * The old Media row is deleted only when owned and unreferenced
     * elsewhere — the same guard replaceFile() uses. No-op for a download
     * that's already Url-typed, an unknown $id, or a blank $externalUrl.
     */
    public function convertToUrl(int $id, string $externalUrl): bool
    {
        $externalUrl = trim($externalUrl);

        if ($externalUrl === '') {
            return false;
        }

        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        if ($row === null || $row['type'] !== DownloadType::File->value) {
            return false;
        }

        $oldMediaId = $row['media_id'] !== null ? (int) $row['media_id'] : null;
        $oldMediaOwned = (int) ($row['media_owned'] ?? 1) === 1;
        $folderId = $row['folder_id'] !== null ? (int) $row['folder_id'] : null;

        $redirect = $this->redirects->create($this->generateUniqueSourcePath((string) $row['title']), $externalUrl, 301, $folderId);

        $updated = $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET type = :type, media_id = NULL, media_owned = 1, redirect_id = :redirect_id, updated_at = :updated_at
              WHERE id = :id',
            [
                'type' => DownloadType::Url->value,
                'redirect_id' => (int) $redirect['id'],
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;

        if ($updated && $oldMediaOwned && $oldMediaId !== null && !$this->referencedByAnotherDownload('media_id', $oldMediaId, $id)) {
            $this->media->delete($oldMediaId);
        }

        return $updated;
    }

    /**
     * Deletes the linked Media or Redirect row too, so nothing is orphaned.
     * Media is only cascaded when owned and no other download row still
     * references it (duplicate() shares rather than copies rows). A
     * Redirect is always considered owned — none is ever pre-existing.
     */
    public function delete(int $id): bool
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return false;
        }

        $mediaOwned = (int) ($row['media_owned'] ?? 1) === 1;

        if ($mediaOwned && $row['media_id'] !== null && !$this->referencedByAnotherDownload('media_id', (int) $row['media_id'], $id)) {
            $this->media->delete((int) $row['media_id']);
        }

        if ($row['redirect_id'] !== null && !$this->referencedByAnotherDownload('redirect_id', (int) $row['redirect_id'], $id)) {
            $this->redirects->delete((int) $row['redirect_id']);
        }

        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Soft-deletes a download — hidden from listAllGroupedByCategory()/
     * listByCategory() (so the [lumora_downloads] shortcode and the public
     * site stop showing it) but its underlying Media/Redirect row is
     * left untouched; only delete() (permanent) ever removes those.
     */
    public function trash(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = :trashed_at WHERE id = :id',
            ['trashed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = NULL WHERE id = :id',
            ['id' => $id],
        ) > 0;
    }

    public function countByStatus(DownloadStatus $status): int
    {
        $condition = $status === DownloadStatus::Trashed ? 'trashed_at IS NOT NULL' : 'trashed_at IS NULL';

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$condition}");
    }

    /**
     * A duplicate shares the original's media_id/redirect_id rather than
     * copying the file — delete()'s reference-counting guard makes this safe.
     */
    public function duplicate(int $id): ?Download
    {
        $original = $this->findById($id);

        if ($original === null) {
            return null;
        }

        return $this->recordExisting(
            $original->title . ' (Copy)',
            $original->description,
            $original->folderId,
            $original->type,
            $original->mediaId,
            $original->redirectId,
            $original->descriptionFormat,
            $original->thumbnailMediaId,
            // Mirrors the original's ownership flag since both rows now share the media_id.
            $original->mediaOwned,
            $original->categoryId,
        );
    }

    /**
     * Flat, paginated, sortable admin-list method — distinct from
     * listAllGroupedByCategory(), the public-facing data source.
     *
     * @param array{term?: string, folderId?: int, categoryId?: int} $filters
     * @return array{downloads: array<int, Download>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(
        int $page = 1,
        int $perPage = 20,
        ?DownloadStatus $statusFilter = null,
        array $filters = [],
        string $orderBy = 'title',
        string $orderDir = 'asc',
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $conditions = [$statusFilter === DownloadStatus::Trashed ? 'trashed_at IS NOT NULL' : 'trashed_at IS NULL'];
        $params = [];

        if (($filters['term'] ?? '') !== '') {
            $conditions[] = 'title LIKE :term';
            $params['term'] = '%' . $filters['term'] . '%';
        }

        if (($filters['folderId'] ?? 0) > 0) {
            $conditions[] = 'folder_id = :folder_id';
            $params['folder_id'] = (int) $filters['folderId'];
        }

        if (($filters['categoryId'] ?? 0) > 0) {
            $conditions[] = 'category_id = :category_id';
            $params['category_id'] = (int) $filters['categoryId'];
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // Column name can never come from user input directly into SQL —
        // whitelist against the only sortable columns this table has.
        $column = match ($orderBy) {
            'id' => 'id',
            'date' => 'created_at',
            default => 'title',
        };
        $direction = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        $total = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . " {$where}", $params);

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " {$where} ORDER BY {$column} {$direction} LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'downloads' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    private function referencedByAnotherDownload(string $column, int $value, int $excludingId): bool
    {
        $count = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$column} = :value AND id != :excluding_id",
            ['value' => $value, 'excluding_id' => $excludingId],
        );

        return $count > 0;
    }

    public function findById(int $id): ?Download
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * One query, grouped in PHP — a small dataset, not Posts-scale.
     * Categories ordered alphabetically; uncategorized downloads grouped last.
     *
     * @return array<int, array{category: ?DownloadCategory, downloads: array<int, Download>}>
     */
    public function listAllGroupedByCategory(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' WHERE trashed_at IS NULL ORDER BY title ASC');
        $categoriesById = [];

        foreach ($this->categories->listAll() as $category) {
            $categoriesById[$category->id] = $category;
        }

        $groups = [];

        foreach ($rows as $row) {
            $categoryId = $row['category_id'] !== null ? (int) $row['category_id'] : null;
            $groupKey = $categoryId ?? 0;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['category' => $categoryId !== null ? ($categoriesById[$categoryId] ?? null) : null, 'downloads' => []];
            }

            $groups[$groupKey]['downloads'][] = $this->hydrate($row);
        }

        uasort($groups, static function (array $a, array $b): int {
            if ($a['category'] === null) {
                return 1;
            }

            if ($b['category'] === null) {
                return -1;
            }

            return strcasecmp($a['category']->name, $b['category']->name);
        });

        return array_values($groups);
    }

    /**
     * One category's downloads, alphabetical by title — the
     * [lumora_downloads] shortcode's default "list everything in a
     * category" data source. $categoryId null means the uncategorized
     * bucket, same convention listAllGroupedByCategory() uses.
     *
     * @return array<int, Download>
     */
    public function listByCategory(?int $categoryId): array
    {
        $sql = $categoryId !== null
            ? 'SELECT * FROM ' . $this->table() . ' WHERE category_id = :category_id AND trashed_at IS NULL ORDER BY title ASC'
            : 'SELECT * FROM ' . $this->table() . ' WHERE category_id IS NULL AND trashed_at IS NULL ORDER BY title ASC';

        $rows = $this->database->fetchAll($sql, $categoryId !== null ? ['category_id' => $categoryId] : []);

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * The newest $limit live downloads, optionally filtered to one
     * category — backs [lumora_downloads]'s "newest"/"newest N" variants;
     * $categoryId null means "across all downloads".
     *
     * @return array<int, Download>
     */
    public function listNewest(?int $categoryId, int $limit): array
    {
        $limit = max(1, $limit);

        $sql = 'SELECT * FROM ' . $this->table() . ' WHERE trashed_at IS NULL';
        $params = [];

        if ($categoryId !== null) {
            $sql .= ' AND category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        // id DESC as a tiebreaker — two downloads created within the same
        // second (a real possibility for a bulk import, or several rapid
        // successive creates) would otherwise sort in an undefined order.
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT {$limit}";

        return array_map($this->hydrate(...), $this->database->fetchAll($sql, $params));
    }

    /**
     * A download's click count is not stored here — read from whichever
     * existing counter applies: MediaStatsService for File, the Redirect's
     * hit_count for Url. Returns 0 when neither applies.
     */
    public function downloadCount(Download $download): int
    {
        if ($download->mediaId !== null && $this->mediaStats !== null) {
            return $this->mediaStats->get($download->mediaId)['downloads'];
        }

        if ($download->redirectId !== null) {
            $redirect = $this->redirects->find($download->redirectId);

            return $redirect !== null ? (int) $redirect['hit_count'] : 0;
        }

        return 0;
    }

    private function generateUniqueSourcePath(string $source): string
    {
        $base = 'downloads/' . $this->slugify($source);
        $sourcePath = $base;
        $suffix = 2;

        while ($this->redirects->findBySourcePath($sourcePath) !== null) {
            $sourcePath = $base . '-' . $suffix;
            $suffix++;
        }

        return $sourcePath;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'download' : $slug;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Download
    {
        $type = DownloadType::from((string) $row['type']);
        $mediaId = $row['media_id'] !== null ? (int) $row['media_id'] : null;
        $redirectId = $row['redirect_id'] !== null ? (int) $row['redirect_id'] : null;
        $url = '';
        $fileSizeBytes = null;
        $targetUrl = null;

        if ($type === DownloadType::File && $mediaId !== null) {
            $media = $this->media->find($mediaId);

            if ($media !== null) {
                $url = site_url('media/' . $mediaId . '/download');
                $fileSizeBytes = (int) $media['file_size'];
            }
        } elseif ($type === DownloadType::Url && $redirectId !== null) {
            $redirect = $this->redirects->find($redirectId);

            if ($redirect !== null) {
                $url = site_url((string) $redirect['source_path']);
                $targetUrl = (string) $redirect['target_url'];
            }
        }

        return new Download(
            id: (int) $row['id'],
            title: (string) $row['title'],
            description: (string) ($row['description'] ?? ''),
            descriptionFormat: ContentFormat::tryFrom((string) ($row['description_format'] ?? '')) ?? ContentFormat::Plain,
            folderId: $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
            categoryId: $row['category_id'] !== null ? (int) $row['category_id'] : null,
            type: $type,
            mediaId: $mediaId,
            mediaOwned: (int) ($row['media_owned'] ?? 1) === 1,
            thumbnailMediaId: $row['thumbnail_media_id'] !== null ? (int) $row['thumbnail_media_id'] : null,
            redirectId: $redirectId,
            url: $url,
            fileSizeBytes: $fileSizeBytes,
            targetUrl: $targetUrl,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            trashedAt: $row['trashed_at'] !== null ? new DateTimeImmutable((string) $row['trashed_at']) : null,
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'downloads';
    }
}
