<?php

/**
 * Finds the files and links filed in a Media folder named like a download category.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use LumoraPress\Core\Database\Database;

/**
 * Downloads migrated from WordPress sit in the Media Library, in a folder named after their old
 * category, and may have no Download row at all. This reads those folders directly rather than
 * through the WordPress Importer plugin, so the Downloads plugin never depends on it. Anything a
 * Download row already points at is left out, so a download is never listed twice.
 */
final class ImportedDownloadsFinder
{
    private const LIMIT = 500;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * Folders are matched by name, ignoring case, and every folder with that name counts.
     *
     * @return array<int, array{name: string, url: string, sizeBytes: ?int, description: string}>
     */
    public function forCategoryName(string $categoryName): array
    {
        $folderIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll(
                'SELECT id FROM ' . $this->prefixed('media_folders') . ' WHERE LOWER(name) = :name',
                ['name' => mb_strtolower(trim($categoryName))],
            ),
        );

        if ($folderIds === []) {
            return [];
        }

        // Folder ids are integers read back from the database, so listing them inline is safe.
        $folderList = implode(',', $folderIds);
        $items = [];

        $files = $this->database->fetchAll(
            'SELECT id, file_name, file_size, description FROM ' . $this->prefixed('media')
                . ' WHERE folder_id IN (' . $folderList . ') AND id NOT IN (SELECT media_id FROM ' . $this->prefixed('downloads') . ' WHERE media_id IS NOT NULL)'
                . ' ORDER BY file_name ASC LIMIT ' . self::LIMIT,
        );

        foreach ($files as $file) {
            $items[] = [
                'name' => (string) $file['file_name'],
                'url' => site_url('media/' . (int) $file['id'] . '/download'),
                'sizeBytes' => (int) $file['file_size'],
                'description' => trim((string) ($file['description'] ?? '')),
            ];
        }

        $links = $this->database->fetchAll(
            'SELECT id, source_path FROM ' . $this->prefixed('redirects')
                . ' WHERE folder_id IN (' . $folderList . ') AND id NOT IN (SELECT redirect_id FROM ' . $this->prefixed('downloads') . ' WHERE redirect_id IS NOT NULL)'
                . ' ORDER BY source_path ASC LIMIT ' . self::LIMIT,
        );

        foreach ($links as $link) {
            $items[] = [
                'name' => ucwords(str_replace('-', ' ', basename((string) $link['source_path']))),
                'url' => site_url((string) $link['source_path']),
                'sizeBytes' => null,
                'description' => '',
            ];
        }

        return $items;
    }

    private function prefixed(string $table): string
    {
        return $this->tablePrefix . $table;
    }
}
