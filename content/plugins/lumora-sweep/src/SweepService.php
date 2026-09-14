<?php

/**
 * Core logic for the bundled Lumora Sweep plugin: count/clean methods for every real database-cleanup category.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraSweep;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\RevisionableType;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\RevisionService;
use LumoraPress\Services\TagService;

/**
 * Every category here is real for Lumora Press's own schema, checked
 * against the actual migrations rather than ported 1:1 from WP-Sweep's
 * WordPress-shaped feature list (this plugin's own README explains what
 * was deliberately left out and why). Each category exposes a read-only
 * count method and a separate delete method, so a preview never risks
 * running a destructive query by accident.
 */
final class SweepService
{
    /** Table names this plugin ever cleans rows from — the only values optimizeTables() will ever run OPTIMIZE TABLE against. */
    private const OPTIMIZABLE_TABLES = ['revisions', 'posts', 'pages', 'comments', 'post_meta', 'categories', 'tags'];

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly CommentService $comments,
        private readonly RevisionService $revisions,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
    ) {
    }

    // -- Excess revisions ----------------------------------------------
    //
    // RevisionService already caps revisions per post/page at the
    // revision_retention option going forward (see its own prune()); this
    // is the retroactive one-time backfill for content that already had
    // more revisions than a since-lowered cap.

    public function countExcessRevisions(): int
    {
        return count($this->excessRevisionIds());
    }

    public function cleanExcessRevisions(): int
    {
        $ids = $this->excessRevisionIds();

        foreach ($ids as $id) {
            $this->database->execute('DELETE FROM ' . $this->table('revisions') . ' WHERE id = :id', ['id' => $id]);
        }

        return count($ids);
    }

    /**
     * @return array<int, int>
     */
    private function excessRevisionIds(): array
    {
        $keep = max(0, (int) $this->config->option('revision_retention', 25));

        if ($keep === 0) {
            // 0 means unlimited retention — mirrors RevisionService::prune().
            return [];
        }

        $rows = $this->database->fetchAll(
            'SELECT id, content_type, content_id FROM ' . $this->table('revisions') . '
                ORDER BY content_type ASC, content_id ASC, created_at DESC, id DESC',
        );

        $excessIds = [];
        $groupKey = null;
        $seenInGroup = 0;

        foreach ($rows as $row) {
            $key = $row['content_type'] . ':' . $row['content_id'];

            if ($key !== $groupKey) {
                $groupKey = $key;
                $seenInGroup = 0;
            }

            $seenInGroup++;

            if ($seenInGroup > $keep) {
                $excessIds[] = (int) $row['id'];
            }
        }

        return $excessIds;
    }

    // -- Trashed posts/pages older than N days --------------------------
    //
    // Posts/Pages already have their own unconditional "Empty Trash";
    // Sweep's value-add here is the age filter and the combined
    // cross-content-type count/trigger.

    public function countOldTrashedContent(int $days): int
    {
        return count($this->oldTrashedPostIds($days)) + count($this->oldTrashedPageIds($days));
    }

    public function cleanOldTrashedContent(int $days): int
    {
        $postIds = $this->oldTrashedPostIds($days);

        foreach ($postIds as $id) {
            $this->revisions->deleteAllFor(RevisionableType::Post, $id);
            $this->posts->delete($id);
        }

        $pageIds = $this->oldTrashedPageIds($days);

        foreach ($pageIds as $id) {
            $this->revisions->deleteAllFor(RevisionableType::Page, $id);
            $this->pages->delete($id);
        }

        return count($postIds) + count($pageIds);
    }

    /**
     * @return array<int, int>
     */
    private function oldTrashedPostIds(int $days): array
    {
        return $this->idsOlderThan('posts', "status = 'trashed'", 'trashed_at', $days);
    }

    /**
     * @return array<int, int>
     */
    private function oldTrashedPageIds(int $days): array
    {
        return $this->idsOlderThan('pages', "status = 'trashed'", 'trashed_at', $days);
    }

    // -- Spam/Trash comments older than N days --------------------------
    //
    // Comments have no trashed_at/spammed_at column — updated_at is
    // rewritten on every status change (CommentService::updateStatus()),
    // so it doubles as "how long has this comment been Spam/Trash."

    public function countOldSpamOrTrashComments(int $days): int
    {
        return count($this->oldSpamOrTrashCommentIds($days));
    }

    public function cleanOldSpamOrTrashComments(int $days): int
    {
        $ids = $this->oldSpamOrTrashCommentIds($days);

        foreach ($ids as $id) {
            $this->comments->delete($id);
        }

        return count($ids);
    }

    /**
     * @return array<int, int>
     */
    private function oldSpamOrTrashCommentIds(int $days): array
    {
        return $this->idsOlderThan('comments', "status IN ('spam', 'trash')", 'updated_at', $days);
    }

    /**
     * @return array<int, int>
     */
    private function idsOlderThan(string $table, string $whereClause, string $dateColumn, int $days): array
    {
        $cutoff = (new DateTimeImmutable())->modify('-' . max(0, $days) . ' days')->format('Y-m-d H:i:s');

        $rows = $this->database->fetchAll(
            "SELECT id FROM " . $this->table($table) . "
                WHERE {$whereClause} AND {$dateColumn} IS NOT NULL AND {$dateColumn} < :cutoff",
            ['cutoff' => $cutoff],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    // -- Orphaned post_meta rows -----------------------------------------
    //
    // A post_meta row whose post_id no longer references an existing post —
    // can genuinely occur from a raw DB operation bypassing PostService, or
    // from data older than a cascade-delete fix (PostService::delete()
    // itself does not currently clean up post_meta).

    public function countOrphanedPostMeta(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table('post_meta') . ' pm
                LEFT JOIN ' . $this->table('posts') . ' p ON pm.post_id = p.id
                WHERE p.id IS NULL',
        );
    }

    public function cleanOrphanedPostMeta(): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table('post_meta') . '
                WHERE post_id NOT IN (SELECT id FROM ' . $this->table('posts') . ')',
        );
    }

    // -- Duplicate post_meta rows -----------------------------------------
    //
    // Identical post_id + meta_key + meta_value — a plausible bug artifact,
    // safe to de-duplicate. The lowest id in each group is always kept.

    public function countDuplicatePostMeta(): int
    {
        return (int) $this->database->fetchColumn($this->duplicatePostMetaCountSql());
    }

    public function cleanDuplicatePostMeta(): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table('post_meta') . '
                WHERE id IN (SELECT id FROM (' . $this->duplicatePostMetaIdsSql() . ') AS lp_sweep_dupes)',
        );
    }

    private function duplicatePostMetaCountSql(): string
    {
        return 'SELECT COUNT(*) FROM (' . $this->duplicatePostMetaIdsSql() . ') AS lp_sweep_dupe_count';
    }

    /**
     * Every post_meta row that has an earlier-id twin sharing the same
     * post_id/meta_key/meta_value — i.e. every duplicate beyond the one
     * that gets kept. meta_value is NULL-safe compared (plain "=" never
     * matches NULL = NULL) so two legitimately-NULL duplicates still count.
     */
    private function duplicatePostMetaIdsSql(): string
    {
        $table = $this->table('post_meta');

        return "SELECT pm.id FROM {$table} pm
            WHERE EXISTS (
                SELECT 1 FROM {$table} pm2
                WHERE pm2.post_id = pm.post_id
                    AND pm2.meta_key = pm.meta_key
                    AND (pm2.meta_value = pm.meta_value OR (pm2.meta_value IS NULL AND pm.meta_value IS NULL))
                    AND pm2.id < pm.id
            )";
    }

    // -- Unused categories/tags (opt-in only, off by default) -------------
    //
    // An empty category/tag can be intentional (reserved for future
    // content), unlike an orphaned meta row which is never intentional —
    // the caller (the Sweep tab) gates whether this section is even shown
    // behind its own setting; these methods themselves have no opinion on
    // that gate.

    public function countUnusedCategories(): int
    {
        return (int) $this->database->fetchColumn($this->unusedCategoriesSql('COUNT(*)'));
    }

    public function cleanUnusedCategories(): int
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll($this->unusedCategoriesSql('c.id')),
        );

        $removed = 0;

        foreach ($ids as $id) {
            if ($this->categories->delete($id)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function unusedCategoriesSql(string $select): string
    {
        return "SELECT {$select} FROM " . $this->table('categories') . " c
            WHERE c.trashed_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM " . $this->table('post_categories') . " pc WHERE pc.category_id = c.id)";
    }

    public function countUnusedTags(): int
    {
        return (int) $this->database->fetchColumn($this->unusedTagsSql('COUNT(*)'));
    }

    public function cleanUnusedTags(): int
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll($this->unusedTagsSql('t.id')),
        );

        $removed = 0;

        foreach ($ids as $id) {
            if ($this->tags->delete($id)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function unusedTagsSql(string $select): string
    {
        return "SELECT {$select} FROM " . $this->table('tags') . " t
            WHERE t.trashed_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM " . $this->table('post_tags') . " pt WHERE pt.tag_id = t.id)";
    }

    // -- Table optimization ------------------------------------------------
    //
    // Run on whatever tables a category just cleaned. MySQL/MariaDB-only
    // (like the rest of this application's schema) — silently skipped if
    // the connection doesn't support it, so it can never turn a successful
    // cleanup into a failed one.

    /**
     * @param array<int, string> $tables bare table names, no prefix (e.g. 'revisions', 'post_meta')
     */
    public function optimizeTables(array $tables): void
    {
        foreach (array_intersect($tables, self::OPTIMIZABLE_TABLES) as $table) {
            try {
                $this->database->execute('OPTIMIZE TABLE ' . $this->table($table));
            } catch (\Throwable) {
                // Best-effort only — a storage engine or driver that
                // doesn't support OPTIMIZE TABLE (or SQLite in tests)
                // shouldn't turn a successful cleanup into an error.
            }
        }
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
