<?php

/**
 * Procedural navigation menu API for themes (register_nav_menu(), nav_menu()).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Core\Menus\Menus;

/**
 * Procedural navigation menu API for themes.
 */

if (!function_exists('register_nav_menu')) {
    function register_nav_menu(string $location, string $label): void
    {
        Menus::instance()->registerLocation($location, $label);
    }
}

if (!function_exists('has_nav_menu')) {
    function has_nav_menu(string $location): bool
    {
        return Menus::instance()->hasItems($location);
    }
}

if (!function_exists('nav_menu')) {
    /**
     * Renders $location as a (possibly nested — LP-049) <ul>. A location
     * with no items assigned (no menu, and nothing set via the legacy
     * assign()) renders nothing at all, same as before named menus
     * existed.
     */
    function nav_menu(string $location, string $menuClass = 'lp-nav-menu'): void
    {
        $tree = Menus::instance()->itemTree($location);

        if ($tree === []) {
            return;
        }

        echo '<ul class="' . esc_attr($menuClass) . '">';
        lp_render_nav_menu_branch($tree);
        echo '</ul>';
    }
}

if (!function_exists('lp_render_nav_menu_branch')) {
    /**
     * @param array<int, array{item: array<string, mixed>, children: array<mixed>}> $branch
     */
    function lp_render_nav_menu_branch(array $branch): void
    {
        foreach ($branch as $node) {
            $item = $node['item'];
            $target = ($item['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="' . esc_attr(trim('noopener noreferrer ' . (string) ($item['rel'] ?? ''))) . '"' : (($item['rel'] ?? '') !== '' ? ' rel="' . esc_attr((string) $item['rel']) . '"' : '');
            $titleAttribute = ($item['titleAttribute'] ?? '') !== '' ? ' title="' . esc_attr((string) $item['titleAttribute']) . '"' : '';
            $itemClass = 'lp-nav-menu__item' . (($item['cssClass'] ?? '') !== '' ? ' ' . esc_attr((string) $item['cssClass']) : '');
            $hasChildren = $node['children'] !== [];

            echo '<li class="' . $itemClass . ($hasChildren ? ' lp-nav-menu__item--has-children' : '') . '">'
                . '<a href="' . esc_url(preview_theme_link((string) $item['url'])) . '"' . $target . $titleAttribute . '>' . esc_html((string) $item['label']) . '</a>';

            if ($hasChildren) {
                echo '<ul class="lp-nav-menu__submenu">';
                lp_render_nav_menu_branch($node['children']);
                echo '</ul>';
            }

            echo '</li>';
        }
    }
}
