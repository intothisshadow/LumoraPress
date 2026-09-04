<?php

/**
 * The WordPress Importer plugin's main file: plugin metadata header.
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
 * Description: Imports an existing WordPress site's users, categories, tags, media, pages, posts, and comments via a direct database connection or a WXR export file, plus a local copy of its uploads folder.
 * Version: 1.0.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: import, migration, wordpress
 * Requires at least: 0.6.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\WordPressImporter;

use LumoraPress\Core\Kernel;
use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\ShortcodeFieldType;

require_once __DIR__ . '/src/WordPressSourceInterface.php';
require_once __DIR__ . '/src/WordPressSource.php';
require_once __DIR__ . '/src/WordPressXmlSource.php';
require_once __DIR__ . '/src/WordPressConfigParser.php';
require_once __DIR__ . '/src/ContentImageRewriter.php';
require_once __DIR__ . '/src/InternalLinkRewriter.php';
require_once __DIR__ . '/src/ImportProgress.php';
require_once __DIR__ . '/src/WordPressImportService.php';
require_once __DIR__ . '/src/DownloadsShortcode.php';
require_once __DIR__ . '/src/NextGenGalleryShortcode.php';

// The import flow itself has nothing to hook at load time — its entire
// surface is the admin Maintenance > Import screen, constructed directly
// from $kernel once it exists. DownloadsShortcode needs a real, always-on
// hook instead, since it renders public-facing content.
$downloadsShortcode = new DownloadsShortcode();

add_filter('content_html', static fn (string $html): string => $downloadsShortcode->renderShortcodes($html), 20);

// Rewrites migrated NextGEN Gallery shortcodes into core's own syntax, so
// it must run at a lower priority than FolderGalleryShortcode's default 10.
$nextGenGalleryShortcode = new NextGenGalleryShortcode();

add_filter('content_html', static fn (string $html): string => $nextGenGalleryShortcode->rewriteShortcodes($html), 5);

// $kernel->folders isn't available yet at this point, so this hooks
// 'register_shortcodes' rather than calling register_shortcode() directly.
// Only [sdm_show_dl_from_category] is covered; the other two are typed by hand.
add_action('register_shortcodes', static function (mixed $registry, Kernel $kernel) use ($downloadsShortcode): void {
    $folderChoices = [];

    foreach ($kernel->folders->listAll() as $folder) {
        $folderChoices[$downloadsShortcode->slugify($folder->name)] = $folder->name;
    }

    register_shortcode('sdm_show_dl_from_category', 'Downloads from Category (Imported)', [
        new ShortcodeField('category_slug', 'Category', ShortcodeFieldType::Select, required: true, choices: $folderChoices, help: 'A Media folder — matched by its slugified name, the same way the imported content itself does.'),
        new ShortcodeField('show_size', 'Show file size', ShortcodeFieldType::Checkbox, default: '0'),
    ]);
});
