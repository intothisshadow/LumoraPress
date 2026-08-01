<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;

/**
 * Download counters for media (LP-006) — deliberately narrower than that
 * ticket's full wishlist: image "views" and audio/video "plays" both need
 * a public request to route through PHP to be countable at all, and
 * nothing in this app currently generates such a request (every `<img>`/
 * `<audio>`/`<video>` embed points straight at a static file under
 * content/uploads — see MediaService::url()). Rather than add PHP
 * overhead to every image render on every page (a real cost for a project
 * whose stated goal is fast shared-hosting performance), this service
 * only counts explicit "download" clicks — a distinct, deliberate action,
 * routed through the new /media/{id}/download endpoint
 * (SiteController::mediaDownload()) — for document/archive/audio/video
 * media. Image view counting and audio/video "plays" (as distinct from a
 * download) are deferred; both would need a content-pipeline change this
 * ticket doesn't otherwise require (see TODO.md's LP-006 entry).
 */
final class MediaStatsService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * A portable check-then-insert/update rather than a MySQL-only
     * `ON DUPLICATE KEY UPDATE` (unlike PressConfig::setOption()'s use of
     * that idiom) — this keeps recordDownload() exercisable against the
     * PHP Test Suite's SQLite-backed unit tests, not just the MySQL/
     * MariaDB integration suite. Two statements per call rather than one,
     * a fine tradeoff for a low-volume, explicit-click write path.
     */
    public function recordDownload(int $mediaId): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = ((int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE media_id = :media_id',
            ['media_id' => $mediaId],
        )) > 0;

        if ($exists) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET downloads = downloads + 1, last_downloaded_at = :now WHERE media_id = :media_id',
                ['now' => $now, 'media_id' => $mediaId],
            );
        } else {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (media_id, downloads, last_downloaded_at) VALUES (:media_id, 1, :now)',
                ['media_id' => $mediaId, 'now' => $now],
            );
        }
    }

    /**
     * @return array{downloads: int, lastDownloadedAt: ?DateTimeImmutable}
     */
    public function get(int $mediaId): array
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE media_id = :media_id',
            ['media_id' => $mediaId],
        );

        return [
            'downloads' => $row !== null ? (int) $row['downloads'] : 0,
            'lastDownloadedAt' => $row !== null && $row['last_downloaded_at'] !== null
                ? new DateTimeImmutable((string) $row['last_downloaded_at'])
                : null,
        ];
    }

    /**
     * Full media rows (same shape as MediaService::find()/query()'s
     * items, so admin/views/media.php's existing grid-rendering needs no
     * branching to handle these) with `downloads`/`last_downloaded_at`
     * merged in — LP-006's "Most Downloaded Files" built-in view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mostDownloaded(int $limit = 40): array
    {
        $limit = max(1, $limit);

        return $this->database->fetchAll(
            'SELECT m.*, s.downloads, s.last_downloaded_at
               FROM ' . $this->mediaTable() . ' m
               INNER JOIN ' . $this->table() . " s ON s.media_id = m.id
              WHERE s.downloads > 0
              ORDER BY s.downloads DESC, s.last_downloaded_at DESC
              LIMIT {$limit}",
        );
    }

    /**
     * LP-006's "Recently Downloaded" built-in view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyDownloaded(int $limit = 40): array
    {
        $limit = max(1, $limit);

        return $this->database->fetchAll(
            'SELECT m.*, s.downloads, s.last_downloaded_at
               FROM ' . $this->mediaTable() . ' m
               INNER JOIN ' . $this->table() . " s ON s.media_id = m.id
              WHERE s.last_downloaded_at IS NOT NULL
              ORDER BY s.last_downloaded_at DESC
              LIMIT {$limit}",
        );
    }

    /**
     * Downloadable-category media (never image — see class docblock) with
     * zero recorded downloads. LP-006's "Never Downloaded" built-in view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function neverDownloaded(int $limit = 40): array
    {
        $limit = max(1, $limit);

        return $this->database->fetchAll(
            'SELECT m.*, COALESCE(s.downloads, 0) AS downloads, s.last_downloaded_at
               FROM ' . $this->mediaTable() . ' m
               LEFT JOIN ' . $this->table() . " s ON s.media_id = m.id
              WHERE m.mime_type NOT LIKE 'image/%'
                AND (s.downloads IS NULL OR s.downloads = 0)
              ORDER BY m.uploaded_at DESC
              LIMIT {$limit}",
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'media_stats';
    }

    private function mediaTable(): string
    {
        return $this->tablePrefix . 'media';
    }
}
