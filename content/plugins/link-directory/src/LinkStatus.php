<?php

/**
 * Whether a Link is live or trashed.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

/**
 * Never stored as its own column — Link::status() computes it from
 * $trashedAt, the same convention DownloadStatus uses.
 */
enum LinkStatus: string
{
    case Live = 'live';
    case Trashed = 'trashed';
}
