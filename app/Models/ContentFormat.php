<?php

declare(strict_types=1);

namespace LumoraPress\Models;

/**
 * How a post/page's stored `content` column should be interpreted before
 * it's ever shown anywhere — see LumoraPress\Services\ContentRenderer,
 * the single place that branches on this value. `Plain` is the pre-LP-015
 * default (existing rows keep rendering exactly as before: nl2br() over
 * escaped text) so migrating in this column never changes how any
 * already-published post looks.
 */
enum ContentFormat: string
{
    case Markdown = 'markdown';
    case Html = 'html';
    case Plain = 'plain';

    public function label(): string
    {
        return match ($this) {
            self::Markdown => 'Markdown',
            self::Html => 'Visual / HTML',
            self::Plain => 'Plain text',
        };
    }
}
