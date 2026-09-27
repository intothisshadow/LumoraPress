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

if (!function_exists('register_nav_menu')) {
    function register_nav_menu(string $location, string $label): void
    {
        Menus::instance()->registerLocation($location, $label);
    }
}

if (!function_exists('register_nav_menu_item_type')) {
    /**
     * Adds a panel of a plugin's own items to Appearance > Menus — see
     * MenuManager::registerItemType() for the shape $source returns.
     */
    function register_nav_menu_item_type(string $type, string $label, callable $source): void
    {
        Menus::instance()->registerItemType($type, $label, $source);
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
     * Renders $location as a (possibly nested) <ul>. A location with no
     * items assigned (no menu, and nothing set via the legacy
     * assign()) renders nothing at all, same as before named menus
     * existed.
     */
    function nav_menu(string $location, string $menuClass = 'lp-nav-menu'): void
    {
        $tree = apply_filters('nav_menu_tree', Menus::instance()->itemTree($location), $location);

        if (!is_array($tree) || $tree === []) {
            return;
        }

        $html = '<ul class="' . esc_attr($menuClass) . '">' . lp_nav_menu_branch_html($tree, $location, 0) . '</ul>';

        echo (string) apply_filters('nav_menu_html', $html, $location);
    }
}

if (!function_exists('lp_nav_menu_branch_html')) {
    /**
     * @param array<int, array{item: array<string, mixed>, children: array<mixed>}> $branch
     */
    function lp_nav_menu_branch_html(array $branch, string $location = '', int $depth = 0): string
    {
        $html = '';

        foreach ($branch as $node) {
            $item = $node['item'];
            $target = ($item['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="' . esc_attr(trim('noopener noreferrer ' . (string) ($item['rel'] ?? ''))) . '"' : (($item['rel'] ?? '') !== '' ? ' rel="' . esc_attr((string) $item['rel']) . '"' : '');
            $titleAttribute = ($item['titleAttribute'] ?? '') !== '' ? ' title="' . esc_attr((string) $item['titleAttribute']) . '"' : '';
            $hasChildren = ($node['children'] ?? []) !== [];

            $classes = ['lp-nav-menu__item'];

            if (($item['cssClass'] ?? '') !== '') {
                $classes[] = (string) $item['cssClass'];
            }

            if ($hasChildren) {
                $classes[] = 'lp-nav-menu__item--has-children';
            }

            $classes = (array) apply_filters('nav_menu_item_classes', $classes, $item, $location, $depth);

            $html .= '<li class="' . esc_attr(implode(' ', array_map('strval', $classes))) . '">'
                . '<a href="' . esc_url(preview_theme_link((string) $item['url'])) . '"' . $target . $titleAttribute . '>' . esc_html((string) $item['label']) . '</a>';

            if ($hasChildren) {
                $html .= '<ul class="lp-nav-menu__submenu">' . lp_nav_menu_branch_html($node['children'], $location, $depth + 1) . '</ul>';
            }

            $html .= '</li>';
        }

        return $html;
    }
}

if (!function_exists('lp_render_nav_menu_branch')) {
    /**
     * Kept for themes that call it directly; nav_menu() uses
     * lp_nav_menu_branch_html() so the whole menu can be filtered.
     *
     * @param array<int, array{item: array<string, mixed>, children: array<mixed>}> $branch
     */
    function lp_render_nav_menu_branch(array $branch): void
    {
        echo lp_nav_menu_branch_html($branch);
    }
}
