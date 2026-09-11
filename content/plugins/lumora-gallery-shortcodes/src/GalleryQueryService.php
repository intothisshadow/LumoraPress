<?php

/**
 * Read-only queries against a separately-installed Lumora Gallery site's database, backing this plugin's shortcodes.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraGalleryShortcodes;

use LumoraPress\Core\Database\Database;
use Throwable;

/**
 * Every query here is scoped to `visibility = 0` (public) albums and `approved = 1` images
 * unconditionally, regardless of the inserting staff member's own Gallery permissions — this
 * plugin has no Gallery login concept and reads exactly what a logged-out visitor could see.
 *
 * Every public method swallows `Throwable` and returns an empty result rather than letting a
 * query failure surface as a fatal error on public pages, matching FolderGalleryShortcode's
 * precedent. `LIMIT` is always an `(int)`-cast, clamped value interpolated directly, never
 * bound, matching MediaService::query()'s convention (bound LIMIT is unreliable across PDO
 * drivers without extra ceremony).
 */
final class GalleryQueryService
{
    private const MAX_IMAGES = 200;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * @return array{id: int, folder: string, title: string}|null
     */
    public function findAlbum(?int $albumId, ?string $folder): ?array
    {
        try {
            if ($albumId !== null) {
                $row = $this->database->fetchOne(
                    'SELECT id, folder, title FROM ' . $this->albumsTable() . ' WHERE id = :id AND visibility = 0',
                    ['id' => $albumId],
                );
            } elseif ($folder !== null && $folder !== '') {
                $row = $this->database->fetchOne(
                    'SELECT id, folder, title FROM ' . $this->albumsTable() . ' WHERE folder = :folder AND visibility = 0',
                    ['folder' => $folder],
                );
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : ['id' => (int) $row['id'], 'folder' => (string) $row['folder'], 'title' => (string) $row['title']];
    }

    /**
     * Every public album, title-sorted — backs the Insert Shortcode
     * picker's `album_id` field so an admin chooses a real album by name
     * instead of typing its id by hand. Not used by
     * `GalleryShortcode` itself, which only ever resolves one album at a
     * time via `findAlbum()`.
     *
     * @return array<int, array{id: int, folder: string, title: string}>
     */
    public function listAlbums(): array
    {
        try {
            $rows = $this->database->fetchAll(
                'SELECT id, folder, title FROM ' . $this->albumsTable() . ' WHERE visibility = 0 ORDER BY title ASC',
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'folder' => (string) $row['folder'], 'title' => (string) $row['title']],
            $rows,
        );
    }

    /**
     * The public album a given image belongs to — used by
     * `GalleryShortcode` to resolve the "View album" link when
     * `image_id` was given with no explicit `album_id`/`folder` of its
     * own.
     *
     * @return array{id: int, folder: string, title: string}|null
     */
    public function findAlbumForImage(int $imageId): ?array
    {
        try {
            $row = $this->database->fetchOne(
                'SELECT a.id, a.folder, a.title FROM ' . $this->albumsTable() . ' a
                    INNER JOIN ' . $this->imagesTable() . ' i ON i.album_id = a.id
                    WHERE i.id = :image_id AND a.visibility = 0',
                ['image_id' => $imageId],
            );
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : ['id' => (int) $row['id'], 'folder' => (string) $row['folder'], 'title' => (string) $row['title']];
    }

    /**
     * Every approved image in one album, in the Gallery's own manual
     * ordering (`pos`), the same order the Gallery site itself displays
     * an album in.
     *
     * @return array<int, array{id: int, filename: string, title: string, width: int, height: int}>
     */
    public function imagesForAlbum(int $albumId): array
    {
        try {
            $rows = $this->database->fetchAll(
                'SELECT id, filename, title, width, height FROM ' . $this->imagesTable() . '
                    WHERE album_id = :album_id AND approved = 1
                 ORDER BY pos ASC, id ASC
                    LIMIT ' . self::MAX_IMAGES,
                ['album_id' => $albumId],
            );
        } catch (Throwable) {
            return [];
        }

        return array_map($this->hydrateImage(...), $rows);
    }

    /**
     * The newest `count` approved images from one album, newest first —
     * a distinct ordering from `imagesForAlbum()` above, deliberately:
     * "newest N" is meant to read most-recent-first, not in the album's
     * own manual display order.
     *
     * @return array<int, array{id: int, filename: string, title: string, width: int, height: int}>
     */
    public function newestInAlbum(int $albumId, int $count): array
    {
        $limit = max(1, min($count, self::MAX_IMAGES));

        try {
            $rows = $this->database->fetchAll(
                'SELECT id, filename, title, width, height FROM ' . $this->imagesTable() . '
                    WHERE album_id = :album_id AND approved = 1
                 ORDER BY added_at DESC, id DESC
                    LIMIT ' . $limit,
                ['album_id' => $albumId],
            );
        } catch (Throwable) {
            return [];
        }

        return array_map($this->hydrateImage(...), $rows);
    }

    /**
     * One or more specific approved images by id, each still required to belong to a public
     * album — joins to `albums` purely to enforce visibility, not to scope by one album.
     * $imageIds order is preserved in the result, not re-sorted by date/position.
     *
     * @param array<int, int> $imageIds
     * @return array<int, array{id: int, filename: string, title: string, width: int, height: int}>
     */
    public function imagesByIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $imageIds = array_slice($imageIds, 0, self::MAX_IMAGES);
        $placeholders = [];
        $params = [];

        foreach ($imageIds as $index => $imageId) {
            $key = "image_id{$index}";
            $placeholders[] = ":{$key}";
            $params[$key] = $imageId;
        }

        try {
            $rows = $this->database->fetchAll(
                'SELECT i.id, i.filename, i.title, i.width, i.height FROM ' . $this->imagesTable() . ' i
                    INNER JOIN ' . $this->albumsTable() . ' a ON a.id = i.album_id
                    WHERE i.approved = 1 AND a.visibility = 0 AND i.id IN (' . implode(',', $placeholders) . ')',
                $params,
            );
        } catch (Throwable) {
            return [];
        }

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $this->hydrateImage($row);
        }

        $ordered = [];

        foreach ($imageIds as $imageId) {
            if (isset($byId[$imageId])) {
                $ordered[] = $byId[$imageId];
            }
        }

        return $ordered;
    }

    /**
     * Every approved image across multiple public albums at once, grouped
     * by album (alphabetically by title) and then in that album's own
     * manual ordering (`pos`) within each group — the multi-album analog
     * of `imagesForAlbum()`. Non-public albums and unknown ids among
     * $albumIds are silently skipped rather than failing the whole call.
     *
     * @param array<int, int> $albumIds
     * @return array<int, array{id: int, filename: string, title: string, width: int, height: int, albumId: int, albumFolder: string}>
     */
    public function imagesForAlbums(array $albumIds): array
    {
        if ($albumIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->albumIdParams($albumIds);

        try {
            $rows = $this->database->fetchAll(
                'SELECT i.id, i.filename, i.title, i.width, i.height, i.album_id, a.folder AS album_folder
                    FROM ' . $this->imagesTable() . ' i
                    INNER JOIN ' . $this->albumsTable() . ' a ON a.id = i.album_id
                    WHERE i.approved = 1 AND a.visibility = 0 AND i.album_id IN (' . implode(',', $placeholders) . ')
                 ORDER BY a.title ASC, i.pos ASC, i.id ASC
                    LIMIT ' . self::MAX_IMAGES,
                $params,
            );
        } catch (Throwable) {
            return [];
        }

        return array_map($this->hydrateMultiAlbumImage(...), $rows);
    }

    /**
     * The newest `count` approved images across multiple public albums at
     * once, newest first — the multi-album analog of `newestInAlbum()`.
     *
     * @param array<int, int> $albumIds
     * @return array<int, array{id: int, filename: string, title: string, width: int, height: int, albumId: int, albumFolder: string}>
     */
    public function newestInAlbums(array $albumIds, int $count): array
    {
        if ($albumIds === []) {
            return [];
        }

        $limit = max(1, min($count, self::MAX_IMAGES));
        [$placeholders, $params] = $this->albumIdParams($albumIds);

        try {
            $rows = $this->database->fetchAll(
                'SELECT i.id, i.filename, i.title, i.width, i.height, i.album_id, a.folder AS album_folder
                    FROM ' . $this->imagesTable() . ' i
                    INNER JOIN ' . $this->albumsTable() . ' a ON a.id = i.album_id
                    WHERE i.approved = 1 AND a.visibility = 0 AND i.album_id IN (' . implode(',', $placeholders) . ')
                 ORDER BY i.added_at DESC, i.id DESC
                    LIMIT ' . $limit,
                $params,
            );
        } catch (Throwable) {
            return [];
        }

        return array_map($this->hydrateMultiAlbumImage(...), $rows);
    }

    /**
     * The newest `count` approved images across every public album,
     * gallery-wide.
     *
     * @return array<int, array{id: int, filename: string, title: string, width: int, height: int, albumId: int, albumFolder: string}>
     */
    public function newestAcrossGallery(int $count): array
    {
        $limit = max(1, min($count, self::MAX_IMAGES));

        try {
            $rows = $this->database->fetchAll(
                'SELECT i.id, i.filename, i.title, i.width, i.height, i.album_id, a.folder AS album_folder
                    FROM ' . $this->imagesTable() . ' i
                    INNER JOIN ' . $this->albumsTable() . ' a ON a.id = i.album_id
                    WHERE i.approved = 1 AND a.visibility = 0
                 ORDER BY i.added_at DESC, i.id DESC
                    LIMIT ' . $limit,
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'filename' => (string) $row['filename'],
                'title' => (string) $row['title'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'albumId' => (int) $row['album_id'],
                'albumFolder' => (string) $row['album_folder'],
            ],
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, filename: string, title: string, width: int, height: int}
     */
    private function hydrateImage(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'filename' => (string) $row['filename'],
            'title' => (string) $row['title'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, filename: string, title: string, width: int, height: int, albumId: int, albumFolder: string}
     */
    private function hydrateMultiAlbumImage(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'filename' => (string) $row['filename'],
            'title' => (string) $row['title'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'albumId' => (int) $row['album_id'],
            'albumFolder' => (string) $row['album_folder'],
        ];
    }

    /**
     * Builds the `IN (...)` placeholder list and bound params shared by
     * `imagesForAlbums()`/`newestInAlbums()` — same named-placeholder
     * approach as `imagesByIds()`'s own `$imageIds` handling.
     *
     * @param array<int, int> $albumIds
     * @return array{0: array<int, string>, 1: array<string, int>}
     */
    private function albumIdParams(array $albumIds): array
    {
        $albumIds = array_slice(array_unique($albumIds), 0, self::MAX_IMAGES);
        $placeholders = [];
        $params = [];

        foreach ($albumIds as $index => $albumId) {
            $key = "album_id{$index}";
            $placeholders[] = ":{$key}";
            $params[$key] = $albumId;
        }

        return [$placeholders, $params];
    }

    private function albumsTable(): string
    {
        return $this->tablePrefix . 'albums';
    }

    private function imagesTable(): string
    {
        return $this->tablePrefix . 'images';
    }
}
