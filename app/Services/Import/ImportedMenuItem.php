<?php

/**
 * A plain data holder describing one nav menu item to create via MenuImporter, independent of where the data came from.
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

/**
 * Already-resolved, source-agnostic — mirrors ImportedPage/ImportedPost's
 * own shape. A source importer (e.g. WordPressImportService) is
 * responsible for turning whatever type/object reference its own format
 * uses (a WordPress menu item can point at a custom URL, a post, a page,
 * or a taxonomy term) into a plain $label/$url pair *before* building
 * this DTO — MenuManager's own item shape has no such distinction either
 * (see its own docblock), so there is nothing WordPress-specific left for
 * MenuImporter to resolve.
 *
 * $parentExternalId (not a local item id) is deliberate, mirroring
 * ImportedPage::$parentExternalId — MenuImporter resolves it through a
 * multi-pass, incrementally-grown externalId => local item id map, since
 * a source's own ordering doesn't guarantee parent-before-child.
 */
final class ImportedMenuItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly int $order,
        public readonly string $target = '_self',
        public readonly string $cssClass = '',
        public readonly string $rel = '',
        public readonly string $titleAttribute = '',
        public readonly ?string $parentExternalId = null,
        public readonly ?string $externalId = null,
    ) {
    }
}
