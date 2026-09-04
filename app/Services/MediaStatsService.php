<?php

/**
 * Download counters for media.
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

/**
 * Download counters for media — narrower than a full "views/plays" tracker, since every
 * `<img>`/`<audio>`/`<video>` embed points straight at a static file, generating no
 * countable PHP request. Rather than add PHP overhead to every image render, this service
 * only counts explicit "download" clicks through /media/{id}/download for document/archive/
 * audio/video media. Image views and audio/video "plays" are deferred.
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
     * Sets a media item's download count directly, rather than
     * incrementing it — for preserving a historical count from an
     * external source (e.g. a WordPress import, seeding a
     * migrated download's count from Simple Download Monitor's own
     * total) instead of every migrated item silently restarting at 0.
     * Overwrites any existing row's count outright; not meant to be
     * called from the real /media/{id}/download click path, which stays
     * on recordDownload()'s increment.
     */
    public function seed(int $mediaId, int $count, ?DateTimeImmutable $lastDownloadedAt = null): void
    {
        $exists = ((int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE media_id = :media_id',
            ['media_id' => $mediaId],
        )) > 0;

        $lastDownloadedAtValue = $lastDownloadedAt?->format('Y-m-d H:i:s');

        if ($exists) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET downloads = :downloads, last_downloaded_at = :last_downloaded_at WHERE media_id = :media_id',
                ['downloads' => $count, 'last_downloaded_at' => $lastDownloadedAtValue, 'media_id' => $mediaId],
            );
        } else {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (media_id, downloads, last_downloaded_at) VALUES (:media_id, :downloads, :last_downloaded_at)',
                ['media_id' => $mediaId, 'downloads' => $count, 'last_downloaded_at' => $lastDownloadedAtValue],
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
     * merged in — the "Most Downloaded Files" built-in view.
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
     * The "Recently Downloaded" built-in view.
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
     * zero recorded downloads. The "Never Downloaded" built-in view.
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
