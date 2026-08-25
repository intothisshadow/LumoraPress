<?php

/**
 * Page CRUD, slug generation, and the queries behind the front-end page route and admin list.
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
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PageVisibility;
use RuntimeException;

/**
 * Page CRUD, slug generation, and the queries behind the frontend page
 * route and admin list view. Mirrors PostService closely — same
 * scheduled-visibility mechanism, same slug generation, same nullable
 * featured_image_id (LP-040) — plus a nullable parent_id for a basic, flat
 * parent/child relationship (no tree view, no drag-and-drop ordering, no
 * hierarchical URLs yet). $hooks is optional (LP-037) — see
 * PostService's docblock for why.
 */
final class PageService
{
    private const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * @param array{x: int, y: int, width: int, height: int}|null $featuredImageCrop
     */
    public function create(
        string $title,
        string $content,
        string $excerpt,
        int $authorId,
        PageStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?int $parentId = null,
        ?int $featuredImageId = null,
        ?string $slug = null,
        ContentFormat $contentFormat = ContentFormat::Markdown,
        ?array $featuredImageCrop = null,
        PageVisibility $visibility = PageVisibility::Public,
        bool $commentsOpen = true,
    ): Page {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title);
        $now = new DateTimeImmutable();
        $menuOrder = $this->nextMenuOrder($parentId);

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, slug, content, content_format, excerpt, status, visibility, comment_status, author_id, parent_id, menu_order, featured_image_id, featured_image_crop, published_at, created_at, updated_at)
             VALUES (:title, :slug, :content, :content_format, :excerpt, :status, :visibility, :comment_status, :author_id, :parent_id, :menu_order, :featured_image_id, :featured_image_crop, :published_at, :created_at, :updated_at)',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => $contentFormat->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'visibility' => $visibility->value,
                'comment_status' => $commentsOpen ? 'open' : 'closed',
                'author_id' => $authorId,
                'parent_id' => $parentId,
                'menu_order' => $menuOrder,
                'featured_image_id' => $featuredImageId,
                'featured_image_crop' => $featuredImageId !== null && $featuredImageCrop !== null ? json_encode($featuredImageCrop) : null,
                'published_at' => $this->resolvePublishedAt($status, $publishedAt, $now)?->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        // A brand-new page can't be its own parent — resolvePublishedAt
        // above doesn't need the id, but the parent guard does, so it's
        // applied after insert if the caller somehow passed the not-yet-
        // known id (defensive; unreachable via the admin UI today, which
        // excludes the page being edited from its own parent dropdown).
        if ($parentId !== null && $parentId === (int) $id) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE id = :id',
                ['id' => (int) $id],
            );
        }

        $page = $this->findById((int) $id);

        if ($page === null) {
            throw new RuntimeException('Failed to load the page that was just created.');
        }

        $this->hooks?->doAction('page_saved', $page);

        return $page;
    }

    /**
     * @param array{x: int, y: int, width: int, height: int}|null $featuredImageCrop
     */
    public function update(
        int $id,
        string $title,
        string $content,
        string $excerpt,
        PageStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?int $parentId = null,
        ?int $featuredImageId = null,
        ?string $slug = null,
        ?ContentFormat $contentFormat = null,
        ?array $featuredImageCrop = null,
        ?PageVisibility $visibility = null,
        bool $commentsOpen = true,
    ): Page {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Page {$id} does not exist.");
        }

        // A page can never be its own parent.
        if ($parentId === $id) {
            $parentId = null;
        }

        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title, ignoreId: $id);
        $now = new DateTimeImmutable();

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, slug = :slug, content = :content, content_format = :content_format, excerpt = :excerpt,
                    status = :status, visibility = :visibility, comment_status = :comment_status, parent_id = :parent_id, featured_image_id = :featured_image_id, featured_image_crop = :featured_image_crop,
                    published_at = :published_at, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => ($contentFormat ?? $existing->contentFormat)->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'visibility' => ($visibility ?? $existing->visibility)->value,
                'comment_status' => $commentsOpen ? 'open' : 'closed',
                'parent_id' => $parentId,
                'featured_image_id' => $featuredImageId,
                'featured_image_crop' => $featuredImageId !== null && $featuredImageCrop !== null ? json_encode($featuredImageCrop) : null,
                'published_at' => $this->resolvePublishedAt($status, $publishedAt, $now, $existing->publishedAt)?->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $page = $this->findById($id);

        if ($page === null) {
            throw new RuntimeException('Failed to load the page that was just updated.');
        }

        $this->hooks?->doAction('page_saved', $page);

        return $page;
    }

    /**
     * LP-022 SEO title/description overrides — mirrors PostService::
     * updateSeo(), see its docblock for why this is a dedicated method.
     */
    public function updateSeo(int $id, ?string $metaTitle, ?string $metaDescription): void
    {
        $metaTitle = $metaTitle !== null && trim($metaTitle) !== '' ? $metaTitle : null;
        $metaDescription = $metaDescription !== null && trim($metaDescription) !== '' ? $metaDescription : null;

        $updated = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET meta_title = :meta_title, meta_description = :meta_description WHERE id = :id',
            ['meta_title' => $metaTitle, 'meta_description' => $metaDescription, 'id' => $id],
        ) > 0;

        if ($updated) {
            $page = $this->findById($id);

            if ($page !== null) {
                $this->hooks?->doAction('page_saved', $page);
            }
        }
    }

    /**
     * Mirrors PostService::duplicate() — see its docblock. The duplicate
     * is never parented under the original (parent_id is not copied): an
     * unattached draft is safer than silently doubling a subtree, and the
     * admin can reassign a parent from the tree view afterward if wanted.
     */
    public function duplicate(int $id, int $authorId): ?Page
    {
        $original = $this->findById($id);

        if ($original === null) {
            return null;
        }

        $duplicate = $this->create(
            title: $original->title . ' (Copy)',
            content: $original->content,
            excerpt: $original->excerpt,
            authorId: $authorId,
            status: PageStatus::Draft,
            featuredImageId: $original->featuredImageId,
            contentFormat: $original->contentFormat,
            featuredImageCrop: $original->featuredImageCrop,
        );

        $this->updateSeo($duplicate->id, $original->metaTitle, $original->metaDescription);

        return $this->findById($duplicate->id);
    }

    /**
     * Backs the admin list's Quick Edit row — updates only the handful
     * of fields that inline form exposes (title, slug, status, parent),
     * routing through the same `update()` used by the full editor with
     * every other field (including the existing published/scheduled
     * date) passed through unchanged, rather than a separate
     * partial-UPDATE query. The admin view only ever offers Draft or
     * Published as Quick Edit options — Quick Edit's inline form has no
     * publish-date field, so scheduling a page remains a
     * full-editor-only action — but this method itself works correctly
     * for any status, since it always preserves whatever `publishedAt`
     * the page already had.
     */
    public function quickUpdate(int $id, string $title, PageStatus $status, ?int $parentId, ?string $slug = null): ?Page
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            return null;
        }

        return $this->update(
            $id,
            $title,
            $existing->content,
            $existing->excerpt,
            $status,
            $existing->publishedAt,
            $parentId,
            $existing->featuredImageId,
            $slug,
            $existing->contentFormat,
            featuredImageCrop: $existing->featuredImageCrop,
            visibility: $existing->visibility,
            commentsOpen: $existing->commentsOpen,
        );
    }

    /**
     * Soft-deletes a page (LP-009 Trash) — mirrors PostService::trash()
     * exactly (status flips to Trashed, trashed_at records when, the row
     * stays). Trashed pages are excluded from paginateForAdmin()'s
     * default "All" view and listAllForTree() the same way trashed posts
     * are hidden from every other admin list view. There is no automatic
     * purge — delete() (via the admin UI's "Delete Permanently" action,
     * only offered for already-trashed pages) is the only way to
     * actually remove one.
     */
    public function trash(int $id): bool
    {
        $trashed = $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'trashed', trashed_at = :trashed_at WHERE id = :id",
            ['trashed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;

        if ($trashed) {
            $this->hooks?->doAction('page_deleted', $id);
        }

        return $trashed;
    }

    /**
     * Restores a trashed page — always back to Draft, never straight
     * back to its previous status, matching PostService::restore()'s
     * documented fail-securely rationale (silently resurfacing a trashed
     * page as publicly visible again without a human deciding to
     * republish it would be surprising).
     */
    public function restore(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'draft', trashed_at = NULL WHERE id = :id",
            ['id' => $id],
        ) > 0;
    }

    /**
     * Directly changes a page's status without touching any other field
     * — backs the admin list's per-row and bulk "Mark as Draft"/
     * "Publish" actions, mirroring PostService::setStatus(). Scheduled
     * is deliberately not reachable through this method since scheduling
     * also needs a publish date; use update() for that.
     */
    public function setStatus(int $id, PageStatus $status): bool
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        $publishedAt = $this->resolvePublishedAt($status, null, $now, $existing->publishedAt);

        $changed = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET status = :status, published_at = :published_at, updated_at = :updated_at WHERE id = :id',
            [
                'status' => $status->value,
                'published_at' => $publishedAt?->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;

        if ($changed) {
            $page = $this->findById($id);

            if ($page !== null) {
                $this->hooks?->doAction('page_saved', $page);
            }
        }

        return $changed;
    }

    /**
     * Directly changes a page's visibility without touching any other
     * field — mirrors PostService::setVisibility() exactly.
     */
    public function setVisibility(int $id, PageVisibility $visibility): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET visibility = :visibility WHERE id = :id',
            ['visibility' => $visibility->value, 'id' => $id],
        ) > 0;
    }

    /**
     * Bulk form of setVisibility() — mirrors PostService::
     * bulkSetVisibility() exactly.
     *
     * @param array<int, int> $ids
     * @return int how many pages were updated
     */
    public function bulkSetVisibility(array $ids, PageVisibility $visibility): int
    {
        $updated = 0;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($this->setVisibility($id, $visibility)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Directly changes a page's parent without touching any other field
     * — backs the admin list's bulk "Change parent to..." action. A page
     * can never become its own parent (mirrors create()/update()'s same
     * guard); the moved page is placed at the end of its new sibling
     * group via nextMenuOrder(), the same position a brand-new page
     * under that parent would get.
     */
    public function setParent(int $id, ?int $parentId): bool
    {
        if ($parentId === $id) {
            $parentId = null;
        }

        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET parent_id = :parent_id, menu_order = :menu_order WHERE id = :id',
            ['parent_id' => $parentId, 'menu_order' => $this->nextMenuOrder($parentId), 'id' => $id],
        ) > 0;
    }

    /**
     * Mirrors PostService::reassignAuthor() — backs the admin list's
     * bulk "Change author to&hellip;" action.
     */
    public function reassignAuthor(int $id, int $authorId): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET author_id = :author_id WHERE id = :id',
            ['author_id' => $authorId, 'id' => $id],
        ) > 0;
    }

    /**
     * Bulk form of reassignAuthor() — mirrors PostService::
     * bulkReassignAuthor() exactly.
     *
     * @param array<int, int> $ids
     * @return int how many pages were reassigned
     */
    public function bulkReassignAuthor(array $ids, int $authorId): int
    {
        $reassigned = 0;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($this->reassignAuthor($id, $authorId)) {
                $reassigned++;
            }
        }

        return $reassigned;
    }

    /**
     * Permanently removes a page. Unlike PostService::delete(), there's
     * no self-referencing hierarchy on posts to worry about, but pages
     * have parent_id with no FK constraint (see the base migration) — a
     * deleted page's children would otherwise be left pointing at a
     * parent_id that no longer exists. Direct children are reparented to
     * top-level (parent_id = NULL) first, the same "orphan on delete"
     * treatment WordPress itself uses for pages with children.
     */
    public function delete(int $id): bool
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE parent_id = :parent_id',
            ['parent_id' => $id],
        );

        $deleted = $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;

        if ($deleted) {
            $this->hooks?->doAction('page_deleted', $id);
        }

        return $deleted;
    }

    public function findById(int $id): ?Page
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Used by the admin Users screen to block deleting a user who has
     * authored pages, since there is no admin reassignment UI yet.
     */
    public function countByAuthor(int $authorId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE author_id = :author_id',
            ['author_id' => $authorId],
        );
    }

    /**
     * Mirrors PostService::countByStatus() (which backs the admin post
     * list's status filter tab counts) — used here by the Statistics
     * widget's published-page count (LP-048).
     */
    public function countByStatus(PageStatus $status): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE status = :status',
            ['status' => $status->value],
        );
    }

    public function findBySlug(string $slug): ?Page
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Titles of every page using $mediaId as its featured image — used by
     * MediaUsageChecker to warn before deleting a referenced file, mirrors
     * PostService::titlesByFeaturedImage().
     *
     * @return array<int, string>
     */
    public function titlesByFeaturedImage(int $mediaId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT title FROM ' . $this->table() . ' WHERE featured_image_id = :featured_image_id',
            ['featured_image_id' => $mediaId],
        );

        return array_map(static fn (array $row): string => (string) $row['title'], $rows);
    }

    /**
     * Mirrors PostService::featuredImageIdsInUse() — see its docblock.
     *
     * @return array<int, int>
     */
    public function featuredImageIdsInUse(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT DISTINCT featured_image_id FROM ' . $this->table() . ' WHERE featured_image_id IS NOT NULL',
        );

        return array_map(static fn (array $row): int => (int) $row['featured_image_id'], $rows);
    }

    /**
     * Pages visible to public site visitors: published outright (or
     * scheduled with a published_at time that has already passed), and
     * not Private (LP-009's "Private pages" — mirrors
     * PostService::publicWhereClause()'s identical visibility AND).
     *
     * @return array{pages: array<int, Page>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginatePublished(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = "(status = 'published' OR (status = 'scheduled' AND published_at <= :now)) AND visibility = 'public'";

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$where}",
            ['now' => $now],
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE {$where}"
                . " ORDER BY title ASC LIMIT {$perPage} OFFSET {$offset}",
            ['now' => $now],
        );

        return [
            'pages' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Every page for the admin page list, excluding Trash from the
     * default "All" view — mirrors PostService::paginateForAdmin()'s
     * identical trash-exclusion default; pass PageStatus::Trashed
     * explicitly to view the Trash tab itself.
     *
     * @return array{pages: array<int, Page>, total: int, page: int, perPage: int, totalPages: int}
     */
    /**
     * @param array{term?: string, authorId?: int, parentId?: int, dateFrom?: string, dateTo?: string} $filters
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?PageStatus $statusFilter = null, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $conditions = [];
        $params = [];

        if ($statusFilter !== null) {
            $conditions[] = 'status = :status';
            $params['status'] = $statusFilter->value;
        } else {
            $conditions[] = "status != 'trashed'";
        }

        // A single search box matching either the title or the content —
        // classic WordPress's Pages list does the same rather than
        // offering separate title/content search fields. Two distinct
        // placeholders bound to the same value, not :term reused twice —
        // real (non-emulated) MySQL prepared statements reject a named
        // placeholder used more than once in one query (see
        // PHP-TEST-SUITE.md's "Known gaps" for the bug this already
        // caused once in listAllForParentSelect()).
        if (($filters['term'] ?? '') !== '') {
            $conditions[] = '(title LIKE :term_title OR content LIKE :term_content)';
            $params['term_title'] = '%' . $filters['term'] . '%';
            $params['term_content'] = '%' . $filters['term'] . '%';
        }

        if (($filters['authorId'] ?? 0) > 0) {
            $conditions[] = 'author_id = :author_id';
            $params['author_id'] = (int) $filters['authorId'];
        }

        if (($filters['parentId'] ?? 0) > 0) {
            $conditions[] = 'parent_id = :parent_id';
            $params['parent_id'] = (int) $filters['parentId'];
        }

        // Filters/sorts by the page's own date (published_at, falling
        // back to created_at for a Draft or other status with no
        // publish date yet) rather than created_at alone — see
        // PostService::paginateForAdmin()'s identical note.
        if (($filters['dateFrom'] ?? '') !== '') {
            $conditions[] = 'COALESCE(published_at, created_at) >= :date_from';
            $params['date_from'] = $filters['dateFrom'] . ' 00:00:00';
        }

        if (($filters['dateTo'] ?? '') !== '') {
            $conditions[] = 'COALESCE(published_at, created_at) <= :date_to';
            $params['date_to'] = $filters['dateTo'] . ' 23:59:59';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " {$where}"
                . " ORDER BY COALESCE(published_at, created_at) DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'pages' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * A flat list of {id, title} suitable for a "Parent Page" <select>,
     * excluding $excludeId itself and its direct children (so a page can't
     * be made the parent of its own parent one level up — deeper cycles
     * are an accepted gap for this basic, non-tree parent selector).
     * Trashed pages are excluded (LP-109) — unlike
     * PostService::listAllForMenuSelect()'s intentionally-inclusive
     * non-trashed statuses (see that method's own docblock), nothing
     * here suggested trashed pages belonged in a parent/front-page
     * picker; this brings the query in line with
     * CategoryService::listAllForParentSelect()'s existing `WHERE
     * trashed_at IS NULL`.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function listAllForParentSelect(?int $excludeId = null): array
    {
        if ($excludeId === null) {
            $rows = $this->database->fetchAll("SELECT id, title FROM " . $this->table() . " WHERE status != 'trashed' ORDER BY title ASC");
        } else {
            // Two distinct placeholders for the same value: with real
            // (non-emulated) prepared statements — see Database::connect()'s
            // PDO::ATTR_EMULATE_PREPARES => false — MySQL's native prepare
            // protocol treats each occurrence of a named placeholder as its
            // own parameter, so reusing :id twice with a single bound value
            // throws "SQLSTATE[HY093]: Invalid parameter number" at runtime.
            $rows = $this->database->fetchAll(
                "SELECT id, title FROM " . $this->table() . "
                    WHERE status != 'trashed' AND id != :exclude_id AND (parent_id IS NULL OR parent_id != :exclude_id_2)
                 ORDER BY title ASC",
                ['exclude_id' => $excludeId, 'exclude_id_2' => $excludeId],
            );
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']],
            $rows,
        );
    }

    /**
     * A depth-tagged {id, title, depth} list for the New/Edit Page
     * "Parent Page" picker (LP-105) — combines listAllForTree()'s
     * hierarchical document order with listAllForParentSelect()'s own
     * cycle-prevention exclusion ($excludeId itself and its direct
     * children; deeper cycles are the same accepted gap that method
     * already documents), so the picker can render indented like the
     * "All Pages" tree view while still ruling out choices that would
     * make a page its own ancestor.
     *
     * @return array<int, array{id: int, title: string, depth: int}>
     */
    public function listAllForParentPicker(?int $excludeId = null): array
    {
        $flattened = array_map(
            static fn (array $row): array => ['id' => $row['page']->id, 'title' => $row['page']->title, 'parentId' => $row['page']->parentId, 'depth' => $row['depth']],
            $this->listAllForTree(),
        );

        if ($excludeId === null) {
            return array_map(
                static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'depth' => $row['depth']],
                $flattened,
            );
        }

        return array_values(array_map(
            static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'depth' => $row['depth']],
            array_filter(
                $flattened,
                static fn (array $row): bool => $row['id'] !== $excludeId && $row['parentId'] !== $excludeId,
            ),
        ));
    }

    /**
     * A depth-tagged {id, title, slug, depth} list, in the same
     * hierarchical document order as listAllForTree(), for the admin
     * Menus screen's "Add Pages" checkbox list (LP-049) — separate from
     * listAllForParentSelect() above since that method's shape/exclusion
     * rules are specific to the parent-page picker, not menu building.
     * Built on listAllForTree() rather than a flat alphabetical query
     * (LP-103) so a child page renders indented under its parent in the
     * Add Items panel, matching the "All Pages" tree view; trashed pages
     * are excluded as a side effect of that reuse, which they always
     * should have been.
     *
     * @return array<int, array{id: int, title: string, slug: string, depth: int}>
     */
    public function listAllForMenuSelect(): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => $row['page']->id,
                'title' => $row['page']->title,
                'slug' => $row['page']->slug,
                'depth' => $row['depth'],
            ],
            $this->listAllForTree(),
        );
    }

    /**
     * Every non-trashed page as a flat, depth-tagged list in hierarchical
     * document order (a parent immediately followed by its own children
     * in menu_order, then the next sibling) — backs the admin "All" tab's
     * tree view (LP-009 Hierarchy UI). One unpaginated query is
     * acceptable here: pages are evergreen/structural content (About,
     * Contact, FAQ, ...), not the tens-of-thousands-of-rows table Posts
     * can be.
     *
     * @return array<int, array{page: Page, depth: int}>
     */
    public function listAllForTree(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE status != 'trashed' ORDER BY parent_id, menu_order, id",
        );
        $pages = array_map($this->hydrate(...), $rows);

        return $this->flattenForTree($pages, null, 0);
    }

    /**
     * The same shape as listAllForTree(), but restricted to what a Guest
     * visitor may actually see — mirrors paginatePublished()'s exact
     * visibility rule (published, or scheduled with a past publish date,
     * AND public visibility) rather than listAllForTree()'s "everything
     * not trashed" — backs the Pages widget's nested output (LP-104),
     * which must never leak a draft, scheduled-future, or private page
     * into public-facing markup just because it happens to be some
     * visible page's child.
     *
     * A page whose real parent isn't itself in this filtered, public set
     * (e.g. its parent is a draft) is dropped entirely rather than
     * promoted to top level or attached at the wrong depth — the same
     * "only ever nest under a genuinely present parent" behavior
     * flattenForTree() already has for listAllForTree()/
     * listAllForMenuSelect().
     *
     * Ordered by title rather than listAllForTree()'s manual menu_order —
     * the widget's own top level (and each depth's siblings) render
     * alphabetically, matching the flat alphabetical order the widget
     * always used before LP-104, and CategoryService::listAllForTree()'s
     * identical alphabetical-siblings behavior. flattenForTree() preserves
     * this query's relative ordering when it filters by parentId at each
     * recursion, so a single global `ORDER BY title` is enough to make
     * every depth's siblings alphabetical, not just the top level.
     *
     * @return array<int, array{page: Page, depth: int}>
     */
    public function publicTreeForWidget(): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . "
                WHERE (status = 'published' OR (status = 'scheduled' AND published_at <= :now))
                  AND visibility = 'public'
             ORDER BY title ASC",
            ['now' => $now],
        );
        $pages = array_map($this->hydrate(...), $rows);

        return $this->flattenForTree($pages, null, 0);
    }

    /**
     * @param array<int, Page> $pages
     * @return array<int, array{page: Page, depth: int}>
     */
    private function flattenForTree(array $pages, ?int $parentId, int $depth): array
    {
        $result = [];

        foreach ($pages as $page) {
            if ($page->parentId !== $parentId) {
                continue;
            }

            $result[] = ['page' => $page, 'depth' => $depth];
            $result = [...$result, ...$this->flattenForTree($pages, $page->id, $depth + 1)];
        }

        return $result;
    }

    /**
     * Moves $draggedId to a position immediately before/after $targetId
     * among their shared siblings (LP-009 Hierarchy UI drag-and-drop) —
     * same "splice out, splice back in" algorithm as menus.php's
     * reposition_item handler, rewritten against real SQL rows instead of
     * an in-memory JSON-array splice, since pages (unlike menus) are
     * individual DB rows with no whole-tree blob to rewrite. $targetId
     * must share $draggedId's parentId — sortable.js's client-side
     * same-parent guard is the primary defense; a request that fails
     * this check (a stale/tampered target id, or a different-parent
     * target) is a silent no-op, matching that existing precedent.
     * Re-parenting stays the "Parent Page" dropdown's/bulk "Change
     * parent" job — this method never changes parent_id.
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
     * The next menu_order value for a new sibling under $parentId (max
     * existing sibling + 1, or 0 if there are none yet) — so a newly
     * created page lands at the end of its sibling group instead of
     * colliding with an existing page at 0.
     */
    private function nextMenuOrder(?int $parentId): int
    {
        $max = $parentId === null
            ? $this->database->fetchColumn('SELECT MAX(menu_order) FROM ' . $this->table() . ' WHERE parent_id IS NULL')
            : $this->database->fetchColumn('SELECT MAX(menu_order) FROM ' . $this->table() . ' WHERE parent_id = :parent_id', ['parent_id' => $parentId]);

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * Walks the parent_id chain from $pageId up to the root, root-first
     * — backs the public "breadcrumbs" theme API
     * (get_page_breadcrumbs()/the_page_breadcrumbs() in
     * include/content-display-functions.php). Returns an empty array for
     * a top-level page. Capped at 50 hops as a defensive guard against a
     * pathological cycle reaching this method some other way — normal
     * create()/update() already block direct self-parenting and one-level
     * cycles, so this is belt-and-suspenders, not a new invariant.
     *
     * @return array<int, Page>
     */
    public function ancestors(int $pageId): array
    {
        $chain = [];
        $current = $this->findById($pageId);
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

    private function resolvePublishedAt(
        PageStatus $status,
        ?DateTimeImmutable $publishedAt,
        DateTimeImmutable $now,
        ?DateTimeImmutable $existingPublishedAt = null,
    ): ?DateTimeImmutable {
        return match ($status) {
            // Trashed is never actually reached through this path — trash()
            // sets status/trashed_at directly via its own SQL, not through
            // create()/update()/setStatus() — this arm exists purely so the
            // match stays exhaustive over PageStatus, mirroring
            // PostService::resolvePublishedAt()'s identical defensive arm.
            // PendingReview has no publish date either, same as Posts.
            PageStatus::Draft, PageStatus::PendingReview, PageStatus::Trashed => null,
            PageStatus::Published => $publishedAt ?? $existingPublishedAt ?? $now,
            PageStatus::Scheduled => $publishedAt ?? $existingPublishedAt,
        };
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

    private function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'page' : $slug;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Page
    {
        return new Page(
            id: (int) $row['id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            content: (string) $row['content'],
            excerpt: (string) ($row['excerpt'] ?? ''),
            status: PageStatus::from((string) $row['status']),
            authorId: (int) $row['author_id'],
            parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            featuredImageId: $row['featured_image_id'] !== null ? (int) $row['featured_image_id'] : null,
            publishedAt: $row['published_at'] !== null ? new DateTimeImmutable((string) $row['published_at']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            contentFormat: ContentFormat::tryFrom((string) ($row['content_format'] ?? '')) ?? ContentFormat::Plain,
            featuredImageCrop: self::decodeCrop($row['featured_image_crop'] ?? null),
            metaTitle: isset($row['meta_title']) && $row['meta_title'] !== '' ? (string) $row['meta_title'] : null,
            metaDescription: isset($row['meta_description']) && $row['meta_description'] !== '' ? (string) $row['meta_description'] : null,
            trashedAt: isset($row['trashed_at']) ? new DateTimeImmutable((string) $row['trashed_at']) : null,
            menuOrder: isset($row['menu_order']) ? (int) $row['menu_order'] : 0,
            visibility: PageVisibility::tryFrom((string) ($row['visibility'] ?? '')) ?? PageVisibility::Public,
            commentsOpen: ($row['comment_status'] ?? 'open') === 'open',
        );
    }

    /**
     * Decodes the `featured_image_crop` column — see
     * PostService::decodeCrop()'s identical docblock; duplicated rather
     * than shared since these two services don't otherwise share a base
     * class and this is a handful of lines, the same trade-off
     * slugify()/generateUniqueSlug() already make in both services.
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private static function decodeCrop(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($decoded[$key]) || !is_numeric($decoded[$key]) || (int) $decoded[$key] < 0) {
                return null;
            }
        }

        if ((int) $decoded['width'] < 1 || (int) $decoded['height'] < 1) {
            return null;
        }

        return [
            'x' => (int) $decoded['x'],
            'y' => (int) $decoded['y'],
            'width' => (int) $decoded['width'],
            'height' => (int) $decoded['height'],
        ];
    }

    private function table(): string
    {
        return $this->tablePrefix . 'pages';
    }
}
