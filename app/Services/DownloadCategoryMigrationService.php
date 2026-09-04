<?php

/**
 * One-time backfill from a Download's old Folder-based categorization to its new dedicated category taxonomy.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;

/**
 * Originally, a Download's "category" was really a Media Manager Folder
 * (`downloads.folder_id`). This service turns each such Folder into a real
 * `download_categories` row (preserving name and parent hierarchy) and backfills
 * `downloads.category_id`, so upgrading never silently loses a download's categorization.
 *
 * Writes directly via parameterized SQL rather than through DownloadCategoryService, since
 * that class lives in the Downloads plugin and this repair must still run even when the
 * plugin is inactive — mirrors EntityDecodeRepairService's reasoning.
 *
 * Idempotent: a Folder already matched to a `download_categories` row is reused, and a
 * Download that already has `category_id` set is left untouched.
 */
final class DownloadCategoryMigrationService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * @return array{categoriesCreated: int, downloadsBackfilled: int}
     */
    public function migrate(): array
    {
        $folderIds = array_map(
            static fn (array $row): int => (int) $row['folder_id'],
            $this->database->fetchAll(
                'SELECT DISTINCT folder_id FROM ' . $this->downloadsTable() . ' WHERE category_id IS NULL AND folder_id IS NOT NULL',
            ),
        );

        $categoriesCreated = 0;
        $downloadsBackfilled = 0;
        $categoryIdByFolderId = [];

        foreach ($folderIds as $folderId) {
            $categoryId = $this->categoryIdForFolder($folderId, $categoryIdByFolderId, $categoriesCreated);

            if ($categoryId === null) {
                continue;
            }

            $downloadsBackfilled += $this->database->execute(
                'UPDATE ' . $this->downloadsTable() . ' SET category_id = :category_id WHERE folder_id = :folder_id AND category_id IS NULL',
                ['category_id' => $categoryId, 'folder_id' => $folderId],
            );
        }

        return ['categoriesCreated' => $categoriesCreated, 'downloadsBackfilled' => $downloadsBackfilled];
    }

    /**
     * Resolves (creating if needed) the download category standing in for $folderId,
     * walking up the Folder's parent_id chain first so a nested Folder tree stays nested,
     * not flattened. Memoized in $categoryIdByFolderId so a shared Folder is resolved once.
     *
     * @param array<int, int> $categoryIdByFolderId
     */
    private function categoryIdForFolder(int $folderId, array &$categoryIdByFolderId, int &$categoriesCreated): ?int
    {
        if (isset($categoryIdByFolderId[$folderId])) {
            return $categoryIdByFolderId[$folderId];
        }

        $folder = $this->database->fetchOne(
            'SELECT * FROM ' . $this->foldersTable() . ' WHERE id = :id',
            ['id' => $folderId],
        );

        if ($folder === null) {
            return null;
        }

        $parentCategoryId = null;

        if ($folder['parent_id'] !== null) {
            $parentCategoryId = $this->categoryIdForFolder((int) $folder['parent_id'], $categoryIdByFolderId, $categoriesCreated);
        }

        $categoryId = $this->findOrCreateCategory((string) $folder['name'], $parentCategoryId, $categoriesCreated);
        $categoryIdByFolderId[$folderId] = $categoryId;

        return $categoryId;
    }

    private function findOrCreateCategory(string $name, ?int $parentId, int &$categoriesCreated): int
    {
        $existing = $parentId !== null
            ? $this->database->fetchOne(
                'SELECT id FROM ' . $this->categoriesTable() . ' WHERE name = :name AND parent_id = :parent_id',
                ['name' => $name, 'parent_id' => $parentId],
            )
            : $this->database->fetchOne(
                'SELECT id FROM ' . $this->categoriesTable() . ' WHERE name = :name AND parent_id IS NULL',
                ['name' => $name],
            );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->categoriesTable() . ' (name, parent_id, created_at, updated_at)
             VALUES (:name, :parent_id, :created_at, :updated_at)',
            ['name' => $name, 'parent_id' => $parentId, 'created_at' => $now, 'updated_at' => $now],
        );

        $categoriesCreated++;

        return (int) $id;
    }

    private function downloadsTable(): string
    {
        return $this->tablePrefix . 'downloads';
    }

    private function foldersTable(): string
    {
        return $this->tablePrefix . 'media_folders';
    }

    private function categoriesTable(): string
    {
        return $this->tablePrefix . 'download_categories';
    }
}
