<?php

/**
 * Tag CRUD, slug generation, and the many-to-many relationship with posts.
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
use LumoraPress\Models\Tag;
use RuntimeException;

/**
 * Tag CRUD, slug generation, and the post relationship via {prefix}post_tags.
 * Non-hierarchical, and typically created on the fly: assignToPost() takes tag names, not
 * ids, resolved through findOrCreateByName(). See CategoryService's docblock for why every
 * query uses a distinct placeholder name per occurrence.
 */
final class TagService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function create(string $name, string $description, ?string $slug = null): Tag
    {
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $name);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (name, slug, description, created_at, updated_at)
             VALUES (:name, :slug, :description, :created_at, :updated_at)',
            [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        $tag = $this->findById((int) $id);

        if ($tag === null) {
            throw new RuntimeException('Failed to load the tag that was just created.');
        }

        $this->hooks?->doAction('tag_saved', $tag);

        return $tag;
    }

    public function update(int $id, string $name, string $description, ?string $slug = null): Tag
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Tag {$id} does not exist.");
        }

        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $name, ignoreId: $id);
        $now = new DateTimeImmutable();

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET name = :name, slug = :slug, description = :description, updated_at = :updated_at
              WHERE id = :id',
            [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $tag = $this->findById($id);

        if ($tag === null) {
            throw new RuntimeException('Failed to load the tag that was just updated.');
        }

        $this->hooks?->doAction('tag_saved', $tag);

        return $tag;
    }

    public function delete(int $id): bool
    {
        $deleted = (bool) $this->database->transaction(function () use ($id): int {
            $this->database->execute(
                'DELETE FROM ' . $this->postTagsTable() . ' WHERE tag_id = :tag_id',
                ['tag_id' => $id],
            );

            return $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE id = :id',
                ['id' => $id],
            );
        });

        if ($deleted) {
            $this->hooks?->doAction('tag_deleted', $id);
        }

        return $deleted;
    }

    /**
     * Merges $sourceId into $targetId: every post gains $targetId (existence-checked first
     * to avoid a composite-key collision), $sourceId's rows are dropped, and $sourceId is
     * deleted. Tags have no hierarchy to reparent, unlike CategoryService::merge(). Returns
     * false without changing anything if $sourceId === $targetId or either doesn't exist.
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
                    'SELECT post_id FROM ' . $this->postTagsTable() . ' WHERE tag_id = :tag_id',
                    ['tag_id' => $sourceId],
                ),
            );

            foreach ($postIds as $postId) {
                $exists = $this->database->fetchOne(
                    'SELECT 1 FROM ' . $this->postTagsTable() . ' WHERE post_id = :post_id AND tag_id = :tag_id',
                    ['post_id' => $postId, 'tag_id' => $targetId],
                ) !== null;

                if (!$exists) {
                    $this->database->execute(
                        'INSERT INTO ' . $this->postTagsTable() . ' (post_id, tag_id) VALUES (:post_id, :tag_id)',
                        ['post_id' => $postId, 'tag_id' => $targetId],
                    );
                }
            }

            $this->database->execute(
                'DELETE FROM ' . $this->postTagsTable() . ' WHERE tag_id = :tag_id',
                ['tag_id' => $sourceId],
            );

            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE id = :id',
                ['id' => $sourceId],
            );
        });

        $this->hooks?->doAction('tag_merged', $sourceId, $targetId);

        return true;
    }

    /**
     * Deletes every tag with zero assigned posts (the admin Tags list's
     * "Remove unused tags" bulk action) — a one-click cleanup for tags
     * left behind once their last post was untagged/deleted/edited,
     * rather than requiring an administrator to hunt them down one at a
     * time. Returns the number of tags removed.
     */
    public function deleteUnused(): int
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll(
                'SELECT t.id FROM ' . $this->table() . ' t
                    LEFT JOIN ' . $this->postTagsTable() . ' pt ON pt.tag_id = t.id
                 WHERE pt.tag_id IS NULL',
            ),
        );

        foreach ($ids as $id) {
            $this->delete($id);
        }

        return count($ids);
    }

    public function findById(int $id): ?Tag
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Tag
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Case-insensitive lookup, falling back to create() — this is what
     * prevents "Sci-Fi" and "sci-fi" from becoming two different tags.
     * The first-created casing wins on reuse.
     */
    public function findOrCreateByName(string $name): Tag
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE LOWER(name) = LOWER(:name)',
            ['name' => $name],
        );

        if ($row !== null) {
            return $this->hydrate($row);
        }

        return $this->create($name, '');
    }

    /**
     * @return array<int, Tag>
     */
    public function listAll(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' ORDER BY name ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Total tag count — the admin list's "Tags (N)" heading, matching
     * Posts'/Pages'/Categories' own status-count conventions. A single
     * COUNT() rather than count(listAll()), which would otherwise fetch
     * and hydrate every row just to discard them.
     */
    public function count(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table());
    }

    /**
     * $filters backs the admin list's Search & Filter panel; an empty array
     * behaves identically to the old no-argument form. "Last used" has no
     * dedicated column — it's derived as the newest created_at among a
     * tag's assigned posts, since that's what "used" actually means and
     * avoids a schema change to track it separately.
     *
     * @param array{term?: string, minPosts?: int, dateFrom?: string, dateTo?: string, lastUsedFrom?: string, lastUsedTo?: string} $filters
     *     term: matches name or slug (LIKE). minPosts: tag must have at least this many
     *     assigned posts. dateFrom/dateTo: created_at date range, inclusive, as Y-m-d
     *     strings. lastUsedFrom/lastUsedTo: same, but against the derived last-used date.
     * @return array<int, array{tag: Tag, postCount: int, lastUsedAt: ?DateTimeImmutable}>
     */
    public function listAllWithPostCounts(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        $term = trim((string) ($filters['term'] ?? ''));

        if ($term !== '') {
            $conditions[] = '(t.name LIKE :term_name OR t.slug LIKE :term_slug)';
            $params['term_name'] = '%' . $term . '%';
            $params['term_slug'] = '%' . $term . '%';
        }

        $dateFrom = (string) ($filters['dateFrom'] ?? '');

        if ($dateFrom !== '') {
            $conditions[] = 't.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = (string) ($filters['dateTo'] ?? '');

        if ($dateTo !== '') {
            $conditions[] = 't.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $sql = 'SELECT t.*, COUNT(pt.post_id) AS post_count, MAX(p.created_at) AS last_used_at
                  FROM ' . $this->table() . ' t
                  LEFT JOIN ' . $this->postTagsTable() . ' pt ON pt.tag_id = t.id
                  LEFT JOIN ' . $this->postsTable() . ' p ON p.id = pt.post_id';

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' GROUP BY t.id';

        $having = [];

        // Interpolated directly (already int/string-cast above), matching
        // CategoryService::listAllWithPostCounts()'s own minPosts handling —
        // a bound parameter compares as SQLite's TEXT storage class against
        // post_count's INTEGER class and never matches.
        $minPosts = (int) ($filters['minPosts'] ?? 0);

        if ($minPosts > 0) {
            $having[] = 'post_count >= ' . $minPosts;
        }

        $lastUsedFrom = (string) ($filters['lastUsedFrom'] ?? '');

        if ($lastUsedFrom !== '') {
            $having[] = 'last_used_at >= :last_used_from';
            $params['last_used_from'] = $lastUsedFrom . ' 00:00:00';
        }

        $lastUsedTo = (string) ($filters['lastUsedTo'] ?? '');

        if ($lastUsedTo !== '') {
            $having[] = 'last_used_at <= :last_used_to';
            $params['last_used_to'] = $lastUsedTo . ' 23:59:59';
        }

        if ($having !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $having);
        }

        $sql .= ' ORDER BY t.name ASC';

        $rows = $this->database->fetchAll($sql, $params);

        return array_map(
            fn (array $row): array => [
                'tag' => $this->hydrate($row),
                'postCount' => (int) $row['post_count'],
                'lastUsedAt' => $row['last_used_at'] !== null ? new DateTimeImmutable((string) $row['last_used_at']) : null,
            ],
            $rows,
        );
    }

    /**
     * @return array<int, Tag>
     */
    public function tagsForPost(int $postId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT t.* FROM ' . $this->table() . ' t
                INNER JOIN ' . $this->postTagsTable() . ' pt ON pt.tag_id = t.id
             WHERE pt.post_id = :post_id
             ORDER BY t.name ASC',
            ['post_id' => $postId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function postCount(int $tagId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->postTagsTable() . ' WHERE tag_id = :tag_id',
            ['tag_id' => $tagId],
        );
    }

    /**
     * Replaces every tag assignment for a post with $tagNames, resolving
     * each name through findOrCreateByName().
     *
     * @param array<int, string> $tagNames
     */
    public function assignToPost(int $postId, array $tagNames): void
    {
        $seen = [];
        $names = [];

        foreach ($tagNames as $name) {
            $trimmed = trim((string) $name);

            if ($trimmed === '') {
                continue;
            }

            $key = strtolower($trimmed);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = $trimmed;
        }

        $tagIds = array_map(fn (string $name): int => $this->findOrCreateByName($name)->id, $names);

        $this->database->transaction(function () use ($postId, $tagIds): void {
            $this->database->execute(
                'DELETE FROM ' . $this->postTagsTable() . ' WHERE post_id = :post_id',
                ['post_id' => $postId],
            );

            foreach ($tagIds as $tagId) {
                $this->database->execute(
                    'INSERT INTO ' . $this->postTagsTable() . ' (post_id, tag_id) VALUES (:post_id, :tag_id)',
                    ['post_id' => $postId, 'tag_id' => $tagId],
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

        return $slug === '' ? 'tag' : $slug;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Tag
    {
        return new Tag(
            id: (int) $row['id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            description: (string) ($row['description'] ?? ''),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'tags';
    }

    private function postTagsTable(): string
    {
        return $this->tablePrefix . 'post_tags';
    }

    private function postsTable(): string
    {
        return $this->tablePrefix . 'posts';
    }
}
