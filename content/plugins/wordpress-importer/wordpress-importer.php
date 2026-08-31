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

/*
 * NextGenGalleryShortcode (LPP-016) rewrites migrated NextGEN Gallery
 * shortcodes into core's own [lumora_folder_gallery] syntax — it never
 * renders anything itself, so it must run at a priority *lower* than
 * FolderGalleryShortcode's own content_html registration (the default
 * priority 10, see include/bootstrap.php) for that rewritten text to
 * still be seen and rendered within the same filter pass.
 */
$nextGenGalleryShortcode = new NextGenGalleryShortcode();

add_filter('content_html', static fn (string $html): string => $nextGenGalleryShortcode->rewriteShortcodes($html), 5);

/*
 * LP-110: picker metadata for the editor toolbar's "Insert Shortcode"
 * button — purely additive, doesn't change how
 * [sdm_show_dl_from_category ...] itself renders (still the
 * content_html filter above). Needs $kernel->folders (a live list of
 * Media Folders, to build category_slug's choices) to exist, which
 * isn't true yet at this point in the file — see
 * include/shortcodes.php's own docblock for why this hooks
 * 'register_shortcodes' instead of calling register_shortcode()
 * directly here. Only [sdm_show_dl_from_category] is covered — this
 * file's other two shortcodes ([sdm_download], [sdm_latest_downloads])
 * are left typeable by hand, matching the ticket's own three-shortcode
 * scope.
 */
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
