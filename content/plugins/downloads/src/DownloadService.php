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
use LumoraPress\Models\ContentFormat;
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
        ContentFormat $descriptionFormat = ContentFormat::Plain,
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

        return $this->recordExisting($title, $description, $folderId, $type, $mediaId, $redirectId, $descriptionFormat);
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
    public function recordExisting(string $title, string $description, ?int $folderId, DownloadType $type, ?int $mediaId, ?int $redirectId, ContentFormat $descriptionFormat = ContentFormat::Plain, ?int $thumbnailMediaId = null, bool $mediaOwned = true): Download
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, description, description_format, folder_id, type, media_id, media_owned, thumbnail_media_id, redirect_id, created_at, updated_at)
             VALUES (:title, :description, :description_format, :folder_id, :type, :media_id, :media_owned, :thumbnail_media_id, :redirect_id, :created_at, :updated_at)',
            [
                'title' => $title,
                'description' => $description,
                'description_format' => $descriptionFormat->value,
                'folder_id' => $folderId,
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
     * $externalUrl only ever applies here to a download that's *already*
     * Url-typed (editing its existing target) — swapping a File-typed
     * download's file, or converting between File and Url entirely, is
     * replaceFile()'s/convertToFile()'s/convertToUrl()'s job (LPP-012),
     * called separately before this method by the same admin view save
     * handler; this method only ever touches title/description/folder/
     * (same-type) URL. $descriptionFormat left null keeps the download's
     * existing stored format (mirrors PageService::update()'s identical
     * "null means unchanged" convention for its own $contentFormat
     * parameter).
     */
    public function update(int $id, string $title, string $description, ?int $folderId, ?string $externalUrl, ?ContentFormat $descriptionFormat = null): bool
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
                SET title = :title, description = :description, description_format = :description_format, folder_id = :folder_id, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'description' => $description,
                'description_format' => $resolvedDescriptionFormat->value,
                'folder_id' => $folderId,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;
    }

    /**
     * "Add from server" (LPP-012): attaches an already-uploaded Media
     * item as a File-typed download's file, instead of uploading a new
     * one — the same shared recordExisting() tail create()'s own upload
     * branch ends at, just skipping the upload itself since the file
     * already exists in the Media Library.
     */
    public function createFromExistingMedia(string $title, string $description, ?int $folderId, int $mediaId, ContentFormat $descriptionFormat = ContentFormat::Plain): Download
    {
        if ($this->media->find($mediaId) === null) {
            throw new InvalidArgumentException('The selected file could not be found.');
        }

        // mediaOwned: false — this Media row predates and exists
        // independently of this download (see Download::$mediaOwned's
        // own docblock), so delete()/replaceFile() must never delete it
        // on this download's account.
        return $this->recordExisting($title, $description, $folderId, DownloadType::File, $mediaId, null, $descriptionFormat, mediaOwned: false);
    }

    /**
     * LPP-012: repoints a File-typed download at a different, already-
     * existing Media item — $newMediaId was either just uploaded (a
     * fresh Media row created moments earlier, $newMediaOwned true — the
     * default, matching the common "upload a replacement" case) or
     * picked from the server the same way createFromExistingMedia()
     * attaches one ($newMediaOwned false); this method never creates a
     * Media row itself. False (no-op) for a Url-typed download or an
     * unknown $id/$newMediaId — replacing a Url download's target is
     * update()'s $externalUrl parameter's job, not this method's.
     *
     * The old Media row is deleted only when it was owned (this
     * download's own file, not one attached from the library — see
     * Download::$mediaOwned's own docblock) *and* no other download
     * still references it — the exact same referencedByAnotherDownload()
     * guard delete() uses for the same reason (duplicate(), LPP-009,
     * deliberately shares a Media/Redirect row rather than copying it,
     * so a replace on one shared download must not orphan or break the
     * other). An unowned old Media row is never deleted here, full
     * stop — it existed independently of this download before being
     * attached, so this download replacing its file is never grounds to
     * delete it; that would be a real, permanent, on-disk file deletion
     * of what may be a Media Library item the admin still wants,
     * regardless of any other download referencing it. $thumbnailMediaId
     * is left untouched — it's a separately chosen representative image
     * (see Download's own docblock), not necessarily invalidated by
     * swapping the underlying file.
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
     * LPP-012: converts a Url-typed download into a File-typed one — a
     * download's type isn't fixed at creation after all; a URL that
     * turns out to need to become a real hosted file (or vice versa,
     * see convertToUrl() below) is a real, expected need. $newMediaId/
     * $newMediaOwned work exactly like replaceFile()'s own — a fresh
     * upload (owned) or a Media Library pick (not owned).
     *
     * The old Redirect is deleted when no other download still
     * references it — the same referencedByAnotherDownload() guard
     * delete()/replaceFile() already use for Media. No ownership check
     * is needed for the Redirect the way replaceFile() needs one for
     * Media: unlike createFromExistingMedia(), nothing ever attaches a
     * *pre-existing, independently-created* Redirect to a download —
     * every Redirect a download ever has was created by create()/
     * convertToUrl() specifically for that download, so it's always
     * safe to clean up once nothing references it any more.
     *
     * False (no-op) for a download that's already File-typed —
     * replaceFile() is that method's job — or an unknown $id/$newMediaId.
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
     * LPP-012: converts a File-typed download into a Url-typed one —
     * convertToFile()'s mirror. Creates a fresh Redirect the same way
     * create()'s own Url branch does (a unique `downloads/{slug}`
     * source path via generateUniqueSourcePath(), against the
     * download's *current* title — a simultaneous title change in the
     * same save is applied afterward by update(), same as it always
     * was; this never renames an existing Redirect's source path either).
     *
     * The old Media row is deleted only when it was owned (see
     * Download::$mediaOwned's own docblock — an attached-from-the-
     * library file must never be deleted just because this download
     * stops using it) and no other download still references it — the
     * same guard replaceFile() uses for the same reason.
     *
     * False (no-op) for a download that's already Url-typed, an unknown
     * $id, or a blank $externalUrl.
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
     * Deletes the linked Media or Redirect row first — a Download's whole
     * reason to exist is to be the public download, so no orphaned Media
     * item or Redirect should survive it. Only cascades that Media
     * deletion when the row is owned (LPP-012: this download's own
     * file, not one attached from the Media Library via
     * createFromExistingMedia() — see Download::$mediaOwned's own
     * docblock; an unowned Media row is never touched, since it existed
     * independently before this download attached it and may still be
     * wanted regardless of this download's fate) *and* no *other*
     * download row still references the same media_id/redirect_id —
     * duplicate() (LPP-009) deliberately shares the original's Media/
     * Redirect rather than copying them, so permanently deleting one
     * duplicate must not break the link the other still relies on. A
     * Redirect is always considered owned — LPP-012 only ever attaches
     * an *existing Media item*, never an existing Redirect, to a
     * download, so that ambiguity doesn't apply there.
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
            $original->descriptionFormat,
            $original->thumbnailMediaId,
            // Mirrors the original's own ownership flag — both rows now
            // share the same media_id (this method deliberately shares
            // rather than copies, see this method's own docblock), so
            // whichever of the two is deleted last is the one that
            // decides whether the shared file gets cleaned up.
            $original->mediaOwned,
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
            descriptionFormat: ContentFormat::tryFrom((string) ($row['description_format'] ?? '')) ?? ContentFormat::Plain,
            folderId: $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
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
