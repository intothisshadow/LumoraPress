<?php

/**
 * The Link Directory plugin's main file: plugin metadata header.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Link Directory
 * Plugin URI: https://lumorapress.org/plugins/link-directory
 * Description: An admin-organized directory of links to other sites — a title, category/sub-category, URL, thumbnail, and description per entry. Show a category's links, or every category and its link count, anywhere with the [lumora_link_directory] shortcode.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: links, directory, webring
 * Requires at least: 0.10.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\LinkDirectory;

use LumoraPress\Core\Kernel;
use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\ShortcodeFieldType;

require_once __DIR__ . '/src/LinkStatus.php';
require_once __DIR__ . '/src/Link.php';
require_once __DIR__ . '/src/LinkDirectoryCategory.php';
require_once __DIR__ . '/src/LinkDirectoryCategoryService.php';
require_once __DIR__ . '/src/LinkService.php';
require_once __DIR__ . '/src/LinkDirectoryShortcode.php';

// Needs no Kernel services until content actually contains
// [lumora_link_directory] (it opens its own database connection then), so
// it's safe to construct here.
$linkDirectoryShortcode = new LinkDirectoryShortcode();

add_filter('content_html', static fn (string $html): string => $linkDirectoryShortcode->renderShortcodes($html), 20);

// Category choices need $kernel->database, unavailable this early, so this
// hooks 'register_shortcodes' rather than calling register_shortcode() directly.
add_action('register_shortcodes', static function (mixed $registry, Kernel $kernel): void {
    $categoryService = new LinkDirectoryCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
    $categoryChoices = ['' => '(every category, with counts)'];

    foreach ($categoryService->listAll() as $category) {
        $categoryChoices[(string) $category->id] = $category->name;
    }

    register_shortcode('lumora_link_directory', 'Link Directory', [
        new ShortcodeField('category_id', 'Category', ShortcodeFieldType::Select, choices: $categoryChoices, help: 'Leave blank to list every category with its link count instead of one category\'s links.'),
    ]);
});

// One-click access to this plugin's own top-level menu — worth having even
// though it already appears in the sidebar, matching Downloads'/Contact
// Forms' own plugin_action_links filter.
add_filter('plugin_action_links_link-directory', static fn (array $links): array => [
    ...$links,
    ['label' => 'Settings', 'url' => admin_url('link-directory')],
]);
