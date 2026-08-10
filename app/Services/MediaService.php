<?php

/**
 * Media Manager (LP-005): validated uploads and their metadata.
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
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Services\Storage\LocalFilesystemStorage;
use LumoraPress\Services\Storage\MediaStorageInterface;
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
    /**
     * LP-005's "Other downloadable resources" broadening: word-processing
     * documents (doc/docx/rtf/odt — guides, fanlisting text) and rar/7z
     * archives (downloadable themes, icon packs, wallpaper sets) on top of
     * the original image/document/archive/audio/video set. SVG is
     * deliberately excluded — unlike every other image type here, a
     * browser executes an SVG's embedded `<script>` when it's opened
     * directly (not `<img>`-embedded, but navigated to as its own
     * document, which every media file here is reachable as via its plain
     * static URL — see the class docblock), a stored-XSS risk WordPress
     * itself excludes SVG from its own default allow-list for the same
     * reason.
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico',
        'pdf', 'zip', 'css', 'txt', 'xml', 'json',
        'doc', 'docx', 'rtf', 'odt', 'rar', '7z',
        'mp3', 'ogg', 'wav', 'm4a',
        'mp4', 'webm', 'mov', 'vtt',
    ];

    /**
     * text/vtt is listed alongside text/plain (LP-031's video
     * captions/subtitles) because mime_content_type()'s detection of a
     * .vtt file is not consistent across systems' magic databases — some
     * report text/vtt, others fall back to text/plain, which was already
     * allowed for other reasons. Both are accepted so a WebVTT upload
     * isn't rejected purely due to which magic database the host happens
     * to have installed.
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
     * Per-request memoization for find() (LP-008 Performance) — a single
     * post/archive/search-results render can call the Featured Image theme
     * API (has_post_thumbnail()/the_post_thumbnail()/
     * the_post_thumbnail_lightbox()) several times against the same media
     * id for one item, and the same media id often repeats across several
     * items in a listing. One instance of this service lives for the
     * whole request (built once in bootstrap.php), so caching by id here
     * collapses those into a single query without needing a shared cache
     * store. Every write method below that can change a row already in
     * this cache must evict it — see each one's own note.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private array $findCache = [];

    /**
     * @param Closure(string, string): bool|null $moveUploadedFile Overrides
     *     the default storage driver's file-move operation — see
     *     LocalFilesystemStorage's constructor docblock for why tests need
     *     this. Ignored when $storage is given explicitly.
     * @param ?HookManager $hooks Optional (LP-037) — see PostService's
     *     docblock for why; fires 'media_saved'/'media_deleted' for cache
     *     invalidation.
     * @param ?MediaStorageInterface $storage LP-005's storage abstraction
     *     — defaults to local disk (LocalFilesystemStorage, built from
     *     $uploadsPath/$uploadsUrl/$moveUploadedFile) when omitted. A
     *     future S3/R2 driver plugs in here without this class changing.
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
     * LP-005's "Replace" action — swaps a media item's file content in
     * place while keeping its id, file_path, and public URL exactly as
     * they were, so every post/page/setting already pointing at it keeps
     * working without edits. Deliberately a single-item action, not a
     * bulk one: there's no sensible UI for mapping several different
     * replacement files onto several different selected items in one
     * bulk-action submit (see TODO.md's LP-005 entry).
     *
     * The replacement file's extension must match the original's exactly
     * — the only way file_path (and so the URL) can stay untouched
     * without also risking a MIME/extension mismatch on disk.
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
        if (array_key_exists($id, $this->findCache)) {
            return $this->findCache[$id];
        }

        return $this->findCache[$id] = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
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
     * LP-005's bulk "Rename" action: a find/replace substring rename
     * across every selected item's display name. Renames file_name only —
     * like move()/updateMetadata(), never touches file_path, so a file's
     * public URL never changes just because its display name did (see
     * class docblock). A no-op ($find === '') is refused rather than
     * silently doing nothing, since str_contains('', '') is always true
     * and would otherwise "match" (and leave unchanged, since
     * str_replace('', $replace, $name) === $name) every selected item.
     *
     * @param array<int, int> $ids
     * @return int How many of the selected ids actually had $find in
     *     their name (and so were renamed) — items without a match are
     *     left untouched, not renamed to a duplicate/garbage name.
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
     * LP-005's bulk "Change metadata" action. Unlike updateMetadata()
     * (single-item edit form, where every field is always submitted and a
     * blank field means "clear this"), a bulk edit only ever sets fields
     * the admin actually filled in — null here means "leave this field's
     * existing value alone" for every selected item, not "clear it",
     * since applying a blank caption/description/notes to every selected
     * item at once would otherwise silently wipe out per-file text nobody
     * asked to remove.
     *
     * @param array<int, int> $ids
     * @return int How many ids were updated (every valid id, regardless
     *     of which fields were non-null)
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
     * LP-031's video poster image and caption/subtitle track: both are
     * just references to other media items (an image for the poster, a
     * .vtt file for the track), so no new upload path is needed — an
     * admin picks from already-uploaded media via a <select>. A separate
     * method rather than folding into updateMetadata() since these two
     * fields are video-specific, not part of the generic alt/caption/
     * description/notes set every media type shares.
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
     * Filterable listing, replacing the old browse()/search() split.
     *
     * @param array{folderIds?: array<int, int>, unassignedOnly?: bool, term?: string, type?: string, dateFrom?: string, dateTo?: string, widthMin?: int, widthMax?: int, heightMin?: int, heightMax?: int, sizeMin?: int, sizeMax?: int} $filters
     *     folderIds: restrict to these folder ids — callers pass exactly
     *     the folder(s) they mean (e.g. admin/views/media/media.php's
     *     folder view passes only the current folder's own id, not its
     *     descendants, so a parent folder's view never shows what's
     *     filed under a child folder). unassignedOnly:
     *     restrict to files with no folder ("General Uploads"); ignored if
     *     folderIds is set. term: matches file_name. type: one of
     *     TYPE_CATEGORY_MIME_TYPES's keys. dateFrom/dateTo: 'Y-m-d' strings.
     *     widthMin/widthMax/heightMin/heightMax: pixel bounds against the
     *     width/height columns (NULL for non-image files, so these
     *     necessarily exclude them — matches the fact that a size range
     *     only makes sense for images in the first place). sizeMin/sizeMax:
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
     * Largest files first, any type — LP-005's "Large Files" Smart
     * Collection. An unpaginated, capped list, the same "quick, capped
     * listing" precedent LP-006's built-in views (mostDownloaded() etc. in
     * MediaStatsService) already established.
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
     * Images with no alt text set — LP-005's "Missing Alt Text" Smart
     * Collection, an accessibility-maintenance aid (see this project's
     * Accessibility goal). Non-image files are excluded outright since alt
     * text is meaningless for them.
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
     * Full media rows for a set of ids, in no particular guaranteed order
     * beyond upload date — used to render a Smart Collection built from ids
     * gathered elsewhere (e.g. MediaUsageChecker's featured-image lookups)
     * rather than a query() filter.
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
     * The file's real filesystem location — needed by callers that must
     * read the file's actual bytes (LP-005's bulk ZIP download) rather
     * than just link to it.
     *
     * @param array<string, mixed> $media
     */
    public function absolutePath(array $media): string
    {
        return $this->storage->absolutePath((string) $media['file_path']);
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
