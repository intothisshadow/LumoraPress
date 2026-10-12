<?php

/**
 * Runs a call whose PHP warnings are expected and handled through its return value.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Errors;

/**
 * For calls that report ordinary failure with a warning as well as a false return, such as
 * file_get_contents() on an unreachable host or getimagesize() on a truncated image. The handler
 * is scoped to the call and always restored, unlike the @ operator, which also hides the errors
 * of anything the call invokes and can't be reasoned about locally.
 */
final class QuietCall
{
    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    public static function run(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
