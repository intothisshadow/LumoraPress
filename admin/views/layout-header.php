<?php

/**
 * The shared admin page chrome: the top nav, sidebar menu, and page header, included at the top of every admin view.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var array<string, array{label: string, icon: string, capability: string|null, default_child?: string, children?: array<string, array{label: string, icon: string, capability: string|null}>}> $menu */
/** @var string $page */
/** @var string|null $subpage */
/** @var array{label: string, capability: string|null} $activeEntry */
/** @var array<int, array{label: string, url: string|null}> $breadcrumbs */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\ThemePreference;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$version = require LUMORA_ROOT . '/version.php';

/*
 * LP-087: rendered server-side from the already-authenticated
 * $currentUser, before the stylesheet is even requested, so there's no
 * flash-of-wrong-theme and no cookie/JS needed. Auto (the default)
 * omits the attribute entirely, leaving admin.css's
 * prefers-color-scheme media query as the sole source of truth — same
 * as before this feature existed.
 */
$themeAttribute = match ($currentUser->themePreference) {
    ThemePreference::Light => ' data-theme="light"',
    ThemePreference::Dark => ' data-theme="dark"',
    ThemePreference::Auto => '',
};

/*
 * LP-087: the sidebar's quick toggle only ever switches between Light and
 * Dark, never Auto — starting from Auto, clicking it commits to Dark
 * first (an arbitrary but predictable direction). Getting back to "follow
 * the system" is a deliberate choice made on the Profile page's
 * Appearance panel, not something the quick toggle cycles through.
 */
$isDarkPreference = $currentUser->themePreference === ThemePreference::Dark;
$nextThemePreference = $isDarkPreference ? ThemePreference::Light : ThemePreference::Dark;
$themeToggleLabel = $isDarkPreference ? 'Switch to light mode' : 'Switch to dark mode';
?>
<!DOCTYPE html>
<html lang="en"<?= $themeAttribute ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc_html($activeEntry['label']) ?> &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_url(admin_asset_url('css/admin.css')) ?>">
</head>
<body class="lp-admin">
<a class="lp-skip-link" href="#lp-admin-content">Skip to content</a>
<div class="lp-admin__shell">
    <aside class="lp-admin__sidebar" id="lp-admin-mobile-sidebar">
        <div class="lp-admin__brand">
            Lumora Press
            <span class="lp-admin__version"><?= esc_html((string) $version['version']) ?></span>
        </div>
        <button type="button" class="lp-admin__nav-toggle-all" data-lp-nav-toggle-all aria-label="Expand all menu sections">&#8862;</button>
        <div class="lp-admin__site-link">
            <a href="<?= esc_url(site_url()) ?>" target="_blank" rel="noopener">&larr; View Site</a>
        </div>
        <nav class="lp-admin__nav" aria-label="Admin menu">
            <ul>
                <?php foreach ($menu as $slug => $item): ?>
                    <?php if ($item['capability'] !== null && !$currentUser->can($item['capability'])) {
                        continue;
                    } ?>
                    <?php $isParentActive = $slug === $page; ?>
                    <?php if (isset($item['children'])): ?>
                        <li class="lp-admin__nav-item lp-admin__nav-item--parent<?= $isParentActive ? ' is-active is-open' : '' ?>" data-menu-slug="<?= esc_attr($slug) ?>">
                            <span class="lp-admin__nav-parent-row">
                                <a href="<?= esc_url(admin_url("{$slug}/{$item['default_child']}")) ?>"><?= esc_html($item['label']) ?></a>
                                <button
                                    type="button"
                                    class="lp-admin__nav-toggle"
                                    aria-expanded="<?= $isParentActive ? 'true' : 'false' ?>"
                                    aria-controls="lp-admin-submenu-<?= esc_attr($slug) ?>"
                                >
                                    <span class="lp-admin__nav-toggle-icon" aria-hidden="true"></span>
                                    <span class="lp-visually-hidden"><?= esc_html($item['label']) ?> submenu</span>
                                </button>
                            </span>
                            <ul class="lp-admin__nav-submenu" id="lp-admin-submenu-<?= esc_attr($slug) ?>">
                                <?php foreach ($item['children'] as $childSlug => $child): ?>
                                    <?php if ($child['capability'] !== null && !$currentUser->can($child['capability'])) {
                                        continue;
                                    } ?>
                                    <li class="lp-admin__nav-item<?= $isParentActive && $childSlug === $subpage ? ' is-active' : '' ?>">
                                        <a href="<?= esc_url(admin_url("{$slug}/{$childSlug}")) ?>"><span class="lp-admin__nav-icon" aria-hidden="true"><?= esc_html($child['icon']) ?></span><?= esc_html($child['label']) ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="lp-admin__nav-item<?= $isParentActive ? ' is-active' : '' ?>">
                            <a href="<?= esc_url(admin_url($slug)) ?>"><?= esc_html($item['label']) ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="lp-admin__user">
            <button type="button" class="lp-admin__nav-toggle-all" data-lp-nav-toggle-all aria-label="Expand all menu sections">&#8862;</button>
            <div class="lp-admin__user-row">
                <span class="lp-admin__user-name"><?= esc_html($currentUser->displayName) ?></span>
                <form method="post" action="" class="lp-admin__theme-toggle-form">
                    <?= Csrf::field('quick_theme_toggle') ?>
                    <input type="hidden" name="form" value="quick_theme_toggle">
                    <input type="hidden" name="theme_preference" value="<?= esc_attr($nextThemePreference->value) ?>">
                    <button type="submit" class="lp-admin__theme-toggle" title="<?= esc_attr($themeToggleLabel) ?>" aria-label="<?= esc_attr($themeToggleLabel) ?>">
                        <?= $isDarkPreference ? '☀️' : '🌙' ?>
                    </button>
                </form>
            </div>
            <a class="lp-admin__logout" href="<?= esc_url(admin_url('logout')) ?>">Log Out</a>
        </div>
    </aside>
    <?php /* LP-096: dismissible backdrop behind the mobile off-canvas sidebar drawer; hidden entirely above the 782px breakpoint (see admin.css), and above it via display:none regardless of state. */ ?>
    <div class="lp-admin__sidebar-backdrop" data-lp-mobile-nav-backdrop></div>
    <main id="lp-admin-content" class="lp-admin__content">
        <?php /* LP-096: hamburger toggle for the mobile off-canvas sidebar; hidden entirely above the 782px breakpoint via admin.css, not just visually collapsed, so it's never in the desktop tab order. */ ?>
        <button
            type="button"
            class="lp-admin__mobile-nav-toggle"
            data-lp-mobile-nav-toggle
            aria-expanded="false"
            aria-controls="lp-admin-mobile-sidebar"
        >
            <span aria-hidden="true">&#9776;</span> Menu
        </button>
        <?php if (count($breadcrumbs) > 1): ?>
            <nav class="lp-admin__breadcrumbs" aria-label="Breadcrumb">
                <ol>
                    <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <li>
                            <?php if ($crumb['url'] !== null && $index !== array_key_last($breadcrumbs)): ?>
                                <a href="<?= esc_url($crumb['url']) ?>"><?= esc_html($crumb['label']) ?></a>
                            <?php else: ?>
                                <span aria-current="page"><?= esc_html($crumb['label']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>
