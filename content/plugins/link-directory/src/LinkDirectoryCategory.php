<?php

/**
 * A Link Directory category (with optional sub-categories), the taxonomy links are filed under.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use DateTimeImmutable;

final class LinkDirectoryCategory
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?DateTimeImmutable $trashedAt = null,
    ) {
    }

    public function isTrashed(): bool
    {
        return $this->trashedAt !== null;
    }
}
