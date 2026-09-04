<?php

/**
 * Tracks whether the current request rendered a lightbox-wrapped image (LP-031), so footer.php only loads PhotoSwipe on pages that need it.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Tracks whether the current request rendered a lightbox-wrapped image, so
 * footer.php only emits the PhotoSwipe <script>/<link> tags when needed.
 */
final class MediaViewer
{
    private static bool $used = false;

    public static function markUsed(): void
    {
        self::$used = true;
    }

    public static function isUsed(): bool
    {
        return self::$used;
    }

    /**
     * Test-only: a real HTTP request is always a fresh PHP process (this
     * flag starts false every time), so production code never needs to
     * reset it — but a PHPUnit run shares one process across many tests.
     */
    public static function reset(): void
    {
        self::$used = false;
    }
}
