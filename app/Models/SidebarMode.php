<?php

/**
 * A user's chosen admin sidebar layout (collapsed icon rail or classic full width).
 *
 * @package LumoraPress
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.26.0
 */

declare(strict_types=1);

namespace LumoraPress\Models;

/**
 * Stored as null on the account until the user chooses, so a person who has
 * only ever used the sidebar's toggle button keeps their per-browser choice
 * instead of being reset to the default.
 */
enum SidebarMode: string
{
    case Collapsed = 'collapsed';
    case Expanded = 'expanded';

    public function label(): string
    {
        return match ($this) {
            self::Collapsed => 'Collapsed (icons with flyout menus)',
            self::Expanded => 'Full width',
        };
    }
}
