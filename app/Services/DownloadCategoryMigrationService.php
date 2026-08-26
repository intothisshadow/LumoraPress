<?php

/**
 * One-time backfill from a Download's old Folder-based categorization to its new dedicated category taxonomy (LPP-011).
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
 * Before LPP-011, a Download's "category" was really a Media Manager
 * Folder (`downloads.folder_id`, pointing at `media_folders`) — every
 * Download created before this ticket shipped is still categorized that
 * way. This service turns each such Folder into a real
 * `download_categories` row (preserving name and parent hierarchy) and
 * backfills `downloads.category_id` to point at it, so upgrading never
 * silently loses a download's existing categorization.
 *
 * Deliberately writes directly via parameterized SQL rather than going
 * through DownloadCategoryService — that class lives in the Downloads
 * plugin (content/plugins/downloads/src/), not core, and this repair must
 * still run correctly even on an install where that plugin happens to be
 * inactive at the moment an admin runs it from Maintenance > Tools (the
 * `download_categories`/`downloads` tables themselves are core migrations,
 * independent of the plugin's own active/inactive state) — mirrors
 * EntityDecodeRepairService's identical "bypass the normal service layer"
 * reasoning for the same kind of one-time, narrowly-scoped repair.
 *
 * Idempotent: a Folder already turned into a matching (by name + parent)
 * `download_categories` row on an earlier run is reused rather than
 * duplicated, and a Download that already has `category_id` set is left
 * untouched — safe to run more than once, and a no-op once every
 * Folder-categorized Download has been backfilled.
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
     * Resolves (creating if needed) the download category that should
     * stand in for $folderId, walking up the Folder's own parent_id chain
     * first so a nested Folder tree (e.g. "Digital Paper" >
     * "Game Of Thrones Papers") becomes an equally nested category tree,
     * not a flattened one. Memoized in $categoryIdByFolderId across the
     * whole migrate() run so a Folder referenced by many downloads (or
     * appearing as an ancestor of several other Folders) is only ever
     * resolved once.
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
