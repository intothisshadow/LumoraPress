<?php

/**
 * A single stored snapshot of a Post/Page body, for the Revisions feature (LP-017).
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

final class Revision
{
    public function __construct(
        public readonly int $id,
        public readonly RevisionableType $contentType,
        public readonly int $contentId,
        public readonly string $title,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly ContentFormat $contentFormat,
        public readonly int $authorId,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
