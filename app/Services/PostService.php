<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
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
    ): Post {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, slug, content, excerpt, status, author_id, featured_image_id, published_at, comment_status, created_at, updated_at)
             VALUES (:title, :slug, :content, :excerpt, :status, :author_id, :featured_image_id, :published_at, :comment_status, :created_at, :updated_at)',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'author_id' => $authorId,
                'featured_image_id' => $featuredImageId,
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
    ): Post {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Post {$id} does not exist.");
        }

        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title, ignoreId: $id);
        $now = new DateTimeImmutable();

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, slug = :slug, content = :content, excerpt = :excerpt,
                    status = :status, featured_image_id = :featured_image_id,
                    published_at = :published_at, comment_status = :comment_status, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'featured_image_id' => $featuredImageId,
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
     * All posts regardless of status, for the admin post list.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?PostStatus $statusFilter = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $where = $statusFilter !== null ? 'WHERE status = :status' : '';
        $params = $statusFilter !== null ? ['status' => $statusFilter->value] : [];

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
            PostStatus::Draft => null,
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
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'posts';
    }
}
