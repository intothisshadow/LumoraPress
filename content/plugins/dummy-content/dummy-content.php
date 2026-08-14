<?php

/**
 * The Dummy Content plugin's main file (LPP-005): plugin metadata header.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Dummy Content
 * Plugin URI: https://lumorapress.org/plugins/dummy-content
 * Description: A developer-only utility that generates realistic, varied placeholder content (users, posts, pages, media, categories, tags, comments) for exercising theme/plugin rendering paths — every generated record is tagged so it can be removed in one click, leaving no trace on a real site.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: developer, testing, dummy content
 * Requires at least: 0.6.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\DummyContent;

require_once __DIR__ . '/src/DummyContentGenerator.php';

/*
 * Unlike Font Awesome (LPP-002), this plugin has nothing to hook at
 * load time — it does no rendering, adds no shortcode, changes no theme
 * output. Its entire surface is a gated section on the always-present
 * Maintenance > Tools screen (admin/views/maintenance/tools.php — not a
 * dedicated menu entry of its own, since admin/index.php only shows that
 * section while this plugin is active; see that view's own comment),
 * which constructs DummyContentGenerator directly from $kernel's own
 * services rather than through any hook this file would register. See
 * this feature's implementation plan for why: $kernel doesn't exist yet
 * when a plugin's main file runs (PluginManager::load() requires this
 * file long before bootstrap.php builds Kernel), so there is nothing
 * useful for this file to do at load time beyond making the
 * DummyContentGenerator class available via the require above.
 */
