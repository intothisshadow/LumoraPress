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
 * Unlike PostStatus/PageStatus, a Download has no Draft/Published
 * distinction — there's nothing to "publish" later, a download either
 * exists and is live or it doesn't (see LPP-009's design decision).
 * This is never stored as its own column: Download::status() computes
 * it from $trashedAt, the same way the underlying `downloads` table
 * only gained a `trashed_at` column, not a `status` one.
 */
enum DownloadStatus: string
{
    case Live = 'live';
    case Trashed = 'trashed';
}
