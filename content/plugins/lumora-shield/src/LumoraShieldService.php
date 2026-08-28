<?php

/**
 * Core logic for the bundled Lumora Shield plugin (LPP-001): settings and the Stop User Enumeration module.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraShield;

use LumoraPress\Core\ActiveConfig;

/**
 * First module built for the much larger LPP-001 Lumora Shield ticket:
 * Stop User Enumeration. A 2026-08-28 re-audit of the ticket's own
 * "nothing to protect yet" note found one real gap since introduced —
 * `/author/{slug}` (LP-008) returned 200 for any real username (even one
 * with zero published posts) and 404 for a made-up one, a plain
 * username-existence oracle.
 *
 * The zero-published-posts half of that fix has no real tradeoff (an
 * author with nothing published has no archive content anyone
 * legitimately wants to browse), so it was moved to live unconditionally
 * in `SiteController::author()` itself rather than staying gated behind
 * this optional plugin. What's left here is the one genuine optional
 * tradeoff: an administrator choosing to hide *every* author archive
 * outright, including real ones with published posts — that does remove
 * a real public feature, so it stays an explicit opt-in setting via the
 * `lumora_shield_author_archive_visible` filter SiteController::author()
 * calls. Everything else in the ticket's User Enumeration Protection
 * checklist remains N/A per that same audit (no XML-RPC, no REST user
 * endpoint, generic login/password-reset responses already exist in
 * core) — deliberately not built speculatively against surface area
 * that doesn't exist yet.
 */
final class LumoraShieldService
{
    private const OPTION_KEY = 'lumora_shield_settings';

    private static ?self $instance = null;

    /** @var array{hide_author_archives: bool}|null */
    private ?array $settingsCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array{hide_author_archives: bool}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        $defaults = [
            // Off by default: this removes a real public feature (browsing
            // everything by an author), unlike the zero-post case core
            // already handles unconditionally — an explicit opt-in, not a
            // secure-by-default posture, is the right call here.
            'hide_author_archives' => false,
        ];

        $settings = array_merge($defaults, is_array($decoded) ? $decoded : []);

        $this->settingsCache = [
            'hide_author_archives' => (bool) $settings['hide_author_archives'],
        ];

        return $this->settingsCache;
    }

    /**
     * @param array{hide_author_archives: bool} $settings
     */
    public function saveSettings(array $settings): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    /**
     * The `lumora_shield_author_archive_visible` filter listener
     * (registered in lumora-shield.php). $default is whatever core would
     * otherwise decide by the time this runs — SiteController::author()
     * has already 404'd a zero-published-post author unconditionally, so
     * $default is always true here — passed through unchanged unless
     * "hide author archives entirely" is on, so disabling the plugin
     * restores exact core-only behavior. $publishedPostCount isn't
     * needed by this module's own logic (core's own check already used
     * it), but stays part of the filter's contract for any other
     * listener that might want it.
     */
    public function authorArchiveVisible(bool $default, int $publishedPostCount): bool
    {
        if ($this->settings()['hide_author_archives']) {
            return false;
        }

        return $default;
    }
}
