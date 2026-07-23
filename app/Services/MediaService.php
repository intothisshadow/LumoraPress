<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * Basic media library: validated uploads, browsing, search, and deletion.
 * Advanced image management (editing, cropping, galleries) is intentionally
 * out of scope — that remains the domain of Lumora Gallery.
 */
final class MediaService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly string $uploadsPath,
        private readonly string $uploadsUrl,
    ) {
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array<string, mixed>
     */
    public function upload(array $file, int $uploadedByUserId): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File exceeds the maximum upload size.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('File type is not allowed.');
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: '';

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
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

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
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

        $relativePath = "{$year}/{$month}/{$filename}";

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (file_name, file_path, mime_type, file_size, width, height, uploaded_by, uploaded_at)
             VALUES (:file_name, :file_path, :mime_type, :file_size, :width, :height, :uploaded_by, :uploaded_at)',
            [
                'file_name' => $file['name'],
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => $file['size'],
                'width' => $width,
                'height' => $height,
                'uploaded_by' => $uploadedByUserId,
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
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function browse(int $limit = 40, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " ORDER BY uploaded_at DESC LIMIT {$limit} OFFSET {$offset}",
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 40): array
    {
        $limit = max(1, $limit);

        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE file_name LIKE :term ORDER BY uploaded_at DESC LIMIT {$limit}",
            ['term' => '%' . $term . '%'],
        );
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

    private function sanitizeFilename(string $name): string
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
