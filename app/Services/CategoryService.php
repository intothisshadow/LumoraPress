<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\Category;
use RuntimeException;

/**
 * Category CRUD, slug generation, hierarchy, and the many-to-many
 * relationship with posts via {prefix}post_categories. Categories have no
 * author/ownership concept, unlike Posts/Pages — any user with edit_posts
 * can edit any category, and delete_posts is required to delete one.
 *
 * Every query here uses a distinct placeholder name per occurrence, even
 * when binding the same value twice: Database::connect() disables emulated
 * prepared statements, and MySQL's native prepare protocol throws
 * "SQLSTATE[HY093]: Invalid parameter number" if a named placeholder
 * repeats within one query — the exact bug fixed in PageService's
 * listAllForParentSelect() (see PHP-TEST-SUITE.md's "Known gaps").
 */
final class CategoryService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function create(string $name, string $description, ?int $parentId = null, ?string $slug = null): Category
    {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $name);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (name, slug, description, parent_id, created_at, updated_at)
             VALUES (:name, :slug, :description, :parent_id, :created_at, :updated_at)',
            [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
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
            throw new RuntimeException('Failed to load the category that was just created.');
        }

        return $category;
    }

    public function update(int $id, string $name, string $description, ?int $parentId = null, ?string $slug = null): Category
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Category {$id} does not exist.");
        }

        // A category can never be its own parent.
        if ($parentId === $id) {
            $parentId = null;
        }

        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $name, ignoreId: $id);
        $now = new DateTimeImmutable();

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET name = :name, slug = :slug, description = :description,
                    parent_id = :parent_id, updated_at = :updated_at
              WHERE id = :id',
            [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'parent_id' => $parentId,
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $category = $this->findById($id);

        if ($category === null) {
            throw new RuntimeException('Failed to load the category that was just updated.');
        }

        return $category;
    }

    /**
     * Orphans any child categories (their parent_id becomes NULL rather
     * than cascading the delete to them), removes this category's
     * post_categories rows, then deletes the category itself.
     */
    public function delete(int $id): bool
    {
        return (bool) $this->database->transaction(function () use ($id): int {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE parent_id = :parent_id',
                ['parent_id' => $id],
            );

            $this->database->execute(
                'DELETE FROM ' . $this->postCategoriesTable() . ' WHERE category_id = :category_id',
                ['category_id' => $id],
            );

            return $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE id = :id',
                ['id' => $id],
            );
        });
    }

    public function findById(int $id): ?Category
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Category
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, Category>
     */
    public function listAll(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' ORDER BY name ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * All categories with their assigned post count, for the admin list —
     * one query rather than one COUNT() per category.
     *
     * @return array<int, array{category: Category, postCount: int}>
     */
    public function listAllWithPostCounts(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, COUNT(pc.post_id) AS post_count
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postCategoriesTable() . ' pc ON pc.category_id = c.id
              GROUP BY c.id
              ORDER BY c.name ASC',
        );

        return array_map(
            fn (array $row): array => ['category' => $this->hydrate($row), 'postCount' => (int) $row['post_count']],
            $rows,
        );
    }

    /**
     * A flat list of {id, name} suitable for a "Parent Category" <select>,
     * excluding $excludeId itself and its direct children (so a category
     * can't be made the parent of its own parent one level up — deeper
     * cycles are an accepted gap for this basic, non-tree parent selector,
     * matching PageService's identical trade-off).
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function listAllForParentSelect(?int $excludeId = null): array
    {
        if ($excludeId === null) {
            $rows = $this->database->fetchAll('SELECT id, name FROM ' . $this->table() . ' ORDER BY name ASC');
        } else {
            $rows = $this->database->fetchAll(
                'SELECT id, name FROM ' . $this->table() . '
                    WHERE id != :exclude_id AND (parent_id IS NULL OR parent_id != :exclude_id_2)
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
     * @return array<int, Category>
     */
    public function categoriesForPost(int $postId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.* FROM ' . $this->table() . ' c
                INNER JOIN ' . $this->postCategoriesTable() . ' pc ON pc.category_id = c.id
             WHERE pc.post_id = :post_id
             ORDER BY c.name ASC',
            ['post_id' => $postId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function postCount(int $categoryId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->postCategoriesTable() . ' WHERE category_id = :category_id',
            ['category_id' => $categoryId],
        );
    }

    /**
     * Replaces every category assignment for a post with $categoryIds.
     *
     * @param array<int, int|string> $categoryIds
     */
    public function assignToPost(int $postId, array $categoryIds): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $categoryIds),
            static fn (int $id): bool => $id > 0,
        )));

        $this->database->transaction(function () use ($postId, $ids): void {
            $this->database->execute(
                'DELETE FROM ' . $this->postCategoriesTable() . ' WHERE post_id = :post_id',
                ['post_id' => $postId],
            );

            foreach ($ids as $categoryId) {
                $this->database->execute(
                    'INSERT INTO ' . $this->postCategoriesTable() . ' (post_id, category_id) VALUES (:post_id, :category_id)',
                    ['post_id' => $postId, 'category_id' => $categoryId],
                );
            }
        });
    }

    private function generateUniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = $this->slugify($source);
        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $ignoreId): bool
    {
        $sql = 'SELECT id FROM ' . $this->table() . ' WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $ignoreId;
        }

        return $this->database->fetchOne($sql, $params) !== null;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'category' : $slug;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Category
    {
        return new Category(
            id: (int) $row['id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            description: (string) ($row['description'] ?? ''),
            parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'categories';
    }

    private function postCategoriesTable(): string
    {
        return $this->tablePrefix . 'post_categories';
    }
}
