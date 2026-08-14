<?php

/**
 * Category CRUD, slug generation, hierarchy, and the many-to-many relationship with posts.
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
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
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
 *
 * $hooks is optional (LP-037) — see PostService's docblock for why.
 */
final class CategoryService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
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

        $this->hooks?->doAction('category_saved', $category);

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

        $this->hooks?->doAction('category_saved', $category);

        return $category;
    }

    /**
     * Soft-deletes a category (LP-010 Trash) — mirrors PostService::trash()/
     * PageService::trash()'s trashed_at pattern, minus the status flip
     * neither of those need here since categories have no draft/published
     * workflow to fall back to. A trashed category is excluded from
     * listAll()/listAllWithPostCounts()/listAllForParentSelect()/
     * findBySlug()/categoriesForPost() — everywhere a category would
     * otherwise show up publicly or be offered for assignment — but stays
     * reachable via findById() so the admin Trash tab can still show and
     * restore it. There is no automatic purge; delete() (the admin UI's
     * "Delete Permanently", only offered for already-trashed categories)
     * is the only way to actually remove one.
     */
    public function trash(int $id): bool
    {
        $trashed = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = :trashed_at WHERE id = :id',
            ['trashed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;

        if ($trashed) {
            $this->hooks?->doAction('category_trashed', $id);
        }

        return $trashed;
    }

    /**
     * Restores a trashed category. Unlike PostService::restore()/
     * PageService::restore() there is no status to reset — clearing
     * trashed_at alone is enough to make the category publicly visible
     * again, exactly as it was before being trashed.
     */
    public function restore(int $id): bool
    {
        $restored = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = NULL WHERE id = :id',
            ['id' => $id],
        ) > 0;

        if ($restored) {
            $this->hooks?->doAction('category_restored', $id);
        }

        return $restored;
    }

    /**
     * Orphans any child categories (their parent_id becomes NULL rather
     * than cascading the delete to them), removes this category's
     * post_categories rows, then deletes the category itself.
     */
    public function delete(int $id): bool
    {
        $deleted = (bool) $this->database->transaction(function () use ($id): int {
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

        if ($deleted) {
            $this->hooks?->doAction('category_deleted', $id);
        }

        return $deleted;
    }

    /**
     * Merges $sourceId into $targetId: every post assigned to $sourceId
     * gains $targetId instead (via bulkAddToPosts(), so a post already in
     * both categories doesn't hit post_categories' composite-key
     * constraint twice), $sourceId's own post_categories rows are then
     * dropped, its child categories are reparented to $targetId, and
     * $sourceId itself is deleted. Mirrors delete()'s child-orphaning
     * shape but reparents instead of orphaning, since the whole point of
     * a merge is that $targetId inherits everything $sourceId had. If
     * $targetId was itself a child of $sourceId, it's orphaned rather
     * than reparented to itself — its old parent no longer exists after
     * the merge, and a category can never be its own parent (same rule
     * create()/update() already enforce).
     *
     * Returns false without changing anything if $sourceId and $targetId
     * are the same, or either doesn't exist.
     */
    public function merge(int $sourceId, int $targetId): bool
    {
        if ($sourceId === $targetId || $this->findById($sourceId) === null || $this->findById($targetId) === null) {
            return false;
        }

        $this->database->transaction(function () use ($sourceId, $targetId): void {
            $postIds = array_map(
                static fn (array $row): int => (int) $row['post_id'],
                $this->database->fetchAll(
                    'SELECT post_id FROM ' . $this->postCategoriesTable() . ' WHERE category_id = :category_id',
                    ['category_id' => $sourceId],
                ),
            );

            if ($postIds !== []) {
                $this->bulkAddToPosts($postIds, $targetId);
            }

            $this->database->execute(
                'DELETE FROM ' . $this->postCategoriesTable() . ' WHERE category_id = :category_id',
                ['category_id' => $sourceId],
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

        $this->hooks?->doAction('category_merged', $sourceId, $targetId);

        return true;
    }

    public function findById(int $id): ?Category
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Excludes trashed categories — a trashed category's archive/feed must
     * 404 like any other unknown slug, not keep serving stale content.
     */
    public function findBySlug(string $slug): ?Category
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE slug = :slug AND trashed_at IS NULL',
            ['slug' => $slug],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Case-insensitive lookup, falling back to create() — mirrors
     * TagService::findOrCreateByName(), the same "don't let 'Sci-Fi' and
     * 'sci-fi' become two different terms" guard. Backs the post editor's
     * "Create categories while editing" affordance (LP-008): typing a name
     * that already exists reuses that category instead of creating a
     * near-duplicate with a numeric-suffixed slug.
     */
    public function findOrCreateByName(string $name, ?int $parentId = null): Category
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE LOWER(name) = LOWER(:name)',
            ['name' => $name],
        );

        if ($row !== null) {
            return $this->hydrate($row);
        }

        return $this->create($name, '', $parentId);
    }

    /**
     * Excludes trashed categories, matching every other default-listing
     * method here — see trash()'s docblock.
     *
     * @return array<int, Category>
     */
    public function listAll(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' WHERE trashed_at IS NULL ORDER BY name ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Non-trashed categories with their assigned post count, for the
     * admin list's default ("All") view — one query rather than one
     * COUNT() per category.
     *
     * @return array<int, array{category: Category, postCount: int}>
     */
    public function listAllWithPostCounts(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, COUNT(pc.post_id) AS post_count
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postCategoriesTable() . ' pc ON pc.category_id = c.id
              WHERE c.trashed_at IS NULL
              GROUP BY c.id
              ORDER BY c.name ASC',
        );

        return array_map(
            fn (array $row): array => ['category' => $this->hydrate($row), 'postCount' => (int) $row['post_count']],
            $rows,
        );
    }

    /**
     * Trashed categories with their assigned post count, for the admin
     * list's Trash tab — same shape as listAllWithPostCounts(), ordered
     * most-recently-trashed first so the newest arrivals surface at the
     * top, matching PageService's Trash tab convention.
     *
     * @return array<int, array{category: Category, postCount: int}>
     */
    public function listTrashedWithPostCounts(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, COUNT(pc.post_id) AS post_count
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postCategoriesTable() . ' pc ON pc.category_id = c.id
              WHERE c.trashed_at IS NOT NULL
              GROUP BY c.id
              ORDER BY c.trashed_at DESC',
        );

        return array_map(
            fn (array $row): array => ['category' => $this->hydrate($row), 'postCount' => (int) $row['post_count']],
            $rows,
        );
    }

    public function trashedCount(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE trashed_at IS NOT NULL',
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
     * Excludes trashed categories — a post keeps its post_categories row
     * for a trashed category (trashing never touches assignments), but it
     * must stop appearing as a clickable badge/link once its own archive
     * page 404s, matching findBySlug()'s exclusion.
     *
     * @return array<int, Category>
     */
    public function categoriesForPost(int $postId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.* FROM ' . $this->table() . ' c
                INNER JOIN ' . $this->postCategoriesTable() . ' pc ON pc.category_id = c.id
             WHERE pc.post_id = :post_id AND c.trashed_at IS NULL
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

    /**
     * Adds $categoryId to $postId's existing category assignments without
     * touching any other category already assigned — unlike
     * assignToPost() (which replaces the full set, used by the single-post
     * edit form), this backs LP-008's bulk "Change category" action, where
     * "change" means "add this category to every selected post", not
     * "replace each post's entire category list with just this one".
     * post_categories has a composite (post_id, category_id) primary key,
     * so a duplicate INSERT would fail — check-then-insert rather than an
     * `ON DUPLICATE KEY` upsert keeps this portable to the SQLite-backed
     * unit tests, the same precedent MediaStatsService::recordDownload()
     * already established.
     *
     * @param array<int, int> $postIds
     * @return int how many posts actually gained the assignment (already-
     *     assigned posts are skipped, not counted)
     */
    public function bulkAddToPosts(array $postIds, int $categoryId): int
    {
        $added = 0;

        foreach (array_unique(array_map('intval', $postIds)) as $postId) {
            $exists = $this->database->fetchOne(
                'SELECT 1 FROM ' . $this->postCategoriesTable() . ' WHERE post_id = :post_id AND category_id = :category_id',
                ['post_id' => $postId, 'category_id' => $categoryId],
            ) !== null;

            if ($exists) {
                continue;
            }

            $this->database->execute(
                'INSERT INTO ' . $this->postCategoriesTable() . ' (post_id, category_id) VALUES (:post_id, :category_id)',
                ['post_id' => $postId, 'category_id' => $categoryId],
            );

            $added++;
        }

        return $added;
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
            trashedAt: isset($row['trashed_at']) ? new DateTimeImmutable((string) $row['trashed_at']) : null,
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
