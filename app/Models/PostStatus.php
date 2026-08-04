<?php

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
