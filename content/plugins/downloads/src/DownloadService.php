<?php

/**
 * CRUD for the downloads table (LPP-008): the admin-facing identity/metadata layer sitting on top of Media items and Redirects.
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
use LumoraPress\Models\Folder;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\RedirectService;
use LumoraPress\Services\ThumbnailService;
use RuntimeException;

/**
 * Deliberately does not reimplement file storage or URL redirection —
 * a File download's actual bytes are a normal MediaService-managed
 * Media row (so it keeps working with the Media Manager, the existing
 * download endpoint, and file-type/size validation exactly as before);
 * a Url download's actual link is a normal RedirectService-managed
 * Redirect row (so it keeps hit-counting and 404 fallback behavior).
 * This table only adds what neither of those had on its own: a real
 * title/description regardless of type, and a single place to list and
 * edit every download grouped by category.
 *
 * $folderId is always also passed straight through to the underlying
 * Media/Redirect row, matching the folder_id-keyed convention
 * LPP-004/LPP-007's WordPress import already established — this is
 * deliberate, not incidental: it means a download created here is
 * automatically visible to the existing
 * `[sdm_show_dl_from_category]` shortcode (DownloadsShortcode, in the
 * wordpress-importer plugin) with no changes needed to that plugin at
 * all, since it queries Media/Redirects by folder_id directly and has
 * no idea this table exists.
 */
final class DownloadService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly MediaService $media,
        private readonly RedirectService $redirects,
        private readonly FolderService $folders,
        private readonly ?ThumbnailService $thumbnails = null,
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

        return $this->recordExisting($title, $description, $folderId, $type, $mediaId, $redirectId);
    }

    /**
     * Inserts a `downloads` row referencing a Media item or Redirect
     * that already exists — the shared tail create() itself uses after
     * doing the actual upload/redirect-creation, and the entry point for
     * a caller that created that Media/Redirect row itself and only
     * needs the identity/metadata layer attached on top (e.g.
     * WordPressImportService::importDownloads(), which uses its own
     * provenance-tracked MediaImporter/RedirectService::create() calls
     * rather than going through create() above — calling create()
     * there would upload the file or create the redirect a second
     * time).
     */
    public function recordExisting(string $title, string $description, ?int $folderId, DownloadType $type, ?int $mediaId, ?int $redirectId): Download
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, description, folder_id, type, media_id, redirect_id, created_at, updated_at)
             VALUES (:title, :description, :folder_id, :type, :media_id, :redirect_id, :created_at, :updated_at)',
            [
                'title' => $title,
                'description' => $description,
                'folder_id' => $folderId,
                'type' => $type->value,
                'media_id' => $mediaId,
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
     * Changing the underlying *file* of a File-typed download is out of
     * scope — Media Manager's own edit panel doesn't support replacing a
     * file either; delete and re-add covers that case. $externalUrl is
     * ignored for a File-typed download.
     */
    public function update(int $id, string $title, string $description, ?int $folderId, ?string $externalUrl): bool
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

        return $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, description = :description, folder_id = :folder_id, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'description' => $description,
                'folder_id' => $folderId,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;
    }

    /**
     * Deletes the linked Media or Redirect row first — a Download's whole
     * reason to exist is to be the public download, so no orphaned Media
     * item or Redirect should survive it. Only cascades that deletion
     * when no *other* download row still references the same media_id/
     * redirect_id — duplicate() (LPP-009) deliberately shares the
     * original's Media/Redirect rather than copying them, so permanently
     * deleting one duplicate must not break the link the other still
     * relies on.
     */
    public function delete(int $id): bool
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return false;
        }

        if ($row['media_id'] !== null && !$this->referencedByAnotherDownload('media_id', (int) $row['media_id'], $id)) {
            $this->media->delete((int) $row['media_id']);
        }

        if ($row['redirect_id'] !== null && !$this->referencedByAnotherDownload('redirect_id', (int) $row['redirect_id'], $id)) {
            $this->redirects->delete((int) $row['redirect_id']);
        }

        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Soft-deletes a download — hidden from listAllGroupedByFolder()/
     * listByFolder() (so the [lumora_downloads] shortcode and the public
     * site stop showing it) but its underlying Media/Redirect row is left
     * untouched; only delete() (permanent) ever removes those.
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
     * copying the underlying file/redirect — the same trade-off
     * PostService::duplicate() already makes for a post's featured image
     * (passing featuredImageId through unchanged instead of duplicating
     * the Media row). delete()'s reference-counting guard above is what
     * makes this safe: permanently deleting either copy only removes the
     * shared Media/Redirect once nothing else points at it.
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
        );
    }

    /**
     * Flat, paginated, sortable admin-list method (LPP-009) — distinct
     * from listAllGroupedByFolder(), which stays as the public-facing
     * grouped-by-category data source. Mirrors PageService::paginateForAdmin()'s
     * shape, but genuinely supports column sorting (via $orderBy/$orderDir)
     * since, unlike Posts/Pages, a download list has no natural
     * published-date ordering to fall back on.
     *
     * @param array{term?: string, folderId?: int} $filters
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
     * One query, grouped in PHP (small, evergreen dataset — the same
     * trade-off PageService::listAllForTree()'s own docblock makes for
     * pages, "not the tens-of-thousands-of-rows table Posts can be").
     * Folders are ordered alphabetically by name; downloads with no
     * folder are grouped last under a null key.
     *
     * @return array<int, array{folder: ?Folder, downloads: array<int, Download>}>
     */
    public function listAllGroupedByFolder(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' WHERE trashed_at IS NULL ORDER BY title ASC');
        $foldersById = [];

        foreach ($this->folders->listAll() as $folder) {
            $foldersById[$folder->id] = $folder;
        }

        $groups = [];

        foreach ($rows as $row) {
            $folderId = $row['folder_id'] !== null ? (int) $row['folder_id'] : null;
            $groupKey = $folderId ?? 0;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['folder' => $folderId !== null ? ($foldersById[$folderId] ?? null) : null, 'downloads' => []];
            }

            $groups[$groupKey]['downloads'][] = $this->hydrate($row);
        }

        uasort($groups, static function (array $a, array $b): int {
            if ($a['folder'] === null) {
                return 1;
            }

            if ($b['folder'] === null) {
                return -1;
            }

            return strcasecmp($a['folder']->name, $b['folder']->name);
        });

        return array_values($groups);
    }

    /**
     * One folder's downloads, alphabetical by title — the new Lumora
     * Downloads shortcode's own data source, mirroring
     * RedirectService::listByFolder()'s identical shape. $folderId null
     * means the uncategorized bucket, same convention
     * listAllGroupedByFolder() already uses.
     *
     * @return array<int, Download>
     */
    public function listByFolder(?int $folderId): array
    {
        $sql = $folderId !== null
            ? 'SELECT * FROM ' . $this->table() . ' WHERE folder_id = :folder_id AND trashed_at IS NULL ORDER BY title ASC'
            : 'SELECT * FROM ' . $this->table() . ' WHERE folder_id IS NULL AND trashed_at IS NULL ORDER BY title ASC';

        $rows = $this->database->fetchAll($sql, $folderId !== null ? ['folder_id' => $folderId] : []);

        return array_map($this->hydrate(...), $rows);
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
            folderId: $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
            type: $type,
            mediaId: $mediaId,
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
