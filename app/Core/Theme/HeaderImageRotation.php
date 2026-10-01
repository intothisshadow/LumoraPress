<?php

/**
 * Chooses which of a site's header images to show on this page view.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.20.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * With one image there is nothing to choose. With several, "added" steps to
 * the next image on each page view and "random" picks any other than the
 * previous one. The visitor's position is kept in the session the site sets
 * for every visitor anyway, so this adds no cookie.
 */
final class HeaderImageRotation
{
    public const ORDER_ADDED = 'added';

    public const ORDER_RANDOM = 'random';

    private const SESSION_KEY = 'lp_header_image_index';

    /**
     * @param array<int, string> $urls usable image URLs, in the order they were added
     * @param array<string, mixed> $session
     */
    public static function pick(array $urls, string $order, array &$session): ?string
    {
        $urls = array_values($urls);
        $count = count($urls);

        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return $urls[0];
        }

        $last = isset($session[self::SESSION_KEY]) ? (int) $session[self::SESSION_KEY] : -1;

        if ($order === self::ORDER_RANDOM) {
            $index = random_int(0, $count - 1);

            if ($index === $last) {
                $index = ($index + 1 + random_int(0, $count - 2)) % $count;
            }
        } else {
            $index = ($last + 1) % $count;
        }

        $session[self::SESSION_KEY] = $index;

        return $urls[$index];
    }
}
