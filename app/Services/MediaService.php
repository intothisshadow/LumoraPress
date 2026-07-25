<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use Closure;
use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * Media Manager (LP-005): validated uploads (images, documents, archives,
 * audio, video), virtual-folder assignment, metadata, and search/filtering.
 * Advanced image management (editing, cropping, galleries) is intentionally
 * out of scope — that remains the domain of Lumora Gallery.
 *
 * Folder assignment is purely organizational — file_path (the physical
 * location) and the public URL built from it are never touched by move(),
 * so moving a file between folders can never change its stable URL. See
 * FolderService for the folder tree itself.
 *
 * isAllowedExtension()/isAllowedMimeType()/sanitizeFilename() are public
 * so MediaImportService (LP-041, importing existing server files without
 * a browser upload) can validate and name files exactly the same way
 * upload() does, without duplicating this class's allow-lists.
 */
final class MediaService
{
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico',
        'pdf', 'zip', 'css', 'txt', 'xml', 'json',
        'mp3', 'ogg', 'wav', 'm4a',
        'mp4', 'webm', 'mov',
    ];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/x-icon', 'image/vnd.microsoft.icon',
        'application/pdf', 'application/zip', 'text/css', 'text/plain',
        'application/xml', 'text/xml', 'application/json',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4',
        'video/mp4', 'video/webm', 'video/quicktime',
    ];

    /**
     * Friendly filter/display categories, keyed by the MIME types that
     * belong to each — used by query()'s "type" filter and typeCategory()
     * for admin-UI labeling. A MIME type not listed here (shouldn't
     * happen, since ALLOWED_MIME_TYPES is the only way a file gets
     * stored) falls back to "other".
     *
     * @var array<string, array<int, string>>
     */
    private const TYPE_CATEGORY_MIME_TYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'],
        'document' => ['application/pdf', 'text/css', 'text/plain', 'application/xml', 'text/xml', 'application/json'],
        'archive' => ['application/zip'],
        'audio' => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
    ];

    private const MAX_FILE_SIZE = 100 * 1024 * 1024;

    private readonly Closure $moveUploadedFile;

    /**
     * @param Closure(string, string): bool|null $moveUploadedFile Overrides
     *     the file-move operation — defaults to move_uploaded_file().
     *     Exists so tests can exercise upload()'s success path: PHP's
     *     is_uploaded_file() (which move_uploaded_file() depends on) can
     *     only ever pass for a file that arrived via a real HTTP upload,
     *     never from a CLI test process.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly string $uploadsPath,
        private readonly string $uploadsUrl,
        ?Closure $moveUploadedFile = null,
    ) {
        $this->moveUploadedFile = $moveUploadedFile ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
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
        $directory = rtrim($this->uploadsPath, '/') . "/{$year}/{$month}";

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the uploads directory.');
        }

        $safeName = $this->sanitizeFilename(pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $directory . '/' . $filename;

        if (!($this->moveUploadedFile)($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to move the uploaded file.');
        }

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
        $relativePath = "{$year}/{$month}/{$filename}";

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

        return $media;
    }

    /**
     * Whether $extension clears the allow-list gate — exposed publicly
     * (alongside isAllowedMimeType()) so MediaImportService (LP-041) can
     * validate scanned server files against the exact same allow-list
     * upload() uses, rather than duplicating it.
     */
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
        return $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
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
    }

    /**
     * Filterable listing, replacing the old browse()/search() split.
     *
     * @param array{folderIds?: array<int, int>, unassignedOnly?: bool, term?: string, type?: string, dateFrom?: string, dateTo?: string} $filters
     *     folderIds: restrict to these folder ids (e.g. a folder plus its
     *     descendants — see FolderService::descendantIds()). unassignedOnly:
     *     restrict to files with no folder ("General Uploads"); ignored if
     *     folderIds is set. term: matches file_name. type: one of
     *     TYPE_CATEGORY_MIME_TYPES's keys. dateFrom/dateTo: 'Y-m-d' strings.
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

        if (($filters['term'] ?? '') !== '') {
            $where[] = 'file_name LIKE :term';
            $params['term'] = '%' . $filters['term'] . '%';
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

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . $whereSql, $params);

        $items = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . $whereSql . " ORDER BY uploaded_at DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        return ['items' => $items, 'total' => $total];
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

    public function delete(int $id): bool
    {
        $media = $this->find($id);

        if ($media === null) {
            return false;
        }

        $path = rtrim($this->uploadsPath, '/') . '/' . $media['file_path'];

        if (is_file($path)) {
            unlink($path);
        }

        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * @param array<string, mixed> $media
     */
    public function url(array $media): string
    {
        return rtrim($this->uploadsUrl, '/') . '/' . $media['file_path'];
    }

    /**
     * Public so MediaImportService (LP-041) builds destination filenames
     * the same way upload() does, rather than duplicating this logic.
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
