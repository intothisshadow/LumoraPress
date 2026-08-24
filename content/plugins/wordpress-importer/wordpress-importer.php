<?php

/**
 * The WordPress Importer plugin's main file (LPP-004): plugin metadata header.
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
 * Plugin Name: WordPress Importer
 * Plugin URI: https://lumorapress.org/plugins/wordpress-importer
 * Description: Imports an existing WordPress site's users, categories, tags, media, pages, posts, and comments via a direct database connection and a local copy of its uploads folder.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: import, migration, wordpress
 * Requires at least: 0.6.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\WordPressImporter;

require_once __DIR__ . '/src/WordPressSource.php';
require_once __DIR__ . '/src/ContentImageRewriter.php';
require_once __DIR__ . '/src/WordPressImportService.php';
require_once __DIR__ . '/src/DownloadsShortcode.php';

/*
 * The import flow itself (WordPressSource/WordPressImportService) has
 * nothing to hook at load time — its entire surface is the admin
 * Maintenance > Import screen (admin/views/maintenance/import.php),
 * which constructs those two directly from $kernel's own services plus
 * the admin-supplied source DB credentials/uploads path, the same
 * "no hook to register, $kernel doesn't exist yet" reasoning
 * dummy-content.php's own docblock explains (see DEVELOPER-APIS.md's
 * "Plugin file structure" section too).
 *
 * DownloadsShortcode (LPP-007) is different: it renders public-facing
 * content, so it needs a real, always-on hook — the same 'content_html'
 * filter Font Awesome's [icon] shortcode uses (see
 * font-awesome.php). It needs no Kernel services at *registration*
 * time (only when a page's content actually contains the shortcode, at
 * which point it opens its own database connection — see its own
 * docblock), so it's safe to construct here at plugin-load time.
 */
$downloadsShortcode = new DownloadsShortcode();

add_filter('content_html', static fn (string $html): string => $downloadsShortcode->renderShortcodes($html), 20);
