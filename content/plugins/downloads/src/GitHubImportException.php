<?php

/**
 * A failure talking to GitHub that is safe to show to the person importing.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use RuntimeException;

/**
 * The message is written for the admin screen, so it never carries a response body, header, or path.
 */
final class GitHubImportException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const RATE_LIMITED = 'rate_limited';

    public const UNREACHABLE = 'unreachable';

    public const MOVED = 'moved';

    public const INVALID = 'invalid';

    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
