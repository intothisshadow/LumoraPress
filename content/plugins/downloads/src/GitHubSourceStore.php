<?php

/**
 * Remembers which GitHub repository a download was imported from, and which screenshot came from which file.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use LumoraPress\Core\Database\Database;

/**
 * The hashes let an update tell what changed upstream and whether the title or description was
 * edited by hand since the last import. Plain delete-then-insert writes (no MySQL-only upsert)
 * keep this exercisable against the test suite's SQLite fixtures.
 */
final class GitHubSourceStore
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function record(int $downloadId, GitHubRepositoryRef $ref, string $branch, string $titleHash, string $descriptionHash, string $readmeHash): void
    {
        $this->database->execute('DELETE FROM ' . $this->sourcesTable() . ' WHERE download_id = :id', ['id' => $downloadId]);
        $this->database->execute(
            'INSERT INTO ' . $this->sourcesTable() . ' (download_id, owner, repo, branch, title_hash, description_hash, readme_hash, synced_at)
             VALUES (:download_id, :owner, :repo, :branch, :title_hash, :description_hash, :readme_hash, :synced_at)',
            [
                'download_id' => $downloadId,
                'owner' => $ref->owner,
                'repo' => $ref->repo,
                'branch' => $branch,
                'title_hash' => $titleHash,
                'description_hash' => $descriptionHash,
                'readme_hash' => $readmeHash,
                'synced_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return array{ref: GitHubRepositoryRef, branch: string, titleHash: string, descriptionHash: string, readmeHash: string, syncedAt: string}|null
     */
    public function find(int $downloadId): ?array
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->sourcesTable() . ' WHERE download_id = :id', ['id' => $downloadId]);

        if ($row === null) {
            return null;
        }

        $ref = GitHubRepositoryRef::fromParts((string) $row['owner'], (string) $row['repo']);

        if ($ref === null) {
            return null;
        }

        return [
            'ref' => $ref,
            'branch' => (string) $row['branch'],
            'titleHash' => (string) $row['title_hash'],
            'descriptionHash' => (string) $row['description_hash'],
            'readmeHash' => (string) $row['readme_hash'],
            'syncedAt' => (string) $row['synced_at'],
        ];
    }

    public function downloadIdForRepository(GitHubRepositoryRef $ref): ?int
    {
        $id = $this->database->fetchColumn(
            'SELECT download_id FROM ' . $this->sourcesTable() . ' WHERE owner = :owner AND repo = :repo ORDER BY download_id ASC LIMIT 1',
            ['owner' => $ref->owner, 'repo' => $ref->repo],
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Repository full names ("owner/repo") for the downloads that have a source, keyed by download id.
     *
     * @param array<int, int> $downloadIds
     * @return array<int, string>
     */
    public function repositoriesForDownloads(array $downloadIds): array
    {
        $downloadIds = array_values(array_unique(array_map('intval', $downloadIds)));

        if ($downloadIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($downloadIds), '?'));
        $rows = $this->database->fetchAll(
            'SELECT download_id, owner, repo FROM ' . $this->sourcesTable() . ' WHERE download_id IN (' . $placeholders . ')',
            $downloadIds,
        );

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row['download_id']] = $row['owner'] . '/' . $row['repo'];
        }

        return $names;
    }

    /**
     * Repository names already imported for an owner, so the picker can say so.
     *
     * @return array<string, int> repo name => download id
     */
    public function importedRepositories(string $owner): array
    {
        $rows = $this->database->fetchAll(
            'SELECT repo, download_id FROM ' . $this->sourcesTable() . ' WHERE owner = :owner ORDER BY download_id ASC',
            ['owner' => $owner],
        );

        $imported = [];

        foreach ($rows as $row) {
            $imported[(string) $row['repo']] ??= (int) $row['download_id'];
        }

        return $imported;
    }

    /**
     * @return array<int, array{repoPath: string, blobSha: string, mediaId: int}>
     */
    public function images(int $downloadId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT repo_path, blob_sha, media_id FROM ' . $this->imagesTable() . ' WHERE download_id = :id ORDER BY id ASC',
            ['id' => $downloadId],
        );

        return array_map(static fn (array $row): array => [
            'repoPath' => (string) $row['repo_path'],
            'blobSha' => (string) $row['blob_sha'],
            'mediaId' => (int) $row['media_id'],
        ], $rows);
    }

    public function saveImage(int $downloadId, string $repoPath, string $blobSha, int $mediaId): void
    {
        $this->removeImage($downloadId, $repoPath);
        $this->database->execute(
            'INSERT INTO ' . $this->imagesTable() . ' (download_id, repo_path, blob_sha, media_id) VALUES (:download_id, :repo_path, :blob_sha, :media_id)',
            ['download_id' => $downloadId, 'repo_path' => $repoPath, 'blob_sha' => $blobSha, 'media_id' => $mediaId],
        );
    }

    public function removeImage(int $downloadId, string $repoPath): void
    {
        $this->database->execute(
            'DELETE FROM ' . $this->imagesTable() . ' WHERE download_id = :id AND repo_path = :path',
            ['id' => $downloadId, 'path' => $repoPath],
        );
    }

    /** Drops the mapping when a screenshot is deleted from the Media library, so the next update offers it as new. */
    public function removeImagesForMedia(int $mediaId): void
    {
        $this->database->execute('DELETE FROM ' . $this->imagesTable() . ' WHERE media_id = :id', ['id' => $mediaId]);
    }

    public function deleteForDownload(int $downloadId): void
    {
        $this->database->execute('DELETE FROM ' . $this->sourcesTable() . ' WHERE download_id = :id', ['id' => $downloadId]);
        $this->database->execute('DELETE FROM ' . $this->imagesTable() . ' WHERE download_id = :id', ['id' => $downloadId]);
    }

    private function sourcesTable(): string
    {
        return $this->tablePrefix . 'download_github_sources';
    }

    private function imagesTable(): string
    {
        return $this->tablePrefix . 'download_github_images';
    }
}
