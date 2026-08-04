<?php

declare(strict_types=1);

namespace LumoraPress\Models;

/**
 * Independent of PostStatus — a post can be Published and Private at the
 * same time (visible to logged-in staff, never to a guest or search
 * engine) — see Post::isPubliclyVisible()/PostService's public query
 * methods, which all AND a status check with a visibility check rather
 * than folding privacy into the status enum itself.
 */
enum PostVisibility: string
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
