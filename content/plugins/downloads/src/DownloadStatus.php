<?php

/**
 * Whether a Download is live or trashed.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

/**
 * Unlike PostStatus/PageStatus, a Download has no Draft/Published distinction.
 * Never stored as its own column — Download::status() computes it from $trashedAt.
 */
enum DownloadStatus: string
{
    case Live = 'live';
    case Trashed = 'trashed';
}
