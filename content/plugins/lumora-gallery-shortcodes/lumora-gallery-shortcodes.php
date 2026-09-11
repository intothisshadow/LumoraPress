<?php

/**
 * The Lumora Gallery Shortcodes plugin's main file: plugin metadata header and bootstrap.
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
 * Version: 0.3.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: gallery, media, integration
 * Requires at least: 0.8.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\LumoraGalleryShortcodes;

use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\ShortcodeFieldType;

require_once __DIR__ . '/src/GallerySettingsService.php';
require_once __DIR__ . '/src/GalleryConfigParser.php';
require_once __DIR__ . '/src/GalleryQueryService.php';
require_once __DIR__ . '/src/GalleryShortcode.php';

/*
 * GalleryShortcode needs no Kernel service at registration time — it
 * only opens a (separately-configured, external) database connection
 * once a page's content actually contains one of its shortcodes, via
 * GallerySettingsService — so it's safe to construct here at plugin-load
 * time, before $kernel exists.
 */
$gallerySettings = new GallerySettingsService();
$galleryShortcode = new GalleryShortcode($gallerySettings);

add_filter('content_html', static fn (string $html): string => $galleryShortcode->render($html));

/*
 * Picker metadata for the editor toolbar's "Insert Shortcode" button —
 * purely additive, doesn't change how [lumora_gallery_album ...]/
 * [lumora_gallery_newest ...] themselves render (still the content_html
 * filter above). `album_id`'s choices need a live query against the
 * separately-configured Gallery database, which has nothing to do with
 * this site's own Kernel, so it's queried directly here rather than
 * through $kernel; still hooked on 'register_shortcodes' to match every
 * other database-backed shortcode registration's timing. An unconfigured
 * or unreachable Gallery connection yields an empty choices list rather
 * than an error.
 *
 * Covers only `[lumora_gallery_album]`'s "whole album" variant
 * (`album_id` alone) and `[lumora_gallery_newest]`'s `count` — the album
 * shortcode's `count` (newest N within one album), `image_id` (specific
 * images), and multi-album (`album_id` with more than one comma-separated
 * id) variants are left typeable by hand; the Select field here only ever
 * produces a single id.
 */
add_action('register_shortcodes', static function () use ($gallerySettings): void {
    $albumChoices = [];
    $database = $gallerySettings->connect();

    if ($database !== null) {
        $query = new GalleryQueryService($database, $gallerySettings->settings()['table_prefix']);

        foreach ($query->listAlbums() as $album) {
            $albumChoices[(string) $album['id']] = $album['title'] !== '' ? $album['title'] : $album['folder'];
        }
    }

    register_shortcode('lumora_gallery_album', 'Gallery Album', [
        new ShortcodeField('album_id', 'Album', ShortcodeFieldType::Select, required: true, choices: $albumChoices),
    ]);

    register_shortcode('lumora_gallery_newest', 'Gallery — Newest Images', [
        new ShortcodeField('count', 'Number of images', ShortcodeFieldType::Number, default: '10'),
    ]);
});
