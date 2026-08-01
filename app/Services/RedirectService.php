<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * Admin-managed URL redirects (LP-022) — checked by
 * SiteController::notFound() before it actually renders a 404, so
 * retiring/renaming a post or page (or migrating from another CMS with
 * different URLs) doesn't have to mean a dead link. Returns plain arrays
 * rather than a dedicated model class, the same lightweight-entity
 * convention MediaService uses — there's no behavior here beyond CRUD and
 * a hit counter, nothing that benefits from a typed object.
 */
final class RedirectService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $sourcePath, string $targetUrl, int $statusCode = 301): array
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . ' (source_path, target_url, status_code, created_at, updated_at)
             VALUES (:source_path, :target_url, :status_code, :created_at, :updated_at)',
            [
                'source_path' => ltrim($sourcePath, '/'),
                'target_url' => $targetUrl,
                'status_code' => $statusCode,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $redirect = $this->find((int) $id);

        if ($redirect === null) {
            throw new RuntimeException('Failed to load the redirect that was just created.');
        }

        return $redirect;
    }

    public function update(int $id, string $sourcePath, string $targetUrl, int $statusCode): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET source_path = :source_path, target_url = :target_url, status_code = :status_code, updated_at = :updated_at
              WHERE id = :id',
            [
                'source_path' => ltrim($sourcePath, '/'),
                'target_url' => $targetUrl,
                'status_code' => $statusCode,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
    }

    /**
     * Looked up by SiteController::notFound() with the current request's
     * path (leading slash stripped, same convention canonical_url()'s
     * BasePath::stripFrom() call already produces).
     *
     * @return array<string, mixed>|null
     */
    public function findBySourcePath(string $sourcePath): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE source_path = :source_path',
            ['source_path' => ltrim($sourcePath, '/')],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' ORDER BY created_at DESC');
    }

    public function recordHit(int $id): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET hit_count = hit_count + 1 WHERE id = :id',
            ['id' => $id],
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'redirects';
    }
}
