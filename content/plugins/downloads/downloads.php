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

use LumoraPress\Core\Kernel;
use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\ShortcodeFieldType;

require_once __DIR__ . '/src/DownloadType.php';
require_once __DIR__ . '/src/DownloadStatus.php';
require_once __DIR__ . '/src/Download.php';
require_once __DIR__ . '/src/DownloadCategory.php';
require_once __DIR__ . '/src/DownloadCategoryService.php';
require_once __DIR__ . '/src/DownloadService.php';
require_once __DIR__ . '/src/DownloadMediaUrlMasker.php';
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

/*
 * LP-110: picker metadata for the editor toolbar's "Insert Shortcode"
 * button — purely additive, doesn't change how [lumora_downloads ...]
 * itself renders (still the content_html filter above). Needs a live
 * list of this plugin's own Download categories (a wholly separate
 * table/concept from Media Folders — see DownloadCategory's own
 * docblock) to build category_id's choices, which requires
 * $kernel->database and isn't available yet at this point in the file —
 * see include/shortcodes.php's own docblock for why this hooks
 * 'register_shortcodes' instead of calling register_shortcode()
 * directly here. Covers only the "list every download in a category"
 * form (DownloadsShortcode::renderOne()'s third variant) — download_id
 * (embed one specific download) and count (a "newest N" list) are left
 * typeable by hand, matching the ticket's own three-shortcode scope.
 */
add_action('register_shortcodes', static function (mixed $registry, Kernel $kernel): void {
    $categoryService = new DownloadCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
    $categoryChoices = [];

    foreach ($categoryService->listAll() as $category) {
        $categoryChoices[(string) $category->id] = $category->name;
    }

    register_shortcode('lumora_downloads', 'Downloads from Category', [
        new ShortcodeField('category_id', 'Category', ShortcodeFieldType::Select, required: true, choices: $categoryChoices),
        new ShortcodeField('show_size', 'Show file size', ShortcodeFieldType::Checkbox, default: '0'),
    ]);
});
