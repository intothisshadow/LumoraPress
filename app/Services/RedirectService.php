<?php

/**
 * Admin-managed URL redirects (LP-022), checked before a request would otherwise 404.
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
     * $folderId lets a redirect represent a Media Manager Folder's
     * external-link "download" (e.g. LPP-004's WordPress import, for a
     * Simple Download Monitor file that only links off-site) so it can
     * be listed alongside that folder's real Media items — every other
     * caller leaves it null, since a plain URL redirect has no folder
     * concept of its own.
     *
     * @return array<string, mixed>
     */
    public function create(string $sourcePath, string $targetUrl, int $statusCode = 301, ?int $folderId = null): array
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . ' (source_path, target_url, status_code, folder_id, created_at, updated_at)
             VALUES (:source_path, :target_url, :status_code, :folder_id, :created_at, :updated_at)',
            [
                'source_path' => ltrim($sourcePath, '/'),
                'target_url' => $targetUrl,
                'status_code' => $statusCode,
                'folder_id' => $folderId,
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByFolder(int $folderId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE folder_id = :folder_id ORDER BY source_path ASC',
            ['folder_id' => $folderId],
        );
    }

    /**
     * Sets a redirect's hit count directly, rather than incrementing it
     * — mirrors MediaStatsService::seed()'s reasoning: preserving a
     * historical count from an external source (LPP-004's WordPress
     * import, seeding a migrated download's count from Simple Download
     * Monitor's own total) instead of every migrated redirect silently
     * restarting at 0. Not meant to be called from recordHit()'s own
     * real-request increment path.
     */
    public function setHitCount(int $id, int $count): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET hit_count = :count WHERE id = :id',
            ['count' => $count, 'id' => $id],
        ) > 0;
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
