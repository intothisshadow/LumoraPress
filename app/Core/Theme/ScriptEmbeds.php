<?php

/**
 * Tracks which script-based Auto-Embed providers (Twitter/X, Bluesky) rendered a blockquote on the current request, so footer.php only loads each provider's own script on pages that need it.
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
 * Tracks which script-based Auto-Embed providers rendered a blockquote on
 * the current request, so footer.php only loads each provider's own
 * script on pages that need it. One flag per provider, since footer.php
 * needs to know which script(s) to load, not just whether any were used.
 */
final class ScriptEmbeds
{
    /** @var array<string, bool> */
    private static array $used = [];

    public static function markUsed(string $provider): void
    {
        self::$used[$provider] = true;
    }

    public static function isUsed(string $provider): bool
    {
        return self::$used[$provider] ?? false;
    }

    /**
     * Test-only: a real HTTP request is always a fresh PHP process (this
     * flag starts empty every time), so production code never needs to
     * reset it — but a PHPUnit run shares one process across many tests.
     */
    public static function reset(): void
    {
        self::$used = [];
    }
}
