<?php

/**
 * The virtual media Folder domain model (LP-005).
 *
 * @package LumoraPress
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

final class Folder
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
