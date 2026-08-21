<?php

/**
 * Whether a Download's content is a locally-hosted file or an external URL.
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
 * Determines which of Download::$mediaId/$redirectId is set — a File
 * download's content lives on a MediaService-managed Media row, a Url
 * download's on a RedirectService-managed Redirect row (see
 * DownloadService's own docblock for why both are reused rather than
 * reimplemented here).
 */
enum DownloadType: string
{
    case File = 'file';
    case Url = 'url';
}
