<?php

/**
 * Virtual media folders (LP-005), purely organizational and independent of a file's actual filesystem location.
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
 * Virtual media folders (LP-005) — purely organizational, never touching
 * the physical filesystem or a file's public URL (MediaService::url()/
 * delete() are keyed off file_path, never folder_id, so reassigning a
 * file's folder is a plain UPDATE with zero effect on where the file
 * actually lives or how it's served).
 *
 * Unlike CategoryService/PageService's parent_id hierarchy — which only
 * guards against a record becoming its own *direct* parent, an accepted,
 * documented shallow trade-off in this codebase — folders support
 * unlimited nesting with full ancestor-chain cycle prevention via
 * descendantIds(), since a Media Manager's folder tree is expected to go
 * deeper than a category list ever would.
 *
 * Unlike CategoryService's listAllForParentSelect(), cycle exclusion
 * happens in PHP (descendantIds()) rather than as extra WHERE clauses in
 * SQL, so no query here binds the same value under two placeholder
 * names — sidestepping that whole class of bug (Database::connect()
 * disables emulated prepares, and MySQL's native protocol rejects a
 * repeated named placeholder; see CategoryService's docblock) rather than
 * having to get it right per-query.
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
     * media) rather than orphaning its contents — LP-005's checklist
     * explicitly says "Delete empty folders," unlike
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
     * Folders whose name contains $term (case-insensitive), plus every
     * ancestor of each match — LP-005's "Folder search". Ancestors are
     * included so the sidebar tree (which can only render a folder once
     * its parent chain up to the root is also present) still shows each
     * match in its proper place rather than as a set of disconnected
     * leaves. Built from one listAll() call rather than a query per
     * folder, the same "small dataset, filter in PHP" approach
     * descendantIds() already uses.
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
