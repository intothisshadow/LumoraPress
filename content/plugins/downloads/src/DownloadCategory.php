<?php

/**
 * A Download's own category — a dedicated taxonomy, separate from Media Manager Folders.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use DateTimeImmutable;

final class DownloadCategory
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
