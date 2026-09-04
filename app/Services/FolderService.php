<?php

/**
 * Virtual media folders, purely organizational and independent of a file's actual filesystem location.
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

use DateTimeImmutable;
use InvalidArgumentException;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\Folder;
use RuntimeException;

/**
 * Virtual media folders — purely organizational, never touching the physical filesystem or
 * a file's public URL, since MediaService keys off file_path, never folder_id.
 *
 * Unlike CategoryService/PageService's parent_id hierarchy, which only guards against a
 * record becoming its own direct parent, folders support unlimited nesting with full
 * ancestor-chain cycle prevention via descendantIds(), done in PHP rather than extra SQL
 * WHERE clauses — sidestepping the repeated-placeholder issue CategoryService's docblock
 * describes.
 */
final class FolderService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function create(string $name, ?int $parentId = null): Folder
    {
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . ' (name, parent_id, created_at, updated_at)
             VALUES (:name, :parent_id, :created_at, :updated_at)',
            [
                'name' => $name,
                'parent_id' => $parentId,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        // A brand-new folder can't be its own parent (defensive; the
        // admin UI never offers a not-yet-created folder as an option).
        if ($parentId !== null && $parentId === (int) $id) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE id = :id',
                ['id' => (int) $id],
            );
        }

        $folder = $this->findById((int) $id);

        if ($folder === null) {
            throw new RuntimeException('Failed to load the folder that was just created.');
        }

        return $folder;
    }

    /**
     * @throws InvalidArgumentException if $parentId is a descendant of $id
     *     (would create a cycle) — a folder becoming its own direct
     *     parent is instead silently cleared, matching Category/Page's
     *     existing convention for that narrower case.
     */
    public function update(int $id, string $name, ?int $parentId = null): Folder
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Folder {$id} does not exist.");
        }

        if ($parentId === $id) {
            $parentId = null;
        } elseif ($parentId !== null && in_array($parentId, $this->descendantIds($id), true)) {
            throw new InvalidArgumentException('A folder cannot be moved inside one of its own subfolders.');
        }

        $now = new DateTimeImmutable();

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET name = :name, parent_id = :parent_id, updated_at = :updated_at
              WHERE id = :id',
            [
                'name' => $name,
                'parent_id' => $parentId,
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $folder = $this->findById($id);

        if ($folder === null) {
            throw new RuntimeException('Failed to load the folder that was just updated.');
        }

        return $folder;
    }

    /**
     * Refuses to delete a non-empty folder (child folders or assigned
     * media) rather than orphaning its contents, unlike
     * CategoryService::delete()'s orphan-on-delete precedent.
     */
    public function delete(int $id): bool
    {
        $hasChildFolders = $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE parent_id = :parent_id',
            ['parent_id' => $id],
        );

        if ((int) $hasChildFolders > 0) {
            return false;
        }

        $hasMedia = $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->mediaTable() . ' WHERE folder_id = :folder_id',
            ['folder_id' => $id],
        );

        if ((int) $hasMedia > 0) {
            return false;
        }

        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    public function findById(int $id): ?Folder
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, Folder>
     */
    public function listAll(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' ORDER BY name ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Every folder as a flat, depth-tagged list in hierarchical document
     * order (a parent immediately followed by its own children,
     * alphabetical among siblings, then the next sibling) — mirrors
     * CategoryService::listAllForTree()/PageService::listAllForTree()'s
     * identical shape, for the editor "Insert Image" picker's Folder
     * filter to render indented the same way those other nested pickers
     * already do.
     *
     * @return array<int, array{folder: Folder, depth: int}>
     */
    public function listAllForTree(): array
    {
        return $this->flattenForTree($this->listAll(), null, 0);
    }

    /**
     * @param array<int, Folder> $folders
     * @return array<int, array{folder: Folder, depth: int}>
     */
    private function flattenForTree(array $folders, ?int $parentId, int $depth): array
    {
        $result = [];

        foreach ($folders as $folder) {
            if ($folder->parentId !== $parentId) {
                continue;
            }

            $result[] = ['folder' => $folder, 'depth' => $depth];
            $result = [...$result, ...$this->flattenForTree($folders, $folder->id, $depth + 1)];
        }

        return $result;
    }

    /**
     * Folders whose name contains $term (case-insensitive), plus every ancestor of each
     * match, so the sidebar tree can render each match in its proper place rather than as
     * disconnected leaves. Built from one listAll() call, not a query per folder.
     *
     * @return array<int, Folder>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $all = $this->listAll();
        $byId = [];

        foreach ($all as $folder) {
            $byId[$folder->id] = $folder;
        }

        $resultIds = [];

        foreach ($all as $folder) {
            if (!str_contains(strtolower($folder->name), strtolower($term))) {
                continue;
            }

            $current = $folder;

            while ($current !== null) {
                $resultIds[$current->id] = true;
                $current = $current->parentId !== null ? ($byId[$current->parentId] ?? null) : null;
            }
        }

        return array_values(array_filter($all, static fn (Folder $folder): bool => isset($resultIds[$folder->id])));
    }

    /**
     * Every descendant of $id (children, grandchildren, ...), NOT
     * including $id itself — built from one query (listAll()) rather
     * than one query per level, so it stays cheap regardless of tree
     * depth.
     *
     * @return array<int, int>
     */
    public function descendantIds(int $id): array
    {
        $childrenByParent = [];

        foreach ($this->listAll() as $folder) {
            if ($folder->parentId !== null) {
                $childrenByParent[$folder->parentId][] = $folder->id;
            }
        }

        $descendants = [];
        $queue = $childrenByParent[$id] ?? [];

        while ($queue !== []) {
            $childId = array_shift($queue);
            $descendants[] = $childId;

            foreach ($childrenByParent[$childId] ?? [] as $grandchildId) {
                $queue[] = $grandchildId;
            }
        }

        return $descendants;
    }

    /**
     * Every ancestor of $id (parent, grandparent, ...), NOT including $id
     * itself — the sidebar tree uses this to force a folder open
     * regardless of its own saved collapsed state whenever it's on the
     * path to the currently active folder, so navigating into a folder
     * never leaves it hidden inside a collapsed ancestor. Built from one
     * listAll() call, matching descendantIds()'s "single query regardless
     * of tree depth" shape.
     *
     * @return array<int, int>
     */
    public function ancestorIds(int $id): array
    {
        $byId = [];

        foreach ($this->listAll() as $folder) {
            $byId[$folder->id] = $folder;
        }

        $ancestors = [];
        $current = $byId[$id] ?? null;

        while ($current !== null && $current->parentId !== null) {
            $ancestors[] = $current->parentId;
            $current = $byId[$current->parentId] ?? null;
        }

        return $ancestors;
    }

    /**
     * Rolls per-folder direct item counts up through the tree so each folder's badge
     * reflects nested subfolders too. Built from one listAll() call, not descendantIds() per
     * folder. Unassigned items (folder_id 0/null) are never rolled into any folder's total.
     *
     * @param array<int, int> $directCounts folder_id => direct item count
     * @return array<int, int> folder_id => cumulative item count
     */
    public function cumulativeCounts(array $directCounts): array
    {
        $folders = $this->listAll();
        $childrenByParent = [];

        foreach ($folders as $folder) {
            if ($folder->parentId !== null) {
                $childrenByParent[$folder->parentId][] = $folder->id;
            }
        }

        $cumulative = [];

        $sumSubtree = function (int $id) use (&$sumSubtree, &$cumulative, $childrenByParent, $directCounts): int {
            $total = $directCounts[$id] ?? 0;

            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $total += $sumSubtree($childId);
            }

            return $cumulative[$id] = $total;
        };

        foreach ($folders as $folder) {
            if (!isset($cumulative[$folder->id])) {
                $sumSubtree($folder->id);
            }
        }

        return $cumulative;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Folder
    {
        return new Folder(
            id: (int) $row['id'],
            name: (string) $row['name'],
            parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'media_folders';
    }

    private function mediaTable(): string
    {
        return $this->tablePrefix . 'media';
    }
}
