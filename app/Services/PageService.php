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
    ): Page {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, slug, content, content_format, excerpt, status, author_id, parent_id, featured_image_id, featured_image_crop, published_at, created_at, updated_at)
             VALUES (:title, :slug, :content, :content_format, :excerpt, :status, :author_id, :parent_id, :featured_image_id, :featured_image_crop, :published_at, :created_at, :updated_at)',
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
                    status = :status, parent_id = :parent_id, featured_image_id = :featured_image_id, featured_image_crop = :featured_image_crop,
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

    public function delete(int $id): bool
    {
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

    /**
     * A flat {id, title, slug} list for the admin Menus screen's "Add
     * Pages" checkbox list (LP-049) — separate from
     * listAllForParentSelect() above since that method's shape/exclusion
     * rules are specific to the parent-page picker, not menu building.
     *
     * @return array<int, array{id: int, title: string, slug: string}>
     */
    public function listAllForMenuSelect(): array
    {
        $rows = $this->database->fetchAll('SELECT id, title, slug FROM ' . $this->table() . ' ORDER BY title ASC');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug']],
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
            featuredImageCrop: self::decodeCrop($row['featured_image_crop'] ?? null),
            metaTitle: isset($row['meta_title']) && $row['meta_title'] !== '' ? (string) $row['meta_title'] : null,
            metaDescription: isset($row['meta_description']) && $row['meta_description'] !== '' ? (string) $row['meta_description'] : null,
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
