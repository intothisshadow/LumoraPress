<?php

/**
 * A Page's visibility (Public or Private), independent of its publish status.
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

/**
 * Independent of PageStatus — a page can be Published and Private at the
 * same time (visible to logged-in staff, never to a guest or search
 * engine) — mirrors PostVisibility exactly, see Page::isPubliclyVisible()/
 * isVisibleToViewer() and PageService's public query methods, which AND a
 * status check with a visibility check rather than folding privacy into
 * the status enum itself.
 */
enum PageVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Private',
        };
    }
}
