<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Models\Tag;
use RuntimeException;

/**
 * Tag CRUD, slug generation, and the many-to-many relationship with posts
 * via {prefix}post_tags. Unlike categories, tags are non-hierarchical and
 * are typically created on the fly while writing a post — assignToPost()
 * takes tag names (from a free-text field), not ids, and resolves each
 * through findOrCreateByName().
 *
 * Every query here uses a distinct placeholder name per occurrence, even
 * when binding the same value twice — see CategoryService's docblock and
 * PHP-TEST-SUITE.md's "Known gaps" for why this matters against a real
 * MySQL connection.
 *
 * $hooks is optional (LP-037) — see PostService's docblock for why.
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
     * @return array<int, array{tag: Tag, postCount: int}>
     */
    public function listAllWithPostCounts(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT t.*, COUNT(pt.post_id) AS post_count
               FROM ' . $this->table() . ' t
               LEFT JOIN ' . $this->postTagsTable() . ' pt ON pt.tag_id = t.id
              GROUP BY t.id
              ORDER BY t.name ASC',
        );

        return array_map(
            fn (array $row): array => ['tag' => $this->hydrate($row), 'postCount' => (int) $row['post_count']],
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
}
