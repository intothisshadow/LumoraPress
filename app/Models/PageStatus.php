<?php

declare(strict_types=1);

namespace LumoraPress\Models;

/**
 * A scheduled page becomes publicly visible once its published_at time has
 * passed, without any change to its stored status — see
 * PageService::isVisible()/Page::isPubliclyVisible().
 */
enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Scheduled => 'Scheduled',
        };
    }
}
