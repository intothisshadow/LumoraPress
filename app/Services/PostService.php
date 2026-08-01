<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;
use RuntimeException;

/**
 * Post CRUD, slug generation, and the queries behind the homepage/single
 * post/admin list views. Scheduled posts are stored with their future
 * published_at and become visible automatically once that time passes
 * (see the "published OR due" clause in publicWhereClause()) — nothing
 * needs to run a background job to flip their status.
 */
final class PostService
{
    private const DEFAULT_PER_PAGE = 10;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
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
        PostStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?int $featuredImageId = null,
        ?string $slug = null,
        bool $commentsOpen = true,
        ContentFormat $contentFormat = ContentFormat::Markdown,
        ?array $featuredImageCrop = null,
    ): Post {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, slug, content, content_format, excerpt, status, author_id, featured_image_id, featured_image_crop, published_at, comment_status, created_at, updated_at)
             VALUES (:title, :slug, :content, :content_format, :excerpt, :status, :author_id, :featured_image_id, :featured_image_crop, :published_at, :comment_status, :created_at, :updated_at)',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => $contentFormat->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'author_id' => $authorId,
                'featured_image_id' => $featuredImageId,
                'featured_image_crop' => $featuredImageId !== null && $featuredImageCrop !== null ? json_encode($featuredImageCrop) : null,
                'published_at' => $this->resolvePublishedAt($status, $publishedAt, $now)?->format('Y-m-d H:i:s'),
                'comment_status' => $commentsOpen ? 'open' : 'closed',
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        $post = $this->findById((int) $id);

        if ($post === null) {
            throw new RuntimeException('Failed to load the post that was just created.');
        }

        return $post;
    }

    /**
     * @param array{x: int, y: int, width: int, height: int}|null $featuredImageCrop
     */
    public function update(
        int $id,
        string $title,
        string $content,
        string $excerpt,
        PostStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?int $featuredImageId = null,
        ?string $slug = null,
        bool $commentsOpen = true,
        ?ContentFormat $contentFormat = null,
        ?array $featuredImageCrop = null,
    ): Post {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Post {$id} does not exist.");
        }

        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title, ignoreId: $id);
        $now = new DateTimeImmutable();

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, slug = :slug, content = :content, content_format = :content_format, excerpt = :excerpt,
                    status = :status, featured_image_id = :featured_image_id, featured_image_crop = :featured_image_crop,
                    published_at = :published_at, comment_status = :comment_status, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => ($contentFormat ?? $existing->contentFormat)->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'featured_image_id' => $featuredImageId,
                'featured_image_crop' => $featuredImageId !== null && $featuredImageCrop !== null ? json_encode($featuredImageCrop) : null,
                'published_at' => $this->resolvePublishedAt($status, $publishedAt, $now, $existing->publishedAt)?->format('Y-m-d H:i:s'),
                'comment_status' => $commentsOpen ? 'open' : 'closed',
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $post = $this->findById($id);

        if ($post === null) {
            throw new RuntimeException('Failed to load the post that was just updated.');
        }

        return $post;
    }

    /**
     * Soft-deletes a post ("Move to Trash") — sets status to Trashed and
     * records when, rather than removing the row. Trashed posts are never
     * publicly visible (Post::isPubliclyVisible() only returns true for
     * Published/due-Scheduled) and are excluded from paginateForAdmin()'s
     * default "All" view (see its own docblock), the same way WordPress
     * hides Trash from every other admin list view. There is no automatic
     * purge of old trashed posts — an administrator uses delete() (via the
     * admin UI's "Delete Permanently" action, only offered for already-
     * trashed posts) to actually remove one.
     */
    public function trash(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'trashed', trashed_at = :trashed_at WHERE id = :id",
            ['trashed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;
    }

    /**
     * Restores a trashed post — always back to Draft, never straight back
     * to its previous status (Published/Scheduled), a deliberate safety
     * choice: silently resurfacing a trashed post as publicly visible
     * again without a human deciding to republish it is exactly the kind
     * of surprising behavior CLAUDE.md's "fail securely" posture argues
     * against, even though restoring the exact prior status is closer to
     * WordPress's own behavior.
     */
    public function restore(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'draft', trashed_at = NULL WHERE id = :id",
            ['id' => $id],
        ) > 0;
    }

    /**
     * Directly changes a post's status without touching any other field
     * (title/content/slug/...) — backs the admin list's per-row and bulk
     * "Mark as Draft"/"Publish" actions, which have no other field to
     * submit. Scheduled is deliberately not reachable through this method
     * since scheduling also needs a publish date; use update() for that.
     */
    public function setStatus(int $id, PostStatus $status): bool
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        $publishedAt = $this->resolvePublishedAt($status, null, $now, $existing->publishedAt);

        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET status = :status, published_at = :published_at, updated_at = :updated_at WHERE id = :id',
            [
                'status' => $status->value,
                'published_at' => $publishedAt?->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;
    }

    /**
     * Clones a post as a new Draft titled "{title} (Copy)" — LP-008's
     * "Duplicate existing posts". $authorId is the user performing the
     * duplication (not necessarily the original post's author), the same
     * "acting user becomes the author" rule create() already applies
     * everywhere else. Categories/tags are deliberately not copied here —
     * the caller reads the original's assignments and re-assigns them to
     * the new post id afterward, the same two-step
     * create-then-assignToPost() pattern admin/views/posts.php's save
     * handler already uses.
     */
    public function duplicate(int $id, int $authorId): ?Post
    {
        $original = $this->findById($id);

        if ($original === null) {
            return null;
        }

        return $this->create(
            title: $original->title . ' (Copy)',
            content: $original->content,
            excerpt: $original->excerpt,
            authorId: $authorId,
            status: PostStatus::Draft,
            featuredImageId: $original->featuredImageId,
            commentsOpen: $original->commentsOpen,
            contentFormat: $original->contentFormat,
            featuredImageCrop: $original->featuredImageCrop,
        );
    }

    public function delete(int $id): bool
    {
        // Referential cleanup: without this, a deleted post's category and
        // tag assignments would linger as orphaned rows.
        $this->database->execute(
            'DELETE FROM ' . $this->tablePrefix . 'post_categories WHERE post_id = :post_id',
            ['post_id' => $id],
        );
        $this->database->execute(
            'DELETE FROM ' . $this->tablePrefix . 'post_tags WHERE post_id = :post_id',
            ['post_id' => $id],
        );
        $this->database->execute(
            'DELETE FROM ' . $this->tablePrefix . 'comments WHERE post_id = :post_id',
            ['post_id' => $id],
        );

        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    public function findById(int $id): ?Post
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Used by the admin Users screen to block deleting a user who has
     * authored posts, since there is no admin reassignment UI yet.
     */
    public function countByAuthor(int $authorId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE author_id = :author_id',
            ['author_id' => $authorId],
        );
    }

    /**
     * Backs the "(N)" counts on the admin post list's status filter tabs,
     * including the Trash tab (which paginateForAdmin() excludes from its
     * default "All" view — see that method's docblock).
     */
    public function countByStatus(PostStatus $status): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE status = :status',
            ['status' => $status->value],
        );
    }

    public function findBySlug(string $slug): ?Post
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Titles of every post using $mediaId as its featured image — used by
     * MediaUsageChecker to warn before deleting a referenced file, same
     * "block/warn because referenced" purpose countByAuthor() serves for
     * the admin Users screen.
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
     * Posts visible to public site visitors: published outright, or
     * scheduled with a published_at time that has already passed. Ordered
     * newest-first by published_at.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginatePublished(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = "(status = 'published' OR (status = 'scheduled' AND published_at <= :now))";

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$where}",
            ['now' => $now],
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE {$where}"
                . " ORDER BY published_at DESC LIMIT {$perPage} OFFSET {$offset}",
            ['now' => $now],
        );

        return [
            'posts' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Posts visible to public site visitors that are directly assigned to
     * $categoryId — posts in child categories are not included (a category
     * archive is scoped to that exact category only).
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateByCategory(int $categoryId, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = "(p.status = 'published' OR (p.status = 'scheduled' AND p.published_at <= :now))";
        $join = 'INNER JOIN ' . $this->tablePrefix . 'post_categories pc ON pc.post_id = p.id AND pc.category_id = :category_id';

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " p {$join} WHERE {$where}",
            ['now' => $now, 'category_id' => $categoryId],
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT p.* FROM ' . $this->table() . " p {$join} WHERE {$where}"
                . " ORDER BY p.published_at DESC LIMIT {$perPage} OFFSET {$offset}",
            ['now' => $now, 'category_id' => $categoryId],
        );

        return [
            'posts' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Posts visible to public site visitors that are assigned to $tagId.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateByTag(int $tagId, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = "(p.status = 'published' OR (p.status = 'scheduled' AND p.published_at <= :now))";
        $join = 'INNER JOIN ' . $this->tablePrefix . 'post_tags pt ON pt.post_id = p.id AND pt.tag_id = :tag_id';

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " p {$join} WHERE {$where}",
            ['now' => $now, 'tag_id' => $tagId],
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT p.* FROM ' . $this->table() . " p {$join} WHERE {$where}"
                . " ORDER BY p.published_at DESC LIMIT {$perPage} OFFSET {$offset}",
            ['now' => $now, 'tag_id' => $tagId],
        );

        return [
            'posts' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Posts visible to public site visitors written by $authorId (LP-021,
     * for the REST API's ?author= filter) — no join needed, unlike
     * paginateByCategory()/paginateByTag(), since author_id is a direct
     * column on this table.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateByAuthor(int $authorId, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = "author_id = :author_id AND (status = 'published' OR (status = 'scheduled' AND published_at <= :now))";

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$where}",
            ['now' => $now, 'author_id' => $authorId],
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE {$where}"
                . " ORDER BY published_at DESC LIMIT {$perPage} OFFSET {$offset}",
            ['now' => $now, 'author_id' => $authorId],
        );

        return [
            'posts' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Posts visible to public site visitors published in a given calendar
     * month (LP-048, for the Archives widget's monthly links and the
     * `/archive/{year}/{month}` route behind them).
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateByMonth(int $year, int $month, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = "(status = 'published' OR (status = 'scheduled' AND published_at <= :now))"
            . ' AND YEAR(published_at) = :year AND MONTH(published_at) = :month';
        $params = ['now' => $now, 'year' => $year, 'month' => $month];

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE {$where}"
                . " ORDER BY published_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'posts' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Post counts grouped by calendar month, newest first — backs the
     * Archives widget (LP-048). Limited to $limit months so a long-running
     * blog doesn't render an unbounded link list.
     *
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function monthlyArchiveCounts(int $limit = 12): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $where = "(status = 'published' OR (status = 'scheduled' AND published_at <= :now)) AND published_at IS NOT NULL";
        $limit = max(1, $limit);

        $rows = $this->database->fetchAll(
            'SELECT YEAR(published_at) AS y, MONTH(published_at) AS m, COUNT(*) AS c
               FROM ' . $this->table() . " WHERE {$where}
              GROUP BY y, m
              ORDER BY y DESC, m DESC
              LIMIT {$limit}",
            ['now' => $now],
        );

        return array_map(
            static fn (array $row): array => ['year' => (int) $row['y'], 'month' => (int) $row['m'], 'count' => (int) $row['c']],
            $rows,
        );
    }

    /**
     * A flat {id, title, slug} list for the admin Menus screen's "Add
     * Posts" checkbox list (LP-049) — mirrors
     * PageService::listAllForParentSelect()'s shape. Regardless of
     * status, same as that method: an editor building a menu may
     * knowingly link to a not-yet-published post.
     *
     * @return array<int, array{id: int, title: string, slug: string}>
     */
    public function listAllForMenuSelect(int $limit = 200): array
    {
        $limit = max(1, $limit);

        $rows = $this->database->fetchAll(
            'SELECT id, title, slug FROM ' . $this->table() . " ORDER BY created_at DESC LIMIT {$limit}",
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug']],
            $rows,
        );
    }

    /**
     * Every post for the admin post list, filtered by status. With no
     * filter, Trashed posts are excluded from this "All" view — the same
     * way WordPress's post list hides Trash unless it's the explicitly
     * selected filter, since there's no automatic purge to otherwise stop
     * old trashed posts from cluttering every other view forever. Pass
     * PostStatus::Trashed explicitly to see the Trash list itself.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?PostStatus $statusFilter = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if ($statusFilter !== null) {
            $where = 'WHERE status = :status';
            $params = ['status' => $statusFilter->value];
        } else {
            $where = "WHERE status != 'trashed'";
            $params = [];
        }

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " {$where}"
                . " ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'posts' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    private function resolvePublishedAt(
        PostStatus $status,
        ?DateTimeImmutable $publishedAt,
        DateTimeImmutable $now,
        ?DateTimeImmutable $existingPublishedAt = null,
    ): ?DateTimeImmutable {
        return match ($status) {
            PostStatus::Draft, PostStatus::Trashed => null,
            PostStatus::Published => $publishedAt ?? $existingPublishedAt ?? $now,
            PostStatus::Scheduled => $publishedAt ?? $existingPublishedAt,
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

        return $slug === '' ? 'post' : $slug;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Post
    {
        return new Post(
            id: (int) $row['id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            content: (string) $row['content'],
            excerpt: (string) ($row['excerpt'] ?? ''),
            status: PostStatus::from((string) $row['status']),
            authorId: (int) $row['author_id'],
            featuredImageId: $row['featured_image_id'] !== null ? (int) $row['featured_image_id'] : null,
            publishedAt: $row['published_at'] !== null ? new DateTimeImmutable((string) $row['published_at']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            commentsOpen: ($row['comment_status'] ?? 'open') === 'open',
            contentFormat: ContentFormat::tryFrom((string) ($row['content_format'] ?? '')) ?? ContentFormat::Plain,
            trashedAt: isset($row['trashed_at']) ? new DateTimeImmutable((string) $row['trashed_at']) : null,
            featuredImageCrop: self::decodeCrop($row['featured_image_crop'] ?? null),
        );
    }

    /**
     * Decodes the `featured_image_crop` column, rejecting anything that
     * isn't a well-formed {x,y,width,height} rectangle of non-negative
     * integers with a positive width/height — a hand-crafted or corrupted
     * value should silently fall back to "no crop" (automatic centered
     * thumbnailing) rather than ever reach ThumbnailService with garbage
     * coordinates.
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
        return $this->tablePrefix . 'posts';
    }
}
