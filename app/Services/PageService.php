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
use LumoraPress\Core\Content\HtmlSanitizer;
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
 * scheduled-visibility mechanism and slug generation, plus a nullable
 * parent_id for a basic, flat parent/child relationship.
 */
final class PageService
{
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Matches the `title` column's VARCHAR(191) width (install/migrations/
     * 0006_create_pages_table.sql) — validated here so an overlong title
     * fails with a clear message instead of a raw truncation/DB error.
     * Mirrors PostService::MAX_TITLE_LENGTH exactly.
     */
    public const MAX_TITLE_LENGTH = 191;

    /**
     * Request-scoped memoization of listAllForTree()'s result. A single
     * request routinely calls it more than once (e.g. the admin "All
     * Pages" tree view also builds its own parent-select dropdown from
     * the same tree) — this avoids repeating the identical query.
     * Cleared by any method that can change which pages exist in the
     * tree or their parent/order within it.
     *
     * @var array<int, array{page: Page, depth: int}>|null
     */
    private ?array $treeCache = null;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * @param array{x: int, y: int, width: int, height: int}|null $featuredImageCrop
     * @throws \InvalidArgumentException if $title is empty or exceeds MAX_TITLE_LENGTH
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
        $this->validateTitle($title);
        $content = $this->sanitizeStoredContent($content, $contentFormat);
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

        // A brand-new page can't be its own parent; applied after insert since the id wasn't known before.
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

        $this->treeCache = null;
        $this->hooks?->doAction('page_saved', $page);

        return $page;
    }

    /**
     * @param array{x: int, y: int, width: int, height: int}|null $featuredImageCrop
     * @throws \InvalidArgumentException if $title is empty or exceeds MAX_TITLE_LENGTH
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

        $this->validateTitle($title);
        $resolvedContentFormat = $contentFormat ?? $existing->contentFormat;
        $content = $this->sanitizeStoredContent($content, $resolvedContentFormat);
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
                'content_format' => $resolvedContentFormat->value,
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

        $this->treeCache = null;
        $this->hooks?->doAction('page_saved', $page);

        return $page;
    }

    /**
     * SEO title/description overrides — mirrors PostService::updateSeo(),
     * see its docblock for why this is a dedicated method.
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
     * Backs the admin list's Quick Edit row — updates only the fields
     * that inline form exposes (title, slug, status, parent), routing
     * through the same `update()` the full editor uses, with every
     * other field (including the existing publish date) preserved unchanged.
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
     * Soft-deletes a page — mirrors PostService::trash() exactly (status
     * flips to Trashed, trashed_at records when, the row stays). There
     * is no automatic purge; delete() is the only way to remove one.
     */
    public function trash(int $id): bool
    {
        $trashed = $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'trashed', trashed_at = :trashed_at WHERE id = :id",
            ['trashed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;

        if ($trashed) {
            $this->treeCache = null;
            $this->hooks?->doAction('page_deleted', $id);
        }

        return $trashed;
    }

    /**
     * Restores a trashed page — always back to Draft, never straight back
     * to its previous status, so a page never resurfaces publicly without a human deciding to republish it.
     */
    public function restore(int $id): bool
    {
        $restored = $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'draft', trashed_at = NULL WHERE id = :id",
            ['id' => $id],
        ) > 0;

        if ($restored) {
            $this->treeCache = null;
        }

        return $restored;
    }

    /**
     * Directly changes a page's status without touching any other field.
     * Scheduled is deliberately not reachable here since scheduling also needs a publish date; use update() for that.
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
            $this->treeCache = null;
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
     * Directly changes a page's parent without touching any other field.
     * A page can never become its own parent; the moved page is placed
     * at the end of its new sibling group via nextMenuOrder().
     */
    public function setParent(int $id, ?int $parentId): bool
    {
        if ($parentId === $id) {
            $parentId = null;
        }

        $moved = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET parent_id = :parent_id, menu_order = :menu_order WHERE id = :id',
            ['parent_id' => $parentId, 'menu_order' => $this->nextMenuOrder($parentId), 'id' => $id],
        ) > 0;

        if ($moved) {
            $this->treeCache = null;
        }

        return $moved;
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
     * Permanently removes a page. parent_id has no FK constraint, so a
     * deleted page's children would otherwise point at a nonexistent
     * parent — direct children are reparented to top-level first.
     */
    public function delete(int $id): bool
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE parent_id = :parent_id',
            ['parent_id' => $id],
        );

        $deleted = $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;

        if ($deleted) {
            $this->treeCache = null;
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
     * widget's published-page count.
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
     * A page by slug scoped to a specific parent (null for top-level) —
     * the single-segment step behind findByPath()'s walk. Validates that
     * a URL's claimed ancestor chain is the page's real one; findBySlug()
     * alone can't tell "/wrong-parent/team" apart from "/about/team".
     */
    public function findBySlugAndParent(string $slug, ?int $parentId): ?Page
    {
        $row = $parentId === null
            ? $this->database->fetchOne(
                'SELECT * FROM ' . $this->table() . ' WHERE slug = :slug AND parent_id IS NULL',
                ['slug' => $slug],
            )
            : $this->database->fetchOne(
                'SELECT * FROM ' . $this->table() . ' WHERE slug = :slug AND parent_id = :parent_id',
                ['slug' => $slug, 'parent_id' => $parentId],
            );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Resolves a hierarchical URL's root-first slug segments (e.g.
     * ["about", "team"]) to the Page at the end of that chain, walking
     * parent_id one segment at a time. Returns null as soon as any
     * segment fails to match, so SiteController can 404 a stale ancestor
     * path rather than guessing which page was meant.
     *
     * @param array<int, string> $segments
     */
    public function findByPath(array $segments): ?Page
    {
        $parentId = null;
        $page = null;

        foreach ($segments as $segment) {
            $page = $this->findBySlugAndParent($segment, $parentId);

            if ($page === null) {
                return null;
            }

            $parentId = $page->id;
        }

        return $page;
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
     * not Private (mirrors PostService::publicWhereClause()'s identical
     * visibility AND).
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
     * Same visibility rules as paginatePublished() (published-or-due,
     * public only), but newest-first by COALESCE(published_at, created_at)
     * rather than alphabetical — for the Pages feed, where a reader
     * expects recently-published pages first, not a title-sorted listing.
     * paginatePublished() itself stays alphabetical since its own callers
     * (the public API listing, the sitemap) have no such expectation.
     *
     * @return array{pages: array<int, Page>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginatePublishedByDate(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
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
                . " ORDER BY COALESCE(published_at, created_at) DESC LIMIT {$perPage} OFFSET {$offset}",
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
     * @param array{term?: string, authorId?: int, parentId?: int, dateFrom?: string, dateTo?: string} $filters
     * @param string $orderBy one of 'title', 'created', 'published' — anything else keeps the
     *     original COALESCE(published_at, created_at) default, since not every page has a publish date yet
     * @return array{pages: array<int, Page>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?PageStatus $statusFilter = null, array $filters = [], string $orderBy = 'date', string $orderDir = 'desc'): array
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

        // Two distinct placeholders bound to the same value, not :term reused twice — MySQL's real prepared statements reject a repeated named placeholder.
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

        // Filters/sorts by the page's own date, falling back to created_at when there's no publish date yet.
        if (($filters['dateFrom'] ?? '') !== '') {
            $conditions[] = 'COALESCE(published_at, created_at) >= :date_from';
            $params['date_from'] = $filters['dateFrom'] . ' 00:00:00';
        }

        if (($filters['dateTo'] ?? '') !== '') {
            $conditions[] = 'COALESCE(published_at, created_at) <= :date_to';
            $params['date_to'] = $filters['dateTo'] . ' 23:59:59';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // Column name can never come from user input directly into SQL —
        // whitelist against the only sortable columns the admin list offers.
        $column = match ($orderBy) {
            'title' => 'title',
            'created' => 'created_at',
            default => 'COALESCE(published_at, created_at)',
        };
        $direction = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " {$where}"
                . " ORDER BY {$column} {$direction} LIMIT {$perPage} OFFSET {$offset}",
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
     * excluding $excludeId itself and its direct children (deeper cycles
     * are an accepted gap for this basic, non-tree parent selector).
     * Trashed pages are excluded.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function listAllForParentSelect(?int $excludeId = null): array
    {
        if ($excludeId === null) {
            $rows = $this->database->fetchAll("SELECT id, title FROM " . $this->table() . " WHERE status != 'trashed' ORDER BY title ASC");
        } else {
            // Two distinct placeholders for the same value: MySQL's real prepared statements reject a repeated named placeholder.
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
     * "Parent Page" picker — combines listAllForTree()'s hierarchical
     * order with listAllForParentSelect()'s cycle-prevention exclusion,
     * so the picker renders indented while ruling out a page becoming its own ancestor.
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
     * A depth-tagged {id, title, slug, depth} list for the admin Menus
     * screen's "Add Pages" checkbox list. Built on listAllForTree() so a
     * child page renders indented under its parent, matching the "All Pages" tree view.
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
     * document order — backs the admin "All" tab's tree view. One
     * unpaginated query is acceptable: pages are evergreen/structural
     * content, not the tens-of-thousands-of-rows table Posts can be.
     *
     * @return array<int, array{page: Page, depth: int}>
     */
    public function listAllForTree(): array
    {
        if ($this->treeCache !== null) {
            return $this->treeCache;
        }

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE status != 'trashed' ORDER BY parent_id, menu_order, id",
        );
        $pages = array_map($this->hydrate(...), $rows);

        return $this->treeCache = $this->flattenForTree($pages, null, 0);
    }

    /**
     * The same shape as listAllForTree(), but restricted to paginatePublished()'s exact
     * visibility rule — backs the Pages widget's nested output, which must never leak a
     * draft/scheduled/private page as some visible page's child. A page whose real parent
     * isn't in this filtered set is dropped entirely, not promoted or misattached.
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
     * among their shared siblings (Hierarchy UI drag-and-drop). $targetId
     * must share $draggedId's parentId — a request that fails this check
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

        $this->treeCache = null;

        return true;
    }

    /**
     * The next menu_order value for a new sibling under $parentId, so a
     * new page lands at the end of its sibling group.
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
     * — backs the public breadcrumbs theme API. Returns an empty array
     * for a top-level page. Capped at 50 hops as a defensive guard
     * against a pathological cycle reaching this method some other way.
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
            // Trashed is never actually reached through this path (trash() sets it directly); this arm just keeps the match exhaustive.
            PageStatus::Draft, PageStatus::PendingReview, PageStatus::Trashed => null,
            PageStatus::Published => $publishedAt ?? $existingPublishedAt ?? $now,
            PageStatus::Scheduled => $publishedAt ?? $existingPublishedAt,
        };
    }

    /**
     * @throws \InvalidArgumentException if $title is empty or too long
     */
    private function validateTitle(string $title): void
    {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('A title is required.');
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException('The title cannot be longer than ' . self::MAX_TITLE_LENGTH . ' characters.');
        }
    }

    /**
     * Runs Html-format content through HtmlSanitizer before it's ever
     * stored — defense in depth on top of ContentRenderer's render-time
     * sanitizing, since the REST API exposes the raw `content` column
     * directly, not just rendered HTML. Markdown/Plain content isn't
     * executable HTML in stored form, so both pass through untouched.
     * Mirrors PostService::sanitizeStoredContent() exactly.
     */
    private function sanitizeStoredContent(string $content, ContentFormat $format): string
    {
        return $format === ContentFormat::Html ? (new HtmlSanitizer())->clean($content) : $content;
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
     * Decodes the `featured_image_crop` column. Duplicated from
     * PostService::decodeCrop() rather than shared — a handful of lines,
     * not worth a base class.
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
