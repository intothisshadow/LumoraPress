<?php

/**
 * Provenance tracking for bulk-created content (LPP-004/LPP-005 Phase 1): which posts/pages/users/media/comments came from which import or generation batch.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;

/**
 * A plain bookkeeping table (`content_import_records`) recording which
 * content rows a bulk operation created, grouped by a `batch_id` and
 * tagged with a free-form `source` string (e.g. `'dummy_content'`,
 * `'wxr_import'`). Deliberately has no dependency on
 * PostService/PageService/UserService/MediaService/CommentService — it
 * only ever stores/queries ids, never deletes content itself. The caller
 * (e.g. a plugin's generator/importer class, which already holds
 * references to those services because it used them to create the
 * content in the first place) is responsible for actually deleting rows
 * via each service's own delete()/trash() method, then calling
 * clearBatch() to drop the now-stale bookkeeping rows — keeping deletion
 * on the same validated path every other deletion in this codebase
 * already uses, rather than this class reaching into content tables
 * directly.
 *
 * `external_id` is an optional free-form string (a WXR post's original
 * numeric WordPress id, for instance) so a future importer can look up
 * "what did WordPress post #123 become in this install" — every content
 * table here is plain AUTO_INCREMENT with no way to force-preserve an
 * original id, so this is the only place that mapping is recoverable
 * from.
 */
final class ContentImportRegistry
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * A fresh random token to group one generate/import run's records
     * under — no row is written until the first record() call, so an
     * unused batch id never lingers in the table.
     */
    public function newBatch(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function record(string $batchId, string $source, string $contentType, int $contentId, ?string $externalId = null): void
    {
        $this->database->execute(
            'INSERT INTO ' . $this->table() . '
                (batch_id, source, content_type, content_id, external_id, created_at)
             VALUES (:batch_id, :source, :content_type, :content_id, :external_id, :created_at)',
            [
                'batch_id' => $batchId,
                'source' => $source,
                'content_type' => $contentType,
                'content_id' => $contentId,
                'external_id' => $externalId,
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Every {contentType, contentId} recorded under $batchId, optionally
     * narrowed to one content type — the caller loops these and deletes
     * each via the matching service (PostService::delete(), etc.), in
     * whatever order avoids leaving dangling references (e.g. posts
     * before the users who authored them, if both are being removed).
     *
     * @return array<int, array{contentType: string, contentId: int}>
     */
    public function idsForBatch(string $batchId, ?string $contentType = null): array
    {
        $sql = 'SELECT content_type, content_id FROM ' . $this->table() . ' WHERE batch_id = :batch_id';
        $params = ['batch_id' => $batchId];

        if ($contentType !== null) {
            $sql .= ' AND content_type = :content_type';
            $params['content_type'] = $contentType;
        }

        $rows = $this->database->fetchAll($sql, $params);

        return array_map(
            static fn (array $row): array => ['contentType' => (string) $row['content_type'], 'contentId' => (int) $row['content_id']],
            $rows,
        );
    }

    /**
     * Resolves an external id (e.g. a WXR post's original WordPress id)
     * back to the local id it became when imported under $batchId — null
     * if that external id was never recorded for this batch/content type.
     */
    public function newIdForExternalId(string $batchId, string $contentType, string $externalId): ?int
    {
        $row = $this->database->fetchOne(
            'SELECT content_id FROM ' . $this->table() . '
                WHERE batch_id = :batch_id AND content_type = :content_type AND external_id = :external_id',
            ['batch_id' => $batchId, 'content_type' => $contentType, 'external_id' => $externalId],
        );

        return $row !== null ? (int) $row['content_id'] : null;
    }

    /**
     * Row counts per content type for $batchId — backs an admin screen's
     * "Last generated: 50 posts, 10 pages, ..." summary.
     *
     * @return array<string, int>
     */
    public function countsForBatch(string $batchId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT content_type, COUNT(*) AS c FROM ' . $this->table() . ' WHERE batch_id = :batch_id GROUP BY content_type',
            ['batch_id' => $batchId],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['content_type']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * When $batchId's first record was written — backs an admin screen's
     * "Last generated: ... at {time}" summary alongside countsForBatch().
     */
    public function batchCreatedAt(string $batchId): ?DateTimeImmutable
    {
        $value = $this->database->fetchColumn(
            'SELECT MIN(created_at) FROM ' . $this->table() . ' WHERE batch_id = :batch_id',
            ['batch_id' => $batchId],
        );

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * Every distinct batch id ever recorded for $source, newest first —
     * a "Remove all generated content" action needs every batch a source
     * has ever created, not just the most recent one, in case a previous
     * run's rows were never cleared.
     *
     * @return array<int, string>
     */
    public function batchesForSource(string $source): array
    {
        $rows = $this->database->fetchAll(
            'SELECT DISTINCT batch_id, MAX(created_at) AS last_created_at FROM ' . $this->table() . '
                WHERE source = :source
             GROUP BY batch_id
             ORDER BY last_created_at DESC',
            ['source' => $source],
        );

        return array_map(static fn (array $row): string => (string) $row['batch_id'], $rows);
    }

    /**
     * Removes every bookkeeping row for $batchId — called after the
     * caller has already deleted the real content rows those records
     * pointed at, so tracking doesn't outlive what it was tracking.
     */
    public function clearBatch(string $batchId): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE batch_id = :batch_id',
            ['batch_id' => $batchId],
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'content_import_records';
    }
}
