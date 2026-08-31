<?php

/**
 * The Lumora Gallery Shortcodes plugin's main file (LPP-015): plugin metadata header and bootstrap.
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
 * Plugin Name: Lumora Gallery Shortcodes
 * Plugin URI: https://lumorapress.org/plugins/lumora-gallery-shortcodes
 * Description: Embed albums/images from a separately-installed Lumora Gallery site into Lumora Press posts and pages — whole albums, the newest N images from an album, specific images, or the newest N images across the entire gallery. Thumbnails open the real full-size image in the same PhotoSwipe lightbox every other gallery in Lumora Press uses. Entirely optional: Lumora Gallery is never required for Lumora Press to work.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: gallery, media, integration
 * Requires at least: 0.8.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\LumoraGalleryShortcodes;

require_once __DIR__ . '/src/GallerySettingsService.php';
require_once __DIR__ . '/src/GalleryQueryService.php';
require_once __DIR__ . '/src/GalleryShortcode.php';

/*
 * GalleryShortcode needs no Kernel service at registration time — it
 * only opens a (separately-configured, external) database connection
 * once a page's content actually contains one of its shortcodes, via
 * GallerySettingsService — so it's safe to construct here at plugin-load
 * time, the same "no hook to register, $kernel doesn't exist yet"
 * reasoning DownloadsShortcode/NextGenGalleryShortcode's own bootstrap
 * already establishes.
 */
$galleryShortcode = new GalleryShortcode(new GallerySettingsService());

add_filter('content_html', static fn (string $html): string => $galleryShortcode->render($html));
