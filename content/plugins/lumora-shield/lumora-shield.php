<?php

/**
 * The Lumora Shield plugin's main file (LPP-001): plugin metadata header and bootstrap.
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

/*
 * Plugin Name: Lumora Shield
 * Plugin URI: https://lumorapress.org/plugins/lumora-shield
 * Description: Optional hardening and spam-prevention features beyond core's own basics — starting with Stop User Enumeration (an optional "hide every author archive entirely" setting; the underlying username-existence oracle itself is closed unconditionally in core).
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: security, spam, hardening
 * Requires at least: 0.4.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\LumoraShield;

require_once __DIR__ . '/src/LumoraShieldService.php';

$lumoraShield = LumoraShieldService::instance();

/*
 * SiteController::author() calls this filter (default true, a no-op when
 * this plugin isn't active) after already 404ing a zero-published-post
 * author unconditionally in core — this listener only ever has one more
 * thing to decide: whether the administrator opted into hiding every
 * author archive outright (see LumoraShieldService::authorArchiveVisible()'s
 * own docblock).
 */
add_filter(
    'lumora_shield_author_archive_visible',
    static fn (bool $visible, \LumoraPress\Models\User $author, int $publishedPostCount): bool
        => $lumoraShield->authorArchiveVisible($visible, $publishedPostCount),
);
