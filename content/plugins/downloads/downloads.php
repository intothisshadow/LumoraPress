<?php

/**
 * The Downloads plugin's main file: plugin metadata header.
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

// Needs no Kernel services until content actually contains [lumora_downloads]
// (it opens its own database connection then), so it's safe to construct here.
$downloadsShortcode = new DownloadsShortcode();

add_filter('content_html', static fn (string $html): string => $downloadsShortcode->renderShortcodes($html), 20);

// Category choices need $kernel->database, unavailable this early, so this
// hooks 'register_shortcodes' rather than calling register_shortcode() directly.
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
