<?php

/**
 * Estimates how long a piece of text takes to read.
 *
 * @package LumoraPress
 * @subpackage Content
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.23.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Content;

final class ReadingTime
{
    // The commonly cited average for silent reading of prose.
    public const WORDS_PER_MINUTE = 200;

    /**
     * Whole minutes, rounded up, never below 1 for non-empty text so a short
     * post doesn't read "0 min". Empty text is 0 and callers show nothing.
     */
    public static function minutes(string $plainText): int
    {
        // \S+ counts CJK runs as one word, which understates them; a
        // per-language word model isn't worth it for a rough estimate.
        $words = preg_match_all('/\S+/u', $plainText);

        if ($words === false || $words === 0) {
            return 0;
        }

        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }
}
