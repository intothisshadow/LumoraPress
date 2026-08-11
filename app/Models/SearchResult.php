<?php

/**
 * One row of a SearchService result set, reduced to the fields a search results listing needs.
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

/**
 * One row of a SearchService result set — a Post, Page, Category, Tag, or
 * Author (`type` distinguishes which), reduced to the fields a search
 * results listing needs. Deliberately data-only, no URL-building method of
 * its own: templates call search_result_permalink() (LP-078,
 * include/permalink-functions.php) instead, since a 'post' result's link
 * must honor the configured permalink structure the same way
 * post_permalink() does everywhere else.
 *
 * featuredImageId mirrors Post/Page's own field (LP-040) so search results
 * can show a thumbnail and participate in the LP-031 lightbox the same way
 * other listings do — always null for Category/Tag/Author results, which
 * have no featured image concept.
 */
final class SearchResult
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $excerpt,
        public readonly ?int $featuredImageId,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly float $score,
    ) {
    }
}
