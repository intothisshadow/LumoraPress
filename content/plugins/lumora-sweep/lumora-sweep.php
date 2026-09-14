<?php

/**
 * The Lumora Sweep plugin's main file: plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Lumora Sweep
 * Plugin URI: https://lumorapress.org/plugins/lumora-sweep
 * Description: Database cleanup utility for excess revisions, old trashed posts/pages, old spam/trashed comments, and orphaned or duplicate post_meta rows, with an optional unused-categories/tags check. Manual, preview-before-delete, per category — a genuine maintenance convenience, not something core depends on.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: maintenance, database, cleanup
 * Requires at least: 0.14.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\LumoraSweep;

require_once __DIR__ . '/src/SweepService.php';

// Nothing to hook at load time — its whole surface is a gated tab on the
// existing Maintenance > Tools screen, which constructs SweepService from
// $kernel directly, the same as the Dummy Content plugin's own section there.

add_filter('plugin_action_links_lumora-sweep', static fn (array $links): array => [
    ...$links,
    ['label' => 'Settings', 'url' => admin_url('maintenance/tools') . '?tab=sweep'],
]);
