<?php

/**
 * Exports and imports this installation's Link Directory categories and links as a single JSON file, for copying a directory between two Lumora Press installs.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.17.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use LumoraPress\Models\ContentFormat;
use RuntimeException;

/**
 * Deliberately simpler than SettingsPortabilityService's own stage()/
 * inspectStaged()/finalize() upload flow (app/Services/SettingsPortabilityService.php):
 * importing here only ever adds new categories/links, never overwrites an
 * existing option value, so there's nothing destructive a confirmation
 * screen needs to guard against — one direct import() call is enough.
 *
 * A category's own id is included in the export purely so import() can
 * rebuild parent/child relationships between categories in the *same*
 * file — it's discarded once that remapping is done and never matched
 * against ids already on the destination install. thumbnailMediaId is
 * left out entirely, the same "local reference meaningless on another
 * install" reasoning SettingsPortabilityService's class docblock gives
 * for skipping homepage_page_id/avatar_default_media_id.
 */
final class LinkDirectoryPortabilityService
{
    private const FORMAT_VERSION = 1;

    public function __construct(
        private readonly LinkDirectoryCategoryService $categories,
        private readonly LinkService $links,
        private readonly string $appVersion,
    ) {
    }

    public function export(): string
    {
        $categoryRows = array_map(
            static fn (array $row): array => [
                'id' => $row['category']->id,
                'name' => $row['category']->name,
                'parentId' => $row['category']->parentId,
            ],
            $this->categories->listAllForTree(),
        );

        $linkRows = array_map(
            static fn (Link $link): array => [
                'title' => $link->title,
                'url' => $link->url,
                'description' => $link->description,
                'descriptionFormat' => $link->descriptionFormat->value,
                'categoryId' => $link->categoryId,
            ],
            $this->links->listAllForExport(),
        );

        $payload = [
            'lumoraPressLinkDirectoryExport' => true,
            'formatVersion' => self::FORMAT_VERSION,
            'exportedAt' => date('c'),
            'exportedFromVersion' => $this->appVersion,
            'categories' => $categoryRows,
            'links' => $linkRows,
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Creates every category and link in $json on this install. Categories
     * are processed in the export's own order — listAllForTree() (the
     * export's data source) always lists a parent before its children, so
     * by the time a child row is reached, $idMap already has its parent's
     * freshly-created id. A parent id the map doesn't recognize (a
     * hand-edited or truncated file) falls back to a top-level category
     * rather than failing the whole import.
     *
     * @return array{categoriesCreated: int, linksCreated: int}
     */
    public function import(string $json): array
    {
        $decoded = json_decode($json, true);

        if (
            !is_array($decoded)
            || ($decoded['lumoraPressLinkDirectoryExport'] ?? false) !== true
            || !isset($decoded['categories']) || !is_array($decoded['categories'])
            || !isset($decoded['links']) || !is_array($decoded['links'])
        ) {
            throw new RuntimeException('That file is not a valid Link Directory export.');
        }

        /** @var array<int, int> $idMap exported category id => newly-created id */
        $idMap = [];
        $categoriesCreated = 0;

        foreach ($decoded['categories'] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || trim($row['name']) === '') {
                continue;
            }

            $oldParentId = $row['parentId'] ?? null;
            $newParentId = is_int($oldParentId) && isset($idMap[$oldParentId]) ? $idMap[$oldParentId] : null;

            $created = $this->categories->create(trim($row['name']), $newParentId);
            $categoriesCreated++;

            if (is_int($row['id'] ?? null)) {
                $idMap[$row['id']] = $created->id;
            }
        }

        $linksCreated = 0;

        foreach ($decoded['links'] as $row) {
            if (
                !is_array($row)
                || !is_string($row['title'] ?? null) || trim($row['title']) === ''
                || !is_string($row['url'] ?? null) || trim($row['url']) === ''
            ) {
                continue;
            }

            $oldCategoryId = $row['categoryId'] ?? null;
            $newCategoryId = is_int($oldCategoryId) && isset($idMap[$oldCategoryId]) ? $idMap[$oldCategoryId] : null;
            $format = ContentFormat::tryFrom((string) ($row['descriptionFormat'] ?? '')) ?? ContentFormat::Plain;

            $this->links->create(
                trim($row['title']),
                trim($row['url']),
                (string) ($row['description'] ?? ''),
                $format,
                $newCategoryId,
                null,
            );
            $linksCreated++;
        }

        return ['categoriesCreated' => $categoriesCreated, 'linksCreated' => $linksCreated];
    }
}
