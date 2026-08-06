<?php

/**
 * Default theme setup: registers widget areas and navigation menu locations.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

/**
 * Default theme setup: widget areas and nav menu locations. Core widget
 * types (Text, Custom HTML, Search, Navigation Menu, Pages, Categories,
 * Recent Posts, Recent Comments, Archives, Tag Cloud, Meta) are
 * registered by LumoraPress\Core\Widgets\CoreWidgets (LP-048) rather than
 * here — this file used to register a single "text" widget type itself as
 * a Phase 1 proof of concept, before core provided any widgets at all.
 */

register_sidebar('primary', 'Primary Sidebar', 'Appears alongside posts and pages.');

register_nav_menu('primary', 'Primary Menu');
register_nav_menu('footer', 'Footer Menu');
register_nav_menu('social', 'Social Links Menu');
register_nav_menu('secondary', 'Secondary Menu');
