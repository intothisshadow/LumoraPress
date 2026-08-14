<?php

/**
 * A plain data holder describing one page to create via PageImporter (LPP-004/LPP-005 Phase 1), independent of where the data came from.
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

use DateTimeImmutable;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\PageStatus;

/**
 * $parentExternalId (not a local id) is deliberate: pages within one
 * import batch are typically created in one pass before every parent's
 * real local id is known to every child, so PageImporter::import() takes
 * a separate $externalIdToLocalId map and resolves this field through it
 * at call time — see that class's own ordering-contract docblock.
 */
final class ImportedPage
{
    /**
     * @param array{x: int, y: int, width: int, height: int}|null $featuredImageCrop
     */
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly int $authorId,
        public readonly PageStatus $status,
        public readonly ?DateTimeImmutable $publishedAt = null,
        public readonly ?string $parentExternalId = null,
        public readonly ?int $featuredImageId = null,
        public readonly ?string $slug = null,
        public readonly ContentFormat $contentFormat = ContentFormat::Markdown,
        public readonly ?array $featuredImageCrop = null,
        /**
         * See ImportedPost::$externalId's docblock — same purpose, for
         * a page's own id and for resolving other pages' parentExternalId
         * against it.
         */
        public readonly ?string $externalId = null,
    ) {
    }
}
