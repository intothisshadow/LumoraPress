<?php

/**
 * CRUD, hierarchy, and lifecycle for Downloads' own dedicated category taxonomy.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * A Download has exactly one category (a single FK column, no join table),
 * with CategoryService's trash-then-delete lifecycle and merge() so a
 * leftover category is removable even while downloads still reference it.
 *
 * Every query uses a distinct placeholder name per occurrence — MySQL's
 * native prepare protocol rejects a repeated named placeholder.
 */
final class DownloadCategoryService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function create(string $name, ?int $parentId = null): DownloadCategory
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

        // A brand-new category can't be its own parent (defensive; the
        // admin UI never offers a not-yet-created category as an option).
        if ($parentId !== null && $parentId === (int) $id) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE id = :id',
                ['id' => (int) $id],
            );
        }

        $category = $this->findById((int) $id);

        if ($category === null) {
            throw new RuntimeException('Failed to load the download category that was just created.');
        }

        return $category;
    }

    public function update(int $id, string $name, ?int $parentId = null): DownloadCategory
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Download category {$id} does not exist.");
        }

        // A category can never be its own parent.
        if ($parentId === $id) {
            $parentId = null;
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

        $category = $this->findById($id);

        if ($category === null) {
            throw new RuntimeException('Failed to load the download category that was just updated.');
        }

        return $category;
    }

    public function trash(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = :trashed_at WHERE id = :id',
            ['trashed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = NULL WHERE id = :id',
            ['id' => $id],
        ) > 0;
    }

    /**
     * Orphans any child categories (parent_id becomes NULL rather than
     * cascading the delete to them), clears category_id on any Download
     * still referencing this category (left Uncategorized, not deleted),
     * then deletes the category itself.
     */
    public function delete(int $id): bool
    {
        return (bool) $this->database->transaction(function () use ($id): int {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE parent_id = :parent_id',
                ['parent_id' => $id],
            );

            $this->database->execute(
                'UPDATE ' . $this->downloadsTable() . ' SET category_id = NULL WHERE category_id = :category_id',
                ['category_id' => $id],
            );

            return $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE id = :id',
                ['id' => $id],
            );
        });
    }

    /**
     * Permanently deletes every currently-trashed category in one action.
     * Reuses delete()'s own orphan/uncategorize behavior per row rather
     * than a raw bulk DELETE, so children and downloads are handled
     * safely instead of left with a dangling parent_id/category_id.
     *
     * @return int how many categories were removed
     */
    public function emptyTrash(): int
    {
        $trashedIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll('SELECT id FROM ' . $this->table() . ' WHERE trashed_at IS NOT NULL'),
        );

        $removed = 0;

        foreach ($trashedIds as $trashedId) {
            if ($this->delete($trashedId)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Merges $sourceId into $targetId: downloads and child categories are
     * reassigned, then $sourceId is deleted. Returns false if the ids are
     * equal or either doesn't exist.
     */
    public function merge(int $sourceId, int $targetId): bool
    {
        if ($sourceId === $targetId || $this->findById($sourceId) === null || $this->findById($targetId) === null) {
            return false;
        }

        $this->database->transaction(function () use ($sourceId, $targetId): void {
            $this->database->execute(
                'UPDATE ' . $this->downloadsTable() . ' SET category_id = :target_id WHERE category_id = :source_id',
                ['target_id' => $targetId, 'source_id' => $sourceId],
            );

            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = :target_id WHERE parent_id = :source_id AND id != :target_id_2',
                ['target_id' => $targetId, 'source_id' => $sourceId, 'target_id_2' => $targetId],
            );

            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE id = :target_id AND parent_id = :source_id',
                ['target_id' => $targetId, 'source_id' => $sourceId],
            );

            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE id = :id',
                ['id' => $sourceId],
            );
        });

        return true;
    }

    public function findById(int $id): ?DownloadCategory
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, DownloadCategory>
     */
    public function listAll(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' WHERE trashed_at IS NULL ORDER BY name ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @return array<int, array{category: DownloadCategory, downloadCount: int}>
     */
    public function listAllWithDownloadCounts(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, COUNT(d.id) AS download_count
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->downloadsTable() . " d ON d.category_id = c.id AND d.trashed_at IS NULL
              WHERE c.trashed_at IS NULL
              GROUP BY c.id
              ORDER BY c.name ASC",
        );

        return array_map(
            fn (array $row): array => ['category' => $this->hydrate($row), 'downloadCount' => (int) $row['download_count']],
            $rows,
        );
    }

    /**
     * @return array<int, array{category: DownloadCategory, downloadCount: int}>
     */
    public function listTrashedWithDownloadCounts(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, COUNT(d.id) AS download_count
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->downloadsTable() . " d ON d.category_id = c.id AND d.trashed_at IS NULL
              WHERE c.trashed_at IS NOT NULL
              GROUP BY c.id
              ORDER BY c.trashed_at DESC",
        );

        return array_map(
            fn (array $row): array => ['category' => $this->hydrate($row), 'downloadCount' => (int) $row['download_count']],
            $rows,
        );
    }

    public function count(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE trashed_at IS NULL');
    }

    public function trashedCount(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE trashed_at IS NOT NULL');
    }

    /**
     * A flat list of {id, name} suitable for a "Parent Category" <select>,
     * excluding $excludeId itself and its direct children.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function listAllForParentSelect(?int $excludeId = null): array
    {
        if ($excludeId === null) {
            $rows = $this->database->fetchAll('SELECT id, name FROM ' . $this->table() . ' WHERE trashed_at IS NULL ORDER BY name ASC');
        } else {
            $rows = $this->database->fetchAll(
                'SELECT id, name FROM ' . $this->table() . '
                    WHERE trashed_at IS NULL AND id != :exclude_id AND (parent_id IS NULL OR parent_id != :exclude_id_2)
                 ORDER BY name ASC',
                ['exclude_id' => $excludeId, 'exclude_id_2' => $excludeId],
            );
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $rows,
        );
    }

    /**
     * @return array<int, array{id: int, name: string, depth: int}>
     */
    public function listAllForParentPicker(?int $excludeId = null): array
    {
        $flattened = array_map(
            static fn (array $row): array => ['id' => $row['category']->id, 'name' => $row['category']->name, 'parentId' => $row['category']->parentId, 'depth' => $row['depth']],
            $this->listAllForTree(),
        );

        if ($excludeId === null) {
            return array_map(
                static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'depth' => $row['depth']],
                $flattened,
            );
        }

        return array_values(array_map(
            static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'depth' => $row['depth']],
            array_filter(
                $flattened,
                static fn (array $row): bool => $row['id'] !== $excludeId && $row['parentId'] !== $excludeId,
            ),
        ));
    }

    /**
     * @return array<int, array{category: DownloadCategory, depth: int}>
     */
    public function listAllForTree(): array
    {
        return $this->flattenForTree($this->listAll(), null, 0);
    }

    /**
     * @param array<int, DownloadCategory> $categories
     * @return array<int, array{category: DownloadCategory, depth: int}>
     */
    private function flattenForTree(array $categories, ?int $parentId, int $depth): array
    {
        $result = [];

        foreach ($categories as $category) {
            if ($category->parentId !== $parentId) {
                continue;
            }

            $result[] = ['category' => $category, 'depth' => $depth];
            $result = [...$result, ...$this->flattenForTree($categories, $category->id, $depth + 1)];
        }

        return $result;
    }

    public function downloadCount(int $categoryId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->downloadsTable() . ' WHERE category_id = :category_id AND trashed_at IS NULL',
            ['category_id' => $categoryId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DownloadCategory
    {
        return new DownloadCategory(
            id: (int) $row['id'],
            name: (string) $row['name'],
            parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            trashedAt: $row['trashed_at'] !== null ? new DateTimeImmutable((string) $row['trashed_at']) : null,
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'download_categories';
    }

    private function downloadsTable(): string
    {
        return $this->tablePrefix . 'downloads';
    }
}
