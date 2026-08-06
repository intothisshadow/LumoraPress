<?php

/**
 * The lifecycle states a Post can be in (Draft, Published, Scheduled, etc.).
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
 * A scheduled post becomes publicly visible once its published_at time has
 * passed, without any change to its stored status — see
 * PostService::isVisible().
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Scheduled = 'scheduled';
    case Trashed = 'trashed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending Review',
            self::Published => 'Published',
            self::Scheduled => 'Scheduled',
            self::Trashed => 'Trash',
        };
    }
}
