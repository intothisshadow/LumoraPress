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
 * Author (`type` distinguishes which). Deliberately no URL-building method
 * of its own: templates call search_result_permalink() instead, so a
 * 'post' result's link honors the configured permalink structure.
 *
 * featuredImageId is always null for Category/Tag/Author results. `url`
 * is only set by a plugin search provider whose results don't map onto
 * a core permalink; search_result_permalink() prefers it when present.
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
        public readonly ?string $url = null,
    ) {
    }

    public function withScore(float $score): self
    {
        return new self(
            $this->type,
            $this->id,
            $this->title,
            $this->slug,
            $this->excerpt,
            $this->featuredImageId,
            $this->publishedAt,
            $score,
            $this->url,
        );
    }
}
