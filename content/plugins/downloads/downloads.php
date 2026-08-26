<?php

/**
 * The Downloads plugin's main file (LPP-008): plugin metadata header.
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
 * Plugin Name: Downloads
 * Plugin URI: https://lumorapress.org/plugins/downloads
 * Description: A dedicated admin screen to manage downloadable files and links — add a new download (an uploaded file or an external URL), give it a title, description, and category, and see every download grouped by category in one place. Show them anywhere with the [lumora_downloads] shortcode.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: downloads, files, media
 * Requires at least: 0.6.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\Downloads;

require_once __DIR__ . '/src/DownloadType.php';
require_once __DIR__ . '/src/DownloadStatus.php';
require_once __DIR__ . '/src/Download.php';
require_once __DIR__ . '/src/DownloadCategory.php';
require_once __DIR__ . '/src/DownloadCategoryService.php';
require_once __DIR__ . '/src/DownloadService.php';
require_once __DIR__ . '/src/DownloadsShortcode.php';

/*
 * Its admin screens (admin/views/downloads/*.php, reachable only while
 * this plugin is active — see admin/index.php's $downloadsActive-gated
 * 'downloads' $menu entry) construct DownloadService directly from
 * $kernel's own already-existing services, the same pattern
 * admin/views/maintenance/import.php uses for WordPressImportService.
 * See DownloadService's own docblock for why it deliberately reuses
 * MediaService/RedirectService rather than reimplementing file storage
 * or URL redirection.
 *
 * DownloadsShortcode renders public-facing content, so — unlike the
 * admin screens above — it needs a real, always-on hook: the same
 * 'content_html' filter Font Awesome's [icon] shortcode and the
 * WordPress Importer plugin's own (unrelated) shortcode both use (see
 * font-awesome.php/wordpress-importer.php). It needs no Kernel services
 * at *registration* time (only when a page's content actually contains
 * `[lumora_downloads]`, at which point it opens its own database
 * connection — see its own docblock), so it's safe to construct here at
 * plugin-load time.
 */
$downloadsShortcode = new DownloadsShortcode();

add_filter('content_html', static fn (string $html): string => $downloadsShortcode->renderShortcodes($html), 20);
