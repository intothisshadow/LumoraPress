<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;
use RuntimeException;

/**
 * Page CRUD, slug generation, and the queries behind the frontend page
 * route and admin list view. Mirrors PostService closely — same
 * scheduled-visibility mechanism, same slug generation, same nullable
 * featured_image_id (LP-040) — plus a nullable parent_id for a basic, flat
 * parent/child relationship (no tree view, no drag-and-drop ordering, no
 * hierarchical URLs yet).
 */
final class PageService
{
    private const DEFAULT_PER_PAGE = 20;

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
        PageStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?int $parentId = null,
        ?int $featuredImageId = null,
        ?string $slug = null,
        ContentFormat $contentFormat = ContentFormat::Markdown,
    ): Page {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
<<<<<<< HEAD
                (title, slug, content, content_format, excerpt, status, author_id, parent_id, featured_image_id, published_at, created_at, updated_at)
             VALUES (:title, :slug, :content, :content_format, :excerpt, :status, :author_id, :parent_id, :featured_image_id, :published_at, :created_at, :updated_at)',
=======
                (title, slug, content, excerpt, status, author_id, parent_id, featured_image_id, published_at, created_at, updated_at)
             VALUES (:title, :slug, :content, :excerpt, :status, :author_id, :parent_id, :featured_image_id, :published_at, :created_at, :updated_at)',
>>>>>>> cb58e001bd90c864ec6db29592e9bed2dbd11055
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => $contentFormat->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'author_id' => $authorId,
                'parent_id' => $parentId,
                'featured_image_id' => $featuredImageId,
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

        return $page;
    }

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
<<<<<<< HEAD
                SET title = :title, slug = :slug, content = :content, content_format = :content_format, excerpt = :excerpt,
=======
                SET title = :title, slug = :slug, content = :content, excerpt = :excerpt,
>>>>>>> cb58e001bd90c864ec6db29592e9bed2dbd11055
                    status = :status, parent_id = :parent_id, featured_image_id = :featured_image_id,
                    published_at = :published_at, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => ($contentFormat ?? $existing->contentFormat)->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'parent_id' => $parentId,
                'featured_image_id' => $featuredImageId,
                'published_at' => $this->resolvePublishedAt($status, $publishedAt, $now, $existing->publishedAt)?->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $page = $this->findById($id);

        if ($page === null) {
            throw new RuntimeException('Failed to load the page that was just updated.');
        }

        return $page;
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
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
     * Pages visible to public site visitors: published outright, or
     * scheduled with a published_at time that has already passed.
     *
     * @return array{pages: array<int, Page>, total: int, page: int, perPage: int, totalPages: int}
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
     * All pages regardless of status, for the admin page list.
     *
     * @return array{pages: array<int, Page>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?PageStatus $statusFilter = null): array
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
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function listAllForParentSelect(?int $excludeId = null): array
    {
        if ($excludeId === null) {
            $rows = $this->database->fetchAll('SELECT id, title FROM ' . $this->table() . ' ORDER BY title ASC');
        } else {
            // Two distinct placeholders for the same value: with real
            // (non-emulated) prepared statements — see Database::connect()'s
            // PDO::ATTR_EMULATE_PREPARES => false — MySQL's native prepare
            // protocol treats each occurrence of a named placeholder as its
            // own parameter, so reusing :id twice with a single bound value
            // throws "SQLSTATE[HY093]: Invalid parameter number" at runtime.
            $rows = $this->database->fetchAll(
                'SELECT id, title FROM ' . $this->table() . '
                    WHERE id != :exclude_id AND (parent_id IS NULL OR parent_id != :exclude_id_2)
                 ORDER BY title ASC',
                ['exclude_id' => $excludeId, 'exclude_id_2' => $excludeId],
            );
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']],
            $rows,
        );
    }

    private function resolvePublishedAt(
        PageStatus $status,
        ?DateTimeImmutable $publishedAt,
        DateTimeImmutable $now,
        ?DateTimeImmutable $existingPublishedAt = null,
    ): ?DateTimeImmutable {
        return match ($status) {
            PageStatus::Draft => null,
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
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'pages';
    }
}
