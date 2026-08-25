<?php

/**
 * Creates, skips, or overwrites a Page from an ImportedPage DTO, resolving its parent through a caller-maintained id map and recording provenance (LPP-004/LPP-005 Phase 1).
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

namespace LumoraPress\Services\Import;

use LumoraPress\Models\Page;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\PageService;
use RuntimeException;

/**
 * Ordering contract: pages must be imported parent-before-child. The
 * caller maintains its own `array<string, int>` map of external id =>
 * local page id (keyed by whatever string scheme ImportedPage::$externalId
 * uses) and grows it after every import() call — `$map[$data->externalId]
 * = $page->id` — then passes the up-to-date map into each subsequent
 * call so a child page's $parentExternalId resolves correctly. A parent
 * id that isn't in the map yet (out-of-order data, or a parent outside
 * this batch) resolves to null — the page is created top-level rather
 * than failing the whole import.
 */
final class PageImporter
{
    public function __construct(
        private readonly PageService $pages,
        private readonly ContentImportRegistry $registry,
    ) {
    }

    /**
     * $existingContentMode is null on every call site except a
     * deliberate re-import against a source already imported once
     * before — see ExistingContentMode's own docblock and
     * PostImporter::import()'s identical shape.
     *
     * @param array<string, int> $externalIdToLocalId
     */
    public function import(string $batchId, string $source, ImportedPage $data, array $externalIdToLocalId = [], ?ExistingContentMode $existingContentMode = null): Page
    {
        $parentId = $data->parentExternalId !== null
            ? ($externalIdToLocalId[$data->parentExternalId] ?? null)
            : null;

        $existingId = $existingContentMode !== null && $data->externalId !== null
            ? $this->registry->existingLocalId($source, 'page', $data->externalId)
            : null;

        if ($existingId !== null) {
            $existing = $this->pages->findById($existingId);

            if ($existing === null) {
                throw new RuntimeException("Page external id \"{$data->externalId}\" was previously imported as #{$existingId}, but that page no longer exists.");
            }

            if ($existingContentMode === ExistingContentMode::Skip) {
                return $existing;
            }

            return $this->pages->update(
                id: $existingId,
                title: $data->title,
                content: $data->content,
                excerpt: $data->excerpt,
                status: $data->status,
                publishedAt: $data->publishedAt,
                parentId: $parentId,
                featuredImageId: $data->featuredImageId,
                slug: $data->slug,
                contentFormat: $data->contentFormat,
                featuredImageCrop: $data->featuredImageCrop,
                // update()'s own $commentsOpen has no "keep existing"
                // fallback (always defaults to true when omitted) —
                // ImportedPage carries no commentsOpen field of its own
                // to overwrite it with, so the row's current value is
                // explicitly preserved instead of silently reopening
                // comments an admin had closed locally.
                commentsOpen: $existing->commentsOpen,
            );
        }

        $page = $this->pages->create(
            title: $data->title,
            content: $data->content,
            excerpt: $data->excerpt,
            authorId: $data->authorId,
            status: $data->status,
            publishedAt: $data->publishedAt,
            parentId: $parentId,
            featuredImageId: $data->featuredImageId,
            slug: $data->slug,
            contentFormat: $data->contentFormat,
            featuredImageCrop: $data->featuredImageCrop,
        );

        $this->registry->record($batchId, $source, 'page', $page->id, $data->externalId);

        return $page;
    }
}
