<?php

/**
 * CRUD for the link_directory_links table: title/URL/category/description/thumbnail for one directory entry.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\ContentFormat;
use RuntimeException;

/**
 * A link's URL is a plain external address stored directly on this table —
 * unlike Downloads' Url-typed entries, a link directory entry never routes
 * through RedirectService, since click-tracking a link-directory listing
 * isn't part of this plugin's scope. $thumbnailMediaId is only ever a
 * reference into the existing Media Library (resolved by the calling admin
 * view the same way Posts/Pages resolve featuredImageId); this service
 * never deletes the underlying Media row, the same convention Download's
 * own thumbnailMediaId already established.
 */
final class LinkService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function create(
        string $title,
        string $url,
        string $description,
        ContentFormat $descriptionFormat,
        ?int $categoryId,
        ?int $thumbnailMediaId,
    ): Link {
        $now = date('Y-m-d H:i:s');

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, url, description, description_format, category_id, thumbnail_media_id, created_at, updated_at)
             VALUES (:title, :url, :description, :description_format, :category_id, :thumbnail_media_id, :created_at, :updated_at)',
            [
                'title' => $title,
                'url' => $url,
                'description' => $description,
                'description_format' => $descriptionFormat->value,
                'category_id' => $categoryId,
                'thumbnail_media_id' => $thumbnailMediaId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $link = $this->findById((int) $id);

        if ($link === null) {
            throw new RuntimeException('Failed to load the link that was just created.');
        }

        return $link;
    }

    public function update(
        int $id,
        string $title,
        string $url,
        string $description,
        ContentFormat $descriptionFormat,
        ?int $categoryId,
        ?int $thumbnailMediaId,
    ): bool {
        return $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, url = :url, description = :description, description_format = :description_format,
                    category_id = :category_id, thumbnail_media_id = :thumbnail_media_id, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'url' => $url,
                'description' => $description,
                'description_format' => $descriptionFormat->value,
                'category_id' => $categoryId,
                'thumbnail_media_id' => $thumbnailMediaId,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ],
        ) > 0;
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

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    public function countByStatus(LinkStatus $status): int
    {
        $condition = $status === LinkStatus::Trashed ? 'trashed_at IS NOT NULL' : 'trashed_at IS NULL';

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$condition}");
    }

    public function duplicate(int $id): ?Link
    {
        $original = $this->findById($id);

        if ($original === null) {
            return null;
        }

        return $this->create(
            $original->title . ' (Copy)',
            $original->url,
            $original->description,
            $original->descriptionFormat,
            $original->categoryId,
            $original->thumbnailMediaId,
        );
    }

    /**
     * Flat, paginated, sortable admin-list method — distinct from
     * listAllGroupedByCategory(), the public-facing data source.
     *
     * @param array{term?: string, categoryId?: int} $filters
     * @return array{links: array<int, Link>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(
        int $page = 1,
        int $perPage = 20,
        ?LinkStatus $statusFilter = null,
        array $filters = [],
        string $orderBy = 'title',
        string $orderDir = 'asc',
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $conditions = [$statusFilter === LinkStatus::Trashed ? 'trashed_at IS NOT NULL' : 'trashed_at IS NULL'];
        $params = [];

        if (($filters['term'] ?? '') !== '') {
            $conditions[] = '(title LIKE :term OR url LIKE :term_2)';
            $params['term'] = '%' . $filters['term'] . '%';
            $params['term_2'] = '%' . $filters['term'] . '%';
        }

        if (($filters['categoryId'] ?? 0) > 0) {
            $conditions[] = 'category_id = :category_id';
            $params['category_id'] = (int) $filters['categoryId'];
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // Column name can never come from user input directly into SQL —
        // whitelist against the only sortable columns this table has.
        $column = match ($orderBy) {
            'id' => 'id',
            'date' => 'created_at',
            default => 'title',
        };
        $direction = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        $total = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . " {$where}", $params);

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " {$where} ORDER BY {$column} {$direction} LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'links' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    public function findById(int $id): ?Link
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * One category's links, alphabetical by title — the
     * [lumora_link_directory]` shortcode's "listings in a single category"
     * variant's data source. $categoryId null means the uncategorized bucket.
     *
     * @return array<int, Link>
     */
    public function listByCategory(?int $categoryId): array
    {
        $sql = $categoryId !== null
            ? 'SELECT * FROM ' . $this->table() . ' WHERE category_id = :category_id AND trashed_at IS NULL ORDER BY title ASC'
            : 'SELECT * FROM ' . $this->table() . ' WHERE category_id IS NULL AND trashed_at IS NULL ORDER BY title ASC';

        $rows = $this->database->fetchAll($sql, $categoryId !== null ? ['category_id' => $categoryId] : []);

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Every non-trashed link, regardless of category — LinkDirectoryPortabilityService's
     * export data source (LPP-027). Grouping by category_id first keeps a
     * re-imported file's own link order close to how it reads in the admin.
     *
     * @return array<int, Link>
     */
    public function listAllForExport(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE trashed_at IS NULL ORDER BY category_id ASC, title ASC',
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Link
    {
        return new Link(
            id: (int) $row['id'],
            title: (string) $row['title'],
            url: (string) $row['url'],
            description: (string) ($row['description'] ?? ''),
            descriptionFormat: ContentFormat::tryFrom((string) ($row['description_format'] ?? '')) ?? ContentFormat::Plain,
            categoryId: $row['category_id'] !== null ? (int) $row['category_id'] : null,
            thumbnailMediaId: $row['thumbnail_media_id'] !== null ? (int) $row['thumbnail_media_id'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            trashedAt: $row['trashed_at'] !== null ? new DateTimeImmutable((string) $row['trashed_at']) : null,
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'link_directory_links';
    }
}
