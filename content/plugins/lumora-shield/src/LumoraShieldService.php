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
 * `/author/{slug}` (LP-008) returns 200 for any real username (even one
 * with zero published posts) and 404 for a made-up one, a plain
 * username-existence oracle. This service closes it via the
 * `lumora_shield_author_archive_visible` filter SiteController::author()
 * now calls, plus an optional stronger "hide entirely" setting for
 * administrators who want no author-archive exposure at all. Everything
 * else in the ticket's User Enumeration Protection checklist remains N/A
 * per that same audit (no XML-RPC, no REST user endpoint, generic login/
 * password-reset responses already exist in core) — deliberately not
 * built speculatively against surface area that doesn't exist yet.
 */
final class LumoraShieldService
{
    private const OPTION_KEY = 'lumora_shield_settings';

    private static ?self $instance = null;

    /** @var array{enabled: bool, protect_user_enumeration: bool, hide_author_archives: bool}|null */
    private ?array $settingsCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array{enabled: bool, protect_user_enumeration: bool, hide_author_archives: bool}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        $defaults = [
            // Secure defaults enabled on new installations (ticket's own
            // Security section) — closing a real oracle should be opt-out,
            // not opt-in, once an administrator has activated this plugin
            // at all.
            'enabled' => true,
            'protect_user_enumeration' => true,
            'hide_author_archives' => false,
        ];

        $settings = array_merge($defaults, is_array($decoded) ? $decoded : []);

        $this->settingsCache = [
            'enabled' => (bool) $settings['enabled'],
            'protect_user_enumeration' => (bool) $settings['protect_user_enumeration'],
            'hide_author_archives' => (bool) $settings['hide_author_archives'],
        ];

        return $this->settingsCache;
    }

    /**
     * @param array{enabled: bool, protect_user_enumeration: bool, hide_author_archives: bool} $settings
     */
    public function saveSettings(array $settings): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    /**
     * The `lumora_shield_author_archive_visible` filter listener
     * (registered in lumora-shield.php). $default is whatever core would
     * otherwise decide (currently always true — SiteController::author()
     * has no other reason to hide an archive once the slug resolves to a
     * real user), passed through unchanged whenever this module is off,
     * so disabling the plugin/module restores exact pre-LPP-001 behavior.
     *
     * "Hide entirely" wins over the enumeration-only check: an
     * administrator who's opted into hiding every author archive doesn't
     * also want real authors' pages to keep working.
     */
    public function authorArchiveVisible(bool $default, int $publishedPostCount): bool
    {
        $settings = $this->settings();

        if (!$settings['enabled']) {
            return $default;
        }

        if ($settings['hide_author_archives']) {
            return false;
        }

        if ($settings['protect_user_enumeration'] && $publishedPostCount === 0) {
            return false;
        }

        return $default;
    }
}
