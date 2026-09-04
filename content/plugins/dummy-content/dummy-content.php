<?php

/**
 * The Dummy Content plugin's main file: plugin metadata header.
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

// Nothing to hook at load time — its whole surface is a gated Maintenance > Tools
// section, which constructs DummyContentGenerator from $kernel directly.
