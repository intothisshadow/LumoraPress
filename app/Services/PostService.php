<?php

/**
 * Post CRUD, slug generation, and the queries behind the homepage, single post view, and admin list.
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
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use RuntimeException;

/**
 * Post CRUD, slug generation, and the queries behind the homepage/single
 * post/admin list views. Scheduled posts are stored with their future
 * published_at and become visible automatically once that time passes
 * — nothing needs to run a background job to flip their status.
 *
 * Firing 'post_saved'/'post_deleted' here rather than from the admin
 * views is deliberate: posts are also written by the Dashboard's Quick
 * Draft form, so this is the one choke point every caller actually shares.
 */
final class PostService
{
    private const DEFAULT_PER_PAGE = 10;

    /**
     * Matches the `title` column's VARCHAR(191) width (install/migrations/
     * 0004_create_posts_table.sql) — validated here so an overlong title
     * fails with a clear message instead of a raw truncation/DB error.
     */
    public const MAX_TITLE_LENGTH = 191;

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
        PostStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?int $featuredImageId = null,
        ?string $slug = null,
        bool $commentsOpen = true,
        ContentFormat $contentFormat = ContentFormat::Markdown,
        ?array $featuredImageCrop = null,
        PostVisibility $visibility = PostVisibility::Public,
        bool $isSticky = false,
        ?DateTimeImmutable $unpublishAt = null,
    ): Post {
        $this->validateTitle($title);
        $content = $this->sanitizeStoredContent($content, $contentFormat);
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, slug, content, content_format, excerpt, status, visibility, is_sticky, author_id, featured_image_id, featured_image_crop, published_at, unpublish_at, comment_status, created_at, updated_at)
             VALUES (:title, :slug, :content, :content_format, :excerpt, :status, :visibility, :is_sticky, :author_id, :featured_image_id, :featured_image_crop, :published_at, :unpublish_at, :comment_status, :created_at, :updated_at)',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => $contentFormat->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'visibility' => $visibility->value,
                'is_sticky' => $isSticky ? 1 : 0,
                'author_id' => $authorId,
                'featured_image_id' => $featuredImageId,
                'featured_image_crop' => $featuredImageId !== null && $featuredImageCrop !== null ? json_encode($featuredImageCrop) : null,
                'published_at' => $this->resolvePublishedAt($status, $publishedAt, $now)?->format('Y-m-d H:i:s'),
                'unpublish_at' => $unpublishAt?->format('Y-m-d H:i:s'),
                'comment_status' => $commentsOpen ? 'open' : 'closed',
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        $post = $this->findById((int) $id);

        if ($post === null) {
            throw new RuntimeException('Failed to load the post that was just created.');
        }

        $this->hooks?->doAction('post_saved', $post);

        return $post;
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
        PostStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?int $featuredImageId = null,
        ?string $slug = null,
        bool $commentsOpen = true,
        ?ContentFormat $contentFormat = null,
        ?array $featuredImageCrop = null,
        ?PostVisibility $visibility = null,
        ?bool $isSticky = null,
        ?DateTimeImmutable $unpublishAt = null,
        bool $clearUnpublishAt = false,
    ): Post {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException("Post {$id} does not exist.");
        }

        $this->validateTitle($title);
        $resolvedContentFormat = $contentFormat ?? $existing->contentFormat;
        $content = $this->sanitizeStoredContent($content, $resolvedContentFormat);
        $slug = $this->generateUniqueSlug($slug !== null && $slug !== '' ? $slug : $title, ignoreId: $id);
        $now = new DateTimeImmutable();

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, slug = :slug, content = :content, content_format = :content_format, excerpt = :excerpt,
                    status = :status, visibility = :visibility, is_sticky = :is_sticky, featured_image_id = :featured_image_id, featured_image_crop = :featured_image_crop,
                    published_at = :published_at, unpublish_at = :unpublish_at, comment_status = :comment_status, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => $resolvedContentFormat->value,
                'excerpt' => $excerpt,
                'status' => $status->value,
                'visibility' => ($visibility ?? $existing->visibility)->value,
                'is_sticky' => ($isSticky ?? $existing->isSticky) ? 1 : 0,
                'featured_image_id' => $featuredImageId,
                'featured_image_crop' => $featuredImageId !== null && $featuredImageCrop !== null ? json_encode($featuredImageCrop) : null,
                'published_at' => $this->resolvePublishedAt($status, $publishedAt, $now, $existing->publishedAt)?->format('Y-m-d H:i:s'),
                'unpublish_at' => $clearUnpublishAt ? null : ($unpublishAt ?? $existing->unpublishAt)?->format('Y-m-d H:i:s'),
                'comment_status' => $commentsOpen ? 'open' : 'closed',
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $post = $this->findById($id);

        if ($post === null) {
            throw new RuntimeException('Failed to load the post that was just updated.');
        }

        $this->hooks?->doAction('post_saved', $post);

        return $post;
    }

    /**
     * SEO title/description overrides — a dedicated method rather than
     * two more params on the already-long create()/update(). Empty
     * strings are stored as NULL so the SEO template tags fall back
     * correctly rather than treating "" as a deliberate override.
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
            $post = $this->findById($id);

            if ($post !== null) {
                $this->hooks?->doAction('post_saved', $post);
            }
        }
    }

    /**
     * Soft-deletes a post ("Move to Trash") — sets status to Trashed and
     * records when, rather than removing the row. There is no automatic
     * purge; delete() is the only way to actually remove one.
     */
    public function trash(int $id): bool
    {
        $trashed = $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'trashed', trashed_at = :trashed_at WHERE id = :id",
            ['trashed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;

        if ($trashed) {
            $this->hooks?->doAction('post_deleted', $id);
        }

        return $trashed;
    }

    /**
     * Restores a trashed post — always back to Draft, never straight back
     * to its previous status, so a post never resurfaces publicly
     * without a human deciding to republish it.
     */
    public function restore(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . " SET status = 'draft', trashed_at = NULL WHERE id = :id",
            ['id' => $id],
        ) > 0;
    }

    /**
     * Directly changes a post's status without touching any other field.
     * Scheduled is deliberately not reachable here since scheduling also
     * needs a publish date; use update() for that.
     */
    public function setStatus(int $id, PostStatus $status): bool
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
            $post = $this->findById($id);

            if ($post !== null) {
                $this->hooks?->doAction('post_saved', $post);
            }
        }

        return $changed;
    }

    /**
     * Pins/unpins a post at the top of the homepage listing (see
     * paginatePublished()'s sticky-first ordering).
     */
    public function setSticky(int $id, bool $isSticky): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET is_sticky = :is_sticky WHERE id = :id',
            ['is_sticky' => $isSticky ? 1 : 0, 'id' => $id],
        ) > 0;
    }

    /**
     * "Private posts" — see PostVisibility's docblock and
     * Post::isVisibleToViewer().
     */
    public function setVisibility(int $id, PostVisibility $visibility): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET visibility = :visibility WHERE id = :id',
            ['visibility' => $visibility->value, 'id' => $id],
        ) > 0;
    }

    /**
     * Reassigns a post to a different existing user. Gated by
     * edit_others_posts in the admin UI, not enforced here since
     * PostService has no notion of "the current user."
     */
    public function reassignAuthor(int $id, int $authorId): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET author_id = :author_id WHERE id = :id',
            ['author_id' => $authorId, 'id' => $id],
        ) > 0;
    }

    /**
     * Bulk form of reassignAuthor() — the Bulk Actions "Change author".
     *
     * @param array<int, int> $ids
     * @return int how many posts were reassigned
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
     * Bulk form of setVisibility() — the Bulk Actions "Change visibility".
     *
     * @param array<int, int> $ids
     * @return int how many posts were updated
     */
    public function bulkSetVisibility(array $ids, PostVisibility $visibility): int
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
     * Every meta_key/meta_value pair stored against $postId, in
     * insertion order — deliberately a simple key/value list, not a
     * typed custom-fields schema.
     *
     * @return array<int, array{key: string, value: string}>
     */
    public function metaForPost(int $postId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT meta_key, meta_value FROM ' . $this->postMetaTable() . ' WHERE post_id = :post_id ORDER BY id ASC',
            ['post_id' => $postId],
        );

        return array_map(
            static fn (array $row): array => ['key' => (string) $row['meta_key'], 'value' => (string) ($row['meta_value'] ?? '')],
            $rows,
        );
    }

    /**
     * Replaces every custom field on $postId with $pairs (delete then
     * re-insert), so the admin edit screen's repeatable row editor
     * doesn't need to diff against what was there before. Rows with a
     * blank key are silently dropped.
     *
     * @param array<int, array{key: string, value: string}> $pairs
     */
    public function replaceMetaForPost(int $postId, array $pairs): void
    {
        $this->database->transaction(function () use ($postId, $pairs): void {
            $this->database->execute(
                'DELETE FROM ' . $this->postMetaTable() . ' WHERE post_id = :post_id',
                ['post_id' => $postId],
            );

            foreach ($pairs as $pair) {
                $key = trim((string) ($pair['key'] ?? ''));

                if ($key === '') {
                    continue;
                }

                $this->database->execute(
                    'INSERT INTO ' . $this->postMetaTable() . ' (post_id, meta_key, meta_value) VALUES (:post_id, :meta_key, :meta_value)',
                    ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => (string) ($pair['value'] ?? '')],
                );
            }
        });
    }

    /**
     * A single custom field's value, for theme use (e.g. a plugin or
     * template reading one known key) — null if $postId has no field
     * named $key.
     */
    public function metaValue(int $postId, string $key): ?string
    {
        $row = $this->database->fetchOne(
            'SELECT meta_value FROM ' . $this->postMetaTable() . ' WHERE post_id = :post_id AND meta_key = :meta_key',
            ['post_id' => $postId, 'meta_key' => $key],
        );

        return $row !== null ? (string) ($row['meta_value'] ?? '') : null;
    }

    /**
     * Clones a post as a new Draft titled "{title} (Copy)". $authorId is
     * the user performing the duplication, not necessarily the
     * original's author. Categories/tags aren't copied here — the
     * caller reads the original's assignments and re-assigns them afterward.
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

        $deleted = $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;

        if ($deleted) {
            $this->hooks?->doAction('post_deleted', $id);
        }

        return $deleted;
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
     * Backs the "(N)" counts on the admin post list's status filter tabs, including the Trash tab.
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
     * Titles of every post using $mediaId as its featured image — used
     * by MediaUsageChecker to warn before deleting a referenced file.
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
     * Every distinct media id currently set as a post's featured image —
     * one bounded query rather than a per-media-id lookup, so it scales
     * with the number of posts, not the size of the media library.
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
     * Posts visible to public site visitors: published outright or due
     * (scheduled with a past published_at), never Private, never past
     * their unpublish_at time. Sticky posts are ordered first, then
     * newest-first — the archive methods below deliberately don't apply
     * sticky ordering, since a sticky post stays pinned to the front page only.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginatePublished(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = $this->publicWhereClause();
        $params = ['now' => $now, 'now_unpublish' => $now];

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE {$where}"
                . " ORDER BY is_sticky DESC, published_at DESC LIMIT {$perPage} OFFSET {$offset}",
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

        $where = $this->publicWhereClause('p');
        $join = 'INNER JOIN ' . $this->tablePrefix . 'post_categories pc ON pc.post_id = p.id AND pc.category_id = :category_id';
        $params = ['now' => $now, 'now_unpublish' => $now, 'category_id' => $categoryId];

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " p {$join} WHERE {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT p.* FROM ' . $this->table() . " p {$join} WHERE {$where}"
                . " ORDER BY p.published_at DESC LIMIT {$perPage} OFFSET {$offset}",
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
     * Posts visible to public site visitors that are assigned to $tagId.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateByTag(int $tagId, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = $this->publicWhereClause('p');
        $join = 'INNER JOIN ' . $this->tablePrefix . 'post_tags pt ON pt.post_id = p.id AND pt.tag_id = :tag_id';
        $params = ['now' => $now, 'now_unpublish' => $now, 'tag_id' => $tagId];

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " p {$join} WHERE {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT p.* FROM ' . $this->table() . " p {$join} WHERE {$where}"
                . " ORDER BY p.published_at DESC LIMIT {$perPage} OFFSET {$offset}",
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
     * Posts visible to public site visitors that share at least one tag
     * with $excludePostId, ranked by shared-tag count then recency — the
     * "Related Posts" block. Returns an empty array for a tagless post
     * without querying.
     *
     * @param array<int, int> $tagIds
     * @return array<int, Post>
     */
    public function relatedByTags(int $excludePostId, array $tagIds, int $limit = 5): array
    {
        if ($tagIds === []) {
            return [];
        }

        $limit = max(1, $limit);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $params = ['now' => $now, 'now_unpublish' => $now, 'exclude_id' => $excludePostId];
        $placeholders = [];

        foreach (array_values(array_unique($tagIds)) as $i => $tagId) {
            $key = "tag_id_{$i}";
            $placeholders[] = ':' . $key;
            $params[$key] = $tagId;
        }

        $where = $this->publicWhereClause('p');
        $join = 'INNER JOIN ' . $this->tablePrefix . 'post_tags pt ON pt.post_id = p.id'
            . ' AND pt.tag_id IN (' . implode(',', $placeholders) . ')';

        $rows = $this->database->fetchAll(
            'SELECT p.*, COUNT(pt.tag_id) AS shared_tag_count FROM ' . $this->table() . " p {$join}"
                . " WHERE {$where} AND p.id != :exclude_id"
                . ' GROUP BY p.id'
                . " ORDER BY shared_tag_count DESC, p.published_at DESC LIMIT {$limit}",
            $params,
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Posts visible to public site visitors written by $authorId (for
     * the REST API's ?author= filter) — no join needed since author_id
     * is a direct column.
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateByAuthor(int $authorId, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = 'author_id = :author_id AND ' . $this->publicWhereClause();
        $params = ['now' => $now, 'now_unpublish' => $now, 'author_id' => $authorId];

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
     * Posts visible to public site visitors published in a given calendar
     * month (for the Archives widget's monthly links and the
     * `/archive/{year}/{month}` route behind them).
     *
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateByMonth(int $year, int $month, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $where = $this->publicWhereClause()
            . ' AND YEAR(published_at) = :year AND MONTH(published_at) = :month';
        $params = ['now' => $now, 'now_unpublish' => $now, 'year' => $year, 'month' => $month];

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
     * Archives widget. Limited to $limit months so a long-running
     * blog doesn't render an unbounded link list.
     *
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function monthlyArchiveCounts(int $limit = 12): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $where = $this->publicWhereClause() . ' AND published_at IS NOT NULL';
        $limit = max(1, $limit);

        $rows = $this->database->fetchAll(
            'SELECT YEAR(published_at) AS y, MONTH(published_at) AS m, COUNT(*) AS c
               FROM ' . $this->table() . " WHERE {$where}
              GROUP BY y, m
              ORDER BY y DESC, m DESC
              LIMIT {$limit}",
            ['now' => $now, 'now_unpublish' => $now],
        );

        return array_map(
            static fn (array $row): array => ['year' => (int) $row['y'], 'month' => (int) $row['m'], 'count' => (int) $row['c']],
            $rows,
        );
    }

    /**
     * A flat {id, title, slug} list for the admin Menus screen's "Add
     * Posts" checkbox list, regardless of publish status — an editor may
     * knowingly link to a not-yet-published post. Trashed posts are excluded.
     *
     * @return array<int, array{id: int, title: string, slug: string}>
     */
    public function listAllForMenuSelect(int $limit = 200): array
    {
        $limit = max(1, $limit);

        $rows = $this->database->fetchAll(
            'SELECT id, title, slug FROM ' . $this->table() . " WHERE status != 'trashed' ORDER BY created_at DESC LIMIT {$limit}",
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug']],
            $rows,
        );
    }

    /**
     * Every post for the admin post list, filtered by status. With no
     * filter, Trashed posts are excluded from this "All" view; pass
     * PostStatus::Trashed explicitly to see the Trash list itself.
     *
     * @param array{term?: string, authorId?: int, categoryId?: int, tagId?: int, dateFrom?: string, dateTo?: string} $filters
     *     term: matches title (LIKE). authorId/categoryId/tagId: exact
     *     match. dateFrom/dateTo: 'Y-m-d' strings against created_at.
     * @return array{posts: array<int, Post>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?PostStatus $statusFilter = null, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $conditions = [];
        $params = [];

        if ($statusFilter !== null) {
            $conditions[] = 'p.status = :status';
            $params['status'] = $statusFilter->value;
        } else {
            $conditions[] = "p.status != 'trashed'";
        }

        $joins = '';

        if (($filters['term'] ?? '') !== '') {
            $conditions[] = 'p.title LIKE :term';
            $params['term'] = '%' . $filters['term'] . '%';
        }

        if (($filters['authorId'] ?? 0) > 0) {
            $conditions[] = 'p.author_id = :author_id';
            $params['author_id'] = (int) $filters['authorId'];
        }

        if (($filters['categoryId'] ?? 0) > 0) {
            $joins .= ' INNER JOIN ' . $this->tablePrefix . 'post_categories pc ON pc.post_id = p.id AND pc.category_id = :category_id';
            $params['category_id'] = (int) $filters['categoryId'];
        }

        if (($filters['tagId'] ?? 0) > 0) {
            $joins .= ' INNER JOIN ' . $this->tablePrefix . 'post_tags pt ON pt.post_id = p.id AND pt.tag_id = :tag_id';
            $params['tag_id'] = (int) $filters['tagId'];
        }

        // Filters/sorts by the post's own date, falling back to created_at when there's no publish date yet.
        if (($filters['dateFrom'] ?? '') !== '') {
            $conditions[] = 'COALESCE(p.published_at, p.created_at) >= :date_from';
            $params['date_from'] = $filters['dateFrom'] . ' 00:00:00';
        }

        if (($filters['dateTo'] ?? '') !== '') {
            $conditions[] = 'COALESCE(p.published_at, p.created_at) <= :date_to';
            $params['date_to'] = $filters['dateTo'] . ' 23:59:59';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " p{$joins} {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT p.* FROM ' . $this->table() . " p{$joins} {$where}"
                . " ORDER BY COALESCE(p.published_at, p.created_at) DESC LIMIT {$perPage} OFFSET {$offset}",
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
     * The exact set of conditions that makes a post visible to an
     * anonymous guest: published or due, publicly visible, and not past
     * unpublish_at. Every paginate*() method builds its WHERE clause
     * from this. $columnPrefix is the table alias to qualify each column
     * with, or '' for an unaliased query. Every caller must bind both
     * ':now' and ':now_unpublish' to the same value — two distinct
     * placeholders, since MySQL's real prepared statements reject a repeated named one.
     */
    private function publicWhereClause(string $columnPrefix = ''): string
    {
        $prefix = $columnPrefix !== '' ? $columnPrefix . '.' : '';

        return "({$prefix}status = 'published' OR ({$prefix}status = 'scheduled' AND {$prefix}published_at <= :now))"
            . " AND {$prefix}visibility = 'public'"
            . " AND ({$prefix}unpublish_at IS NULL OR {$prefix}unpublish_at > :now_unpublish)";
    }

    /**
     * A Draft carries published_at as a planned date, not an enforced
     * one — it has no effect on visibility, but lets an author note when
     * they intend to publish and survives a later Draft -> Scheduled
     * transition without re-entering the date.
     */
    private function resolvePublishedAt(
        PostStatus $status,
        ?DateTimeImmutable $publishedAt,
        DateTimeImmutable $now,
        ?DateTimeImmutable $existingPublishedAt = null,
    ): ?DateTimeImmutable {
        return match ($status) {
            PostStatus::Draft => $publishedAt ?? $existingPublishedAt,
            PostStatus::PendingReview, PostStatus::Trashed => null,
            PostStatus::Published => $publishedAt ?? $existingPublishedAt ?? $now,
            PostStatus::Scheduled => $publishedAt ?? $existingPublishedAt,
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
            metaTitle: isset($row['meta_title']) && $row['meta_title'] !== '' ? (string) $row['meta_title'] : null,
            metaDescription: isset($row['meta_description']) && $row['meta_description'] !== '' ? (string) $row['meta_description'] : null,
            visibility: PostVisibility::tryFrom((string) ($row['visibility'] ?? '')) ?? PostVisibility::Public,
            isSticky: (bool) ($row['is_sticky'] ?? false),
            unpublishAt: isset($row['unpublish_at']) ? new DateTimeImmutable((string) $row['unpublish_at']) : null,
        );
    }

    /**
     * Decodes the `featured_image_crop` column, rejecting anything that
     * isn't a well-formed {x,y,width,height} rectangle — a corrupted
     * value silently falls back to "no crop" rather than reaching
     * ThumbnailService with garbage coordinates.
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

    private function postMetaTable(): string
    {
        return $this->tablePrefix . 'post_meta';
    }
}
