<?php

/**
 * The SQL conditions that make a post or page visible to a signed-out visitor.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Search;

/**
 * Shared by every search-side query so search results, suggestions, and the
 * search word list can never disagree about what is public. Mirrors
 * PostService's/PageService's own guest-visibility rules.
 */
final class PublicContent
{
    /**
     * Published or due, public, and not past unpublish_at. The caller binds
     * both :now and :now_unpublish to the current time (MySQL's native
     * prepared statements reject a repeated named placeholder).
     */
    public static function postWhere(string $alias = 'p'): string
    {
        return "({$alias}.status = 'published' OR ({$alias}.status = 'scheduled' AND {$alias}.published_at <= :now))"
            . " AND {$alias}.visibility = 'public'"
            . " AND ({$alias}.unpublish_at IS NULL OR {$alias}.unpublish_at > :now_unpublish)";
    }

    /**
     * Published or due, and public. The caller binds :now.
     */
    public static function pageWhere(string $alias = 'p'): string
    {
        return "({$alias}.status = 'published' OR ({$alias}.status = 'scheduled' AND {$alias}.published_at <= :now))"
            . " AND {$alias}.visibility = 'public'";
    }
}
