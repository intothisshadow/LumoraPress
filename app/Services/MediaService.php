<?php

/**
 * Media Manager: validated uploads and their metadata.
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
use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Services\Storage\LocalFilesystemStorage;
use LumoraPress\Services\Storage\MediaStorageInterface;
use RuntimeException;

/**
 * Media Manager: validated uploads, virtual-folder assignment, metadata, and search.
 * Gallery display/organization of large image collections is Lumora Gallery's domain, not
 * this class's — Lumora Gallery has no image-editing capability either, so this project's
 * own limited operations (featured-image crop, thumbnails) live directly here.
 *
 * Folder assignment is purely organizational — move() never touches file_path or the public
 * URL, so a file's URL stays stable regardless of which folder it's filed under.
 */
final class MediaService
{
    /**
     * SVG is deliberately excluded: unlike other image types here, a browser executes an
     * SVG's embedded `<script>` when navigated to directly — a stored-XSS risk WordPress
     * excludes SVG from its own default allow-list for the same reason.
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico',
        'pdf', 'zip', 'css', 'txt', 'xml', 'json',
        'doc', 'docx', 'rtf', 'odt', 'rar', '7z',
        'mp3', 'ogg', 'wav', 'm4a',
        'mp4', 'webm', 'mov', 'vtt',
    ];

    /**
     * text/vtt is listed alongside text/plain since mime_content_type()'s detection of a
     * .vtt file varies by host — some report text/vtt, others fall back to text/plain.
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/x-icon', 'image/vnd.microsoft.icon',
        'application/pdf', 'application/zip', 'text/css', 'text/plain', 'text/vtt',
        'application/xml', 'text/xml', 'application/json',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/rtf', 'application/rtf',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.rar', 'application/x-rar-compressed', 'application/x-rar',
        'application/x-7z-compressed',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4',
        'video/mp4', 'video/webm', 'video/quicktime',
    ];

    /**
     * Friendly filter/display categories, keyed by the MIME types that belong to each — used
     * by query()'s "type" filter and typeCategory(). An unlisted MIME type falls back to "other".
     *
     * @var array<string, array<int, string>>
     */
    private const TYPE_CATEGORY_MIME_TYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'],
        'document' => [
            'application/pdf', 'text/css', 'text/plain', 'application/xml', 'text/xml', 'application/json',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/rtf', 'application/rtf', 'application/vnd.oasis.opendocument.text',
        ],
        'archive' => ['application/zip', 'application/vnd.rar', 'application/x-rar-compressed', 'application/x-rar', 'application/x-7z-compressed'],
        'audio' => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
    ];

    private const MAX_FILE_SIZE = 100 * 1024 * 1024;

    private readonly MediaStorageInterface $storage;

    /**
     * Per-request memoization for find() — a single render can call the Featured Image theme
     * API several times against the same media id, and one service instance lives for the
     * whole request. Every write method below that can change a cached row must evict it.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private array $findCache = [];

    /**
     * @param Closure(string, string): bool|null $moveUploadedFile Overrides the default
     *     storage driver's file-move operation for tests. Ignored when $storage is given.
     * @param ?HookManager $hooks Fires 'media_saved'/'media_deleted' for cache invalidation.
     * @param ?MediaStorageInterface $storage Defaults to local disk when omitted; a future
     *     S3/R2 driver plugs in here without this class changing.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly string $uploadsPath,
        private readonly string $uploadsUrl,
        ?Closure $moveUploadedFile = null,
        private readonly ?HookManager $hooks = null,
        ?MediaStorageInterface $storage = null,
    ) {
        $this->storage = $storage ?? new LocalFilesystemStorage($uploadsPath, $uploadsUrl, $moveUploadedFile);
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array<string, mixed>
     */
    public function upload(array $file, int $uploadedByUserId, ?int $folderId = null): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File exceeds the maximum upload size.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!$this->isAllowedExtension($extension)) {
            throw new RuntimeException('File type is not allowed.');
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: '';

        if (!$this->isAllowedMimeType($mimeType)) {
            throw new RuntimeException('File type could not be verified.');
        }

        $year = date('Y');
        $month = date('m');
        $safeName = $this->sanitizeFilename(pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $relativePath = "{$year}/{$month}/{$filename}";

        if (!$this->storage->put($file['tmp_name'], $relativePath)) {
            throw new RuntimeException('Unable to move the uploaded file.');
        }

        $destination = $this->storage->absolutePath($relativePath);
        $width = null;
        $height = null;

        if (str_starts_with($mimeType, 'image/')) {
            $dimensions = getimagesize($destination);

            if (is_array($dimensions)) {
                $width = $dimensions[0];
                $height = $dimensions[1];
            }
        }

        $fileHash = hash_file('sha256', $destination) ?: null;

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (file_name, file_path, mime_type, file_size, width, height, uploaded_by, folder_id, file_hash, uploaded_at)
             VALUES (:file_name, :file_path, :mime_type, :file_size, :width, :height, :uploaded_by, :folder_id, :file_hash, :uploaded_at)',
            [
                'file_name' => $file['name'],
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => $file['size'],
                'width' => $width,
                'height' => $height,
                'uploaded_by' => $uploadedByUserId,
                'folder_id' => $folderId,
                'file_hash' => $fileHash,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ],
        );

        $media = $this->find((int) $id);

        if ($media === null) {
            throw new RuntimeException('Failed to load the media item that was just uploaded.');
        }

        $this->hooks?->doAction('media_saved', $media);

        return $media;
    }

    /**
     * Registers a file that already exists on disk as a new `media` row, with no
     * browser-submitted upload to validate or move — used e.g. by
     * ThumbnailService::createCroppedFeaturedMedia() for a GD-written file.
     *
     * $uploadedAt lets a bulk importer preserve a source's original upload date; other
     * callers leave it null.
     *
     * @return array<string, mixed>
     */
    public function registerExistingFile(
        string $relativePath,
        string $fileName,
        string $mimeType,
        int $fileSize,
        ?int $width,
        ?int $height,
        int $uploadedByUserId,
        ?int $folderId,
        ?DateTimeImmutable $uploadedAt = null,
    ): array {
        $absolutePath = rtrim($this->uploadsPath, '/') . '/' . $relativePath;
        $fileHash = is_file($absolutePath) ? (hash_file('sha256', $absolutePath) ?: null) : null;

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (file_name, file_path, mime_type, file_size, width, height, uploaded_by, folder_id, file_hash, uploaded_at)
             VALUES (:file_name, :file_path, :mime_type, :file_size, :width, :height, :uploaded_by, :folder_id, :file_hash, :uploaded_at)',
            [
                'file_name' => $fileName,
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'width' => $width,
                'height' => $height,
                'uploaded_by' => $uploadedByUserId,
                'folder_id' => $folderId,
                'file_hash' => $fileHash,
                'uploaded_at' => ($uploadedAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $media = $this->find((int) $id);

        if ($media === null) {
            throw new RuntimeException('Failed to load the media item that was just registered.');
        }

        $this->hooks?->doAction('media_saved', $media);

        return $media;
    }

    /**
     * The "Replace" action — swaps a media item's file content in place while keeping its
     * id, file_path, and public URL, so everything already pointing at it keeps working.
     * Single-item only; the replacement's extension must match the original's exactly so
     * file_path can stay untouched without a MIME/extension mismatch on disk.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array<string, mixed>
     */
    public function replace(int $id, array $file): array
    {
        $media = $this->find($id);

        if ($media === null) {
            throw new RuntimeException('Media item not found.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File exceeds the maximum upload size.');
        }

        $existingExtension = strtolower(pathinfo((string) $media['file_path'], PATHINFO_EXTENSION));
        $newExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($newExtension !== $existingExtension) {
            throw new RuntimeException("The replacement file must be the same type (.{$existingExtension}) as the original, to keep its URL working.");
        }

        if (!$this->isAllowedExtension($newExtension)) {
            throw new RuntimeException('File type is not allowed.');
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: '';

        if (!$this->isAllowedMimeType($mimeType)) {
            throw new RuntimeException('File type could not be verified.');
        }

        $relativePath = (string) $media['file_path'];

        if (!$this->storage->put($file['tmp_name'], $relativePath)) {
            throw new RuntimeException('Unable to move the uploaded file.');
        }

        $destination = $this->storage->absolutePath($relativePath);
        $width = null;
        $height = null;

        if (str_starts_with($mimeType, 'image/')) {
            $dimensions = getimagesize($destination);

            if (is_array($dimensions)) {
                $width = $dimensions[0];
                $height = $dimensions[1];
            }
        }

        $fileHash = hash_file('sha256', $destination) ?: null;

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET mime_type = :mime_type, file_size = :file_size, width = :width, height = :height, file_hash = :file_hash
              WHERE id = :id',
            [
                'mime_type' => $mimeType,
                'file_size' => $file['size'],
                'width' => $width,
                'height' => $height,
                'file_hash' => $fileHash,
                'id' => $id,
            ],
        );

        unset($this->findCache[$id]);
        $updated = $this->find($id);

        if ($updated === null) {
            throw new RuntimeException('Failed to load the media item after replacing it.');
        }

        $this->hooks?->doAction('media_saved', $updated);

        return $updated;
    }

    /** Exposed publicly so MediaImportService validates against the same allow-list upload() uses. */
    public function isAllowedExtension(string $extension): bool
    {
        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    public function isAllowedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_MIME_TYPES, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        if (array_key_exists($id, $this->findCache)) {
            return $this->findCache[$id];
        }

        return $this->findCache[$id] = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
    }

    /**
     * Reverse lookup from a file's relative path back to its Media row — used to mask an
     * already-rendered `content/uploads/...` URL found in free-text HTML back to a
     * `/media/{id}/view` link. $relativePath must already be stripped of the uploads URL
     * prefix; see DownloadMediaUrlMasker::relativePathFromUrl().
     *
     * @return array<string, mixed>|null
     */
    public function findByFilePath(string $relativePath): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE file_path = :file_path',
            ['file_path' => $relativePath],
        );
    }

    public function updateMetadata(int $id, ?string $altText, ?string $caption, ?string $description, ?string $notes): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET alt_text = :alt_text, caption = :caption, description = :description, notes = :notes
              WHERE id = :id',
            [
                'alt_text' => $altText,
                'caption' => $caption,
                'description' => $description,
                'notes' => $notes,
                'id' => $id,
            ],
        );

        unset($this->findCache[$id]);
    }

    /**
     * The bulk "Rename" action: find/replace across every selected item's file_name only,
     * never file_path, so a file's public URL never changes. A blank $find is refused, since
     * str_contains('', '') would otherwise "match" every selected item as a no-op.
     *
     * @param array<int, int> $ids
     * @return int How many ids actually had $find in their name and were renamed.
     */
    public function bulkRenameByReplacing(array $ids, string $find, string $replace): int
    {
        if ($find === '') {
            return 0;
        }

        $renamed = 0;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $media = $this->find($id);

            if ($media === null || !str_contains((string) $media['file_name'], $find)) {
                continue;
            }

            $newName = str_replace($find, $replace, (string) $media['file_name']);

            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET file_name = :file_name WHERE id = :id',
                ['file_name' => $newName, 'id' => $id],
            );

            unset($this->findCache[$id]);
            $renamed++;
        }

        return $renamed;
    }

    /**
     * The bulk "Change metadata" action. Unlike updateMetadata()'s single-item form, null
     * here means "leave this field alone" rather than "clear it" — otherwise a blank field
     * would silently wipe per-file text on every selected item at once.
     *
     * @param array<int, int> $ids
     * @return int How many ids were updated.
     */
    public function bulkUpdateMetadata(array $ids, ?string $altText, ?string $caption, ?string $description, ?string $notes): int
    {
        $updated = 0;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $media = $this->find($id);

            if ($media === null) {
                continue;
            }

            $this->updateMetadata(
                $id,
                $altText ?? ($media['alt_text'] !== null ? (string) $media['alt_text'] : null),
                $caption ?? ($media['caption'] !== null ? (string) $media['caption'] : null),
                $description ?? ($media['description'] !== null ? (string) $media['description'] : null),
                $notes ?? ($media['notes'] !== null ? (string) $media['notes'] : null),
            );

            $updated++;
        }

        return $updated;
    }

    /**
     * Reassigns a file's virtual folder — purely a metadata update, never
     * touches file_path/the public URL (see class docblock).
     */
    public function move(int $id, ?int $folderId): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET folder_id = :folder_id WHERE id = :id',
            ['folder_id' => $folderId, 'id' => $id],
        );

        unset($this->findCache[$id]);
    }

    /**
     * Video poster image and caption track are references to other media items, picked from
     * a <select> — no new upload path needed. Kept separate from updateMetadata() since
     * these fields are video-specific.
     */
    public function setVideoAssets(int $id, ?int $posterMediaId, ?int $captionTrackMediaId): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET poster_media_id = :poster_media_id, caption_track_media_id = :caption_track_media_id
              WHERE id = :id',
            [
                'poster_media_id' => $posterMediaId,
                'caption_track_media_id' => $captionTrackMediaId,
                'id' => $id,
            ],
        );

        unset($this->findCache[$id]);
    }

    /**
     * Filterable listing.
     *
     * @param array{folderIds?: array<int, int>, unassignedOnly?: bool, term?: string, type?: string, dateFrom?: string, dateTo?: string, widthMin?: int, widthMax?: int, heightMin?: int, heightMax?: int, sizeMin?: int, sizeMax?: int} $filters
     *     folderIds: exact folders to restrict to, not their descendants. unassignedOnly:
     *     files with no folder; ignored if folderIds is set. term: matches file_name. type:
     *     one of TYPE_CATEGORY_MIME_TYPES's keys. dateFrom/dateTo: 'Y-m-d' strings.
     *     width/height bounds are NULL (and so excluded) for non-image files. sizeMin/sizeMax:
     *     byte bounds against file_size, any file type.
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters = [], int $limit = 40, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $where = [];
        $params = [];

        if (isset($filters['folderIds'])) {
            $placeholders = [];

            foreach (array_values($filters['folderIds']) as $i => $folderId) {
                $key = "folder_id_{$i}";
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $folderId;
            }

            $where[] = $placeholders === [] ? '1 = 0' : 'folder_id IN (' . implode(',', $placeholders) . ')';
        } elseif (($filters['unassignedOnly'] ?? false) === true) {
            $where[] = 'folder_id IS NULL';
        }

        $termWords = preg_split('/\s+/', trim((string) ($filters['term'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);

        // Each word must appear in file_name (AND across words), not the whole typed
        // string as one substring, so word order in the filename doesn't matter.
        foreach ($termWords as $i => $word) {
            $key = "term_{$i}";
            $where[] = "file_name LIKE :{$key}";
            $params[$key] = '%' . $word . '%';
        }

        if (($filters['type'] ?? '') !== '' && isset(self::TYPE_CATEGORY_MIME_TYPES[$filters['type']])) {
            $placeholders = [];

            foreach (self::TYPE_CATEGORY_MIME_TYPES[$filters['type']] as $i => $mimeType) {
                $key = "mime_{$i}";
                $placeholders[] = ':' . $key;
                $params[$key] = $mimeType;
            }

            $where[] = 'mime_type IN (' . implode(',', $placeholders) . ')';
        }

        if (($filters['dateFrom'] ?? '') !== '') {
            $where[] = 'uploaded_at >= :date_from';
            $params['date_from'] = $filters['dateFrom'] . ' 00:00:00';
        }

        if (($filters['dateTo'] ?? '') !== '') {
            $where[] = 'uploaded_at <= :date_to';
            $params['date_to'] = $filters['dateTo'] . ' 23:59:59';
        }

        if (isset($filters['widthMin'])) {
            $where[] = 'width >= :width_min';
            $params['width_min'] = $filters['widthMin'];
        }

        if (isset($filters['widthMax'])) {
            $where[] = 'width <= :width_max';
            $params['width_max'] = $filters['widthMax'];
        }

        if (isset($filters['heightMin'])) {
            $where[] = 'height >= :height_min';
            $params['height_min'] = $filters['heightMin'];
        }

        if (isset($filters['heightMax'])) {
            $where[] = 'height <= :height_max';
            $params['height_max'] = $filters['heightMax'];
        }

        if (isset($filters['sizeMin'])) {
            $where[] = 'file_size >= :size_min';
            $params['size_min'] = $filters['sizeMin'];
        }

        if (isset($filters['sizeMax'])) {
            $where[] = 'file_size <= :size_max';
            $params['size_max'] = $filters['sizeMax'];
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . $whereSql, $params);

        $items = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . $whereSql . " ORDER BY uploaded_at DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        return ['items' => $items, 'total' => $total];
    }

    /** Total item count across every folder — the sidebar's "All Media" badge. */
    public function countAll(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table());
    }

    /**
     * Item counts grouped by folder_id in a single query, avoiding an N+1 per folder
     * rendered. A folder with zero items is simply absent, not present with 0 —
     * FolderService::directCountsByFolderId() fills in zeroes and rolls up to ancestors.
     *
     * @return array<int, int> folder_id (or 0 for unassigned) => item count
     */
    public function directCountsByFolderId(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT folder_id, COUNT(*) AS item_count FROM ' . $this->table() . ' GROUP BY folder_id',
        );

        $counts = [];

        foreach ($rows as $row) {
            $folderId = $row['folder_id'] !== null ? (int) $row['folder_id'] : 0;
            $counts[$folderId] = (int) $row['item_count'];
        }

        return $counts;
    }

    /**
     * The friendly filter/display category (see TYPE_CATEGORY_MIME_TYPES)
     * a stored MIME type belongs to.
     */
    public function typeCategory(string $mimeType): string
    {
        foreach (self::TYPE_CATEGORY_MIME_TYPES as $category => $mimeTypes) {
            if (in_array($mimeType, $mimeTypes, true)) {
                return $category;
            }
        }

        return 'other';
    }

    /**
     * Largest files first, any type — the "Large Files" Smart Collection. An unpaginated,
     * capped list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function largestFiles(int $limit = 40): array
    {
        $limit = max(1, $limit);

        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " ORDER BY file_size DESC LIMIT {$limit}",
        );
    }

    /**
     * Images with no alt text set — the "Missing Alt Text" Smart Collection. Non-image files
     * are excluded, since alt text is meaningless for them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function missingAltText(int $limit = 40): array
    {
        $limit = max(1, $limit);

        return $this->database->fetchAll(
            "SELECT * FROM " . $this->table() . " WHERE mime_type LIKE 'image/%' AND (alt_text IS NULL OR alt_text = '') ORDER BY uploaded_at DESC LIMIT {$limit}",
        );
    }

    /**
     * Full media rows for a set of ids — used to render a Smart Collection built from ids
     * gathered elsewhere rather than a query() filter.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $i => $id) {
            $key = "id_{$i}";
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE id IN (' . implode(',', $placeholders) . ') ORDER BY uploaded_at DESC',
            $params,
        );
    }

    public function delete(int $id): bool
    {
        $media = $this->find($id);

        if ($media === null) {
            return false;
        }

        $this->storage->delete((string) $media['file_path']);

        $deleted = $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;

        unset($this->findCache[$id]);

        if ($deleted) {
            $this->hooks?->doAction('media_deleted', $id);
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $media
     */
    public function url(array $media): string
    {
        return $this->storage->url((string) $media['file_path']);
    }

    /**
     * The file's real filesystem location — needed by callers that read the file's actual
     * bytes (e.g. a bulk ZIP download) rather than just link to it.
     *
     * @param array<string, mixed> $media
     */
    public function absolutePath(array $media): string
    {
        return $this->storage->absolutePath((string) $media['file_path']);
    }

    /**
     * Sends $media's actual bytes as the HTTP response rather than redirecting to the real
     * static file URL, which would expose the `content/uploads/...` path. Headers only; the
     * caller must still `exit` afterward. $inline picks `Content-Disposition: inline` vs
     * `attachment`. `X-Content-Type-Options: nosniff` guards against a browser sniffing a
     * text/plain-etc. response as HTML — a narrow stored-XSS surface for streamed content.
     * Returns false when the file no longer exists on disk, so the caller can 404 instead.
     *
     * @param array<string, mixed> $media
     */
    public function stream(array $media, bool $inline): bool
    {
        $path = $this->absolutePath($media);

        if (!is_file($path)) {
            return false;
        }

        header('Content-Type: ' . (string) $media['mime_type']);
        header('Content-Length: ' . (string) $media['file_size']);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes((string) $media['file_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($inline ? 'public, max-age=31536000, immutable' : 'private, no-cache'));

        readfile($path);

        return true;
    }

    /**
     * Public so MediaImportService builds destination filenames the same
     * way upload() does, rather than duplicating this logic.
     */
    public function sanitizeFilename(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $name) ?? '';
        $sanitized = trim($sanitized, '-');

        return $sanitized === '' ? 'file' : strtolower($sanitized);
    }

    private function table(): string
    {
        return $this->tablePrefix . 'media';
    }
}
