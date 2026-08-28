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
 * Description: Optional hardening and spam-prevention features beyond core's own basics — starting with Stop User Enumeration (an author-archive username-existence oracle closed, plus an optional full author-archive hide).
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
 * this plugin isn't active) after resolving the requested slug to a real
 * user but before deciding whether to render the archive — see that
 * method's own docblock for the enumeration oracle this closes.
 */
add_filter(
    'lumora_shield_author_archive_visible',
    static fn (bool $visible, \LumoraPress\Models\User $author, int $publishedPostCount): bool
        => $lumoraShield->authorArchiveVisible($visible, $publishedPostCount),
);
