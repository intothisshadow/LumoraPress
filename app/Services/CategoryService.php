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
 * Category CRUD, slug generation, hierarchy, and the post relationship via
 * {prefix}post_categories. Categories have no author/ownership concept — any user with
 * edit_posts can edit any category, and delete_posts is required to delete one.
 *
 * Every query uses a distinct placeholder name per occurrence, even for a repeated value:
 * MySQL's native prepare protocol (emulated prepares are disabled) throws
 * "SQLSTATE[HY093]" if a named placeholder repeats within one query.
 */
final class CategoryService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function create(string $name, string $description, ?int $parentId = null, ?string $slug = null, ?int $imageId = null, ?string $archiveDisplayMode = null): Category
    {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $name);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (name, slug, description, parent_id, menu_order, image_id, archive_display_mode, created_at, updated_at)
             VALUES (:name, :slug, :description, :parent_id, :menu_order, :image_id, :archive_display_mode, :created_at, :updated_at)',
            [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'parent_id' => $parentId,
                'menu_order' => $this->nextMenuOrder($parentId),
                'image_id' => $imageId,
                'archive_display_mode' => $archiveDisplayMode,
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

    /**
     * $imageId always replaces the stored image outright (null clears it) — unlike $parentId,
     * there is no "keep whatever was there" sentinel, so callers resolve the final value
     * (upload wins over an existing-media pick, which wins over "remove", which wins over
     * keeping the current image) before calling update(), the same resolution order
     * PostsController::save() already uses for featured images.
     */
    public function update(int $id, string $name, string $description, ?int $parentId = null, ?string $slug = null, ?int $imageId = null, ?string $archiveDisplayMode = null): Category
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
                    parent_id = :parent_id, image_id = :image_id, archive_display_mode = :archive_display_mode,
                    updated_at = :updated_at
              WHERE id = :id',
            [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'parent_id' => $parentId,
                'image_id' => $imageId,
                'archive_display_mode' => $archiveDisplayMode,
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
     * Soft-deletes a category — mirrors PostService/PageService's trashed_at pattern.
     * A trashed category is excluded everywhere it would normally show up publicly or be
     * offered for assignment, but stays reachable via findById() for the admin Trash tab.
     * There is no automatic purge; delete() is the only way to actually remove one.
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
     * Merges $sourceId into $targetId: every post gains $targetId (via bulkAddToPosts(), so
     * no composite-key collision), $sourceId's rows are dropped, its children are
     * reparented to $targetId, and $sourceId is deleted. If $targetId was itself a child of
     * $sourceId, it's orphaned rather than reparented to itself, since a category can never
     * be its own parent. Returns false without changing anything if $sourceId === $targetId
     * or either doesn't exist.
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
     * Excludes trashed categories, matching findBySlug()'s exclusion —
     * backs findByPath()'s segment-by-segment hierarchical URL resolution.
     */
    public function findBySlugAndParent(string $slug, ?int $parentId): ?Category
    {
        $row = $parentId === null
            ? $this->database->fetchOne(
                'SELECT * FROM ' . $this->table() . ' WHERE slug = :slug AND parent_id IS NULL AND trashed_at IS NULL',
                ['slug' => $slug],
            )
            : $this->database->fetchOne(
                'SELECT * FROM ' . $this->table() . ' WHERE slug = :slug AND parent_id = :parent_id AND trashed_at IS NULL',
                ['slug' => $slug, 'parent_id' => $parentId],
            );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Resolves a hierarchical URL's segments (root-first) to the leaf category, requiring each
     * segment to match the previous segment's actual child — a URL with a missing or wrong
     * ancestor prefix returns null rather than resolving by its final slug alone, mirroring
     * PageService::findByPath(). Category slugs are globally unique regardless of nesting, so
     * SiteController::category() still offers a legacy-redirect fallback via findBySlug() for
     * old bookmarked/indexed flat links to a category that has since gained a parent.
     *
     * @param array<int, string> $segments
     */
    public function findByPath(array $segments): ?Category
    {
        $parentId = null;
        $category = null;

        foreach ($segments as $segment) {
            $category = $this->findBySlugAndParent($segment, $parentId);

            if ($category === null) {
                return null;
            }

            $parentId = $category->id;
        }

        return $category;
    }

    /**
     * Root-first ancestor chain, empty for a top-level category — mirrors
     * PageService::ancestors() exactly, including its cycle-safety cap.
     *
     * @return array<int, Category>
     */
    public function ancestors(int $categoryId): array
    {
        $chain = [];
        $current = $this->findById($categoryId);
        $hops = 0;

        while ($current !== null && $current->parentId !== null && $hops < 50) {
            $parent = $this->findById($current->parentId);

            if ($parent === null) {
                break;
            }

            $chain[] = $parent;
            $current = $parent;
            $hops++;
        }

        return array_reverse($chain);
    }

    /**
     * Case-insensitive lookup, falling back to create() — mirrors
     * TagService::findOrCreateByName(), the same "don't let 'Sci-Fi' and
     * 'sci-fi' become two different terms" guard. Backs the post editor's
     * "Create categories while editing" affordance: typing a name that
     * already exists reuses that category instead of creating a
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
     * COUNT() per category. $filters backs the admin list's Search &
     * Filter panel; an empty array behaves identically to the old
     * no-argument form.
     *
     * @param array{term?: string, parentId?: int, minPosts?: int, dateFrom?: string, dateTo?: string} $filters
     *     term: matches name or slug (LIKE). parentId: exact match on parent_id.
     *     minPosts: category must have at least this many assigned posts.
     *     dateFrom/dateTo: created_at date range, inclusive, as Y-m-d strings.
     * @return array<int, array{category: Category, postCount: int}>
     */
    public function listAllWithPostCounts(array $filters = []): array
    {
        $sql = 'SELECT c.*, COUNT(pc.post_id) AS post_count
                  FROM ' . $this->table() . ' c
                  LEFT JOIN ' . $this->postCategoriesTable() . ' pc ON pc.category_id = c.id
                 WHERE c.trashed_at IS NULL';
        $params = [];

        $term = trim((string) ($filters['term'] ?? ''));

        if ($term !== '') {
            $sql .= ' AND (c.name LIKE :term_name OR c.slug LIKE :term_slug)';
            $params['term_name'] = '%' . $term . '%';
            $params['term_slug'] = '%' . $term . '%';
        }

        $parentId = (int) ($filters['parentId'] ?? 0);

        if ($parentId > 0) {
            $sql .= ' AND c.parent_id = :parent_id';
            $params['parent_id'] = $parentId;
        }

        $dateFrom = (string) ($filters['dateFrom'] ?? '');

        if ($dateFrom !== '') {
            $sql .= ' AND c.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = (string) ($filters['dateTo'] ?? '');

        if ($dateTo !== '') {
            $sql .= ' AND c.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $sql .= ' GROUP BY c.id';

        $minPosts = (int) ($filters['minPosts'] ?? 0);

        // A bound parameter here compares as SQLite's TEXT storage class against post_count's
        // INTEGER class (neither side has column affinity to trigger numeric coercion), which
        // always evaluates false — interpolated directly since $minPosts is already int-cast.
        if ($minPosts > 0) {
            $sql .= ' HAVING post_count >= ' . $minPosts;
        }

        $sql .= ' ORDER BY c.name ASC';

        $rows = $this->database->fetchAll($sql, $params);

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
     * Permanently deletes every currently-trashed category via delete(), so
     * each one gets the same child-orphaning/post_categories cleanup a
     * single Delete Permanently would — not a bare bulk DELETE.
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
     * Non-trashed category count — the admin list's "All (N)" status
     * link, matching Posts'/Pages' own status-count tabs. A single
     * COUNT() rather than count(listAll()), since the admin view needs
     * this total even while viewing the Trash tab, where listAll()'s
     * full row set (and its unrelated ORDER BY name) would otherwise be
     * fetched for nothing.
     */
    public function count(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE trashed_at IS NULL',
        );
    }

    /**
     * A flat list of {id, name} suitable for a "Parent Category" select,
     * excluding $excludeId itself and its direct children (so a category
     * can't be made the parent of its own parent one level up — deeper
     * cycles are an accepted gap for this basic, non-tree parent selector).
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
     * A depth-tagged {id, name, depth} list for admin "Parent Category" pickers — combines
     * listAllForTree()'s hierarchical order with listAllForParentSelect()'s cycle-prevention
     * exclusion. Pass no $excludeId for a plain list with nothing excluded.
     *
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
     * Non-trashed categories as a flat, depth-tagged list in hierarchical
     * document order (a parent immediately followed by its own children,
     * siblings in their manually-set menu_order, then the next sibling) —
     * backs the admin Categories list's own tree view as well as the
     * admin Menus screen's "Add Categories" panel, mirroring
     * PageService::listAllForTree()'s shape so a subcategory renders
     * indented under its parent there instead of in the same flat,
     * alphabetized list listAll() produces. Sibling order here reflects
     * CategoryService::reorder()'s manual drag-and-drop ordering, not
     * listAll()'s alphabetical order — every other caller of this method
     * (Menus, the Post editor's category tree, the Categories widget)
     * inherits that same manual order, matching how Pages' own
     * hand-ordered menu_order already flows through everywhere Pages are
     * listed hierarchically.
     *
     * @return array<int, array{category: Category, depth: int}>
     */
    public function listAllForTree(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE trashed_at IS NULL ORDER BY parent_id, menu_order, id',
        );

        return $this->flattenForTree(array_map($this->hydrate(...), $rows), null, 0);
    }

    /**
     * @param array<int, Category> $categories
     * @return array<int, array{category: Category, depth: int}>
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
     * Adds $categoryId to each post's existing assignments without touching others already
     * assigned — unlike assignToPost(), which replaces the full set. Uses check-then-insert
     * rather than `ON DUPLICATE KEY` to stay portable to the SQLite-backed unit tests.
     *
     * @param array<int, int> $postIds
     * @return int how many posts actually gained the assignment
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

    /**
     * Moves $draggedId to a position immediately before/after $targetId
     * among their shared siblings (admin Categories tree view's
     * drag-and-drop) — mirrors PageService::reorder() exactly. $targetId
     * must share $draggedId's parentId; a request that fails this check
     * is a silent no-op. This method never changes parent_id.
     */
    public function reorder(int $draggedId, int $targetId, string $position): bool
    {
        $dragged = $this->findById($draggedId);

        if ($dragged === null) {
            return false;
        }

        $siblings = $this->database->fetchAll(
            $dragged->parentId === null
                ? 'SELECT id FROM ' . $this->table() . ' WHERE parent_id IS NULL ORDER BY menu_order, id'
                : 'SELECT id FROM ' . $this->table() . ' WHERE parent_id = :parent_id ORDER BY menu_order, id',
            $dragged->parentId === null ? [] : ['parent_id' => $dragged->parentId],
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $siblings);
        $remaining = array_values(array_filter($ids, static fn (int $id): bool => $id !== $draggedId));

        $targetIndex = array_search($targetId, $remaining, true);

        if ($targetIndex === false) {
            return false;
        }

        $insertAt = $position === 'after' ? $targetIndex + 1 : $targetIndex;
        array_splice($remaining, $insertAt, 0, [$draggedId]);

        $this->database->transaction(function () use ($remaining): void {
            foreach ($remaining as $order => $id) {
                $this->database->execute(
                    'UPDATE ' . $this->table() . ' SET menu_order = :menu_order WHERE id = :id',
                    ['menu_order' => $order, 'id' => $id],
                );
            }
        });

        return true;
    }

    /**
     * The next menu_order value for a new sibling under $parentId, so a
     * new category lands at the end of its sibling group — mirrors
     * PageService's own private helper of the same name.
     */
    private function nextMenuOrder(?int $parentId): int
    {
        $max = $parentId === null
            ? $this->database->fetchColumn('SELECT MAX(menu_order) FROM ' . $this->table() . ' WHERE parent_id IS NULL')
            : $this->database->fetchColumn('SELECT MAX(menu_order) FROM ' . $this->table() . ' WHERE parent_id = :parent_id', ['parent_id' => $parentId]);

        return $max === null ? 0 : ((int) $max) + 1;
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
            imageId: isset($row['image_id']) && $row['image_id'] !== null ? (int) $row['image_id'] : null,
            menuOrder: isset($row['menu_order']) ? (int) $row['menu_order'] : 0,
            archiveDisplayMode: isset($row['archive_display_mode']) && $row['archive_display_mode'] !== null ? (string) $row['archive_display_mode'] : null,
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
