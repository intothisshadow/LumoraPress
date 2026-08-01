<?php

declare(strict_types=1);

namespace LumoraPress\Models;

/**
 * Which content table a revision snapshot belongs to. Revisions for Posts
 * and Pages share one {prefix}revisions table (content_type + content_id),
 * the same shape MediaUsageChecker already reasons about both content
 * types with, rather than two near-identical tables.
 */
enum RevisionableType: string
{
    case Post = 'post';
    case Page = 'page';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'Post',
            self::Page => 'Page',
        };
    }
}
