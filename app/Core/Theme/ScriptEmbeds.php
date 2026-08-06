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
 * Tracks which script-based Auto-Embed providers (LP-070's Twitter/X, and
 * any later provider with the same "no plain iframe, needs a loaded
 * `<script>`" shape) actually rendered a blockquote on the current
 * request, so header.php/footer.php only emit each provider's own script
 * tag on pages that actually contain a matching blockquote for it to scan
 * — the same "bridge for something themes can't reach any other way"
 * shape as MediaViewer (LP-031), kept as its own class rather than a
 * second flag on MediaViewer since lightbox images and script-based
 * embeds are unrelated concerns that happen to need an identical
 * mechanism. One shared class rather than one single-flag class per
 * provider (TweetEmbed was that shape until this generalization) because
 * a per-provider script tag needs a per-provider flag regardless — a
 * single "was any script embed used" bool wouldn't tell footer.php which
 * script(s) to load.
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
