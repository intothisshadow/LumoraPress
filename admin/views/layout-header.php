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

// Rendered server-side before the stylesheet is requested, so there's no
// flash-of-wrong-theme. Auto (the default) omits the attribute entirely,
// leaving admin.css's prefers-color-scheme query as the sole source of truth.
$themeAttribute = match ($currentUser->themePreference) {
    ThemePreference::Light => ' data-theme="light"',
    ThemePreference::Dark => ' data-theme="dark"',
    ThemePreference::Auto => '',
};

// The quick toggle only switches between Light and Dark, never Auto —
// starting from Auto it commits to Dark first. Returning to "follow the
// system" is a deliberate choice made on the Profile page instead.
$isDarkPreference = $currentUser->themePreference === ThemePreference::Dark;
$nextThemePreference = $isDarkPreference ? ThemePreference::Light : ThemePreference::Dark;
$themeToggleLabel = $isDarkPreference ? 'Switch to light mode' : 'Switch to dark mode';

// Condensed sidebar counterparts of dashboard.php's own full-text alerts (LP-177) — same
// visibility rules, so a screen other than Dashboard still surfaces them instead of only
// showing on the one screen an admin might not be looking at.
$sidebarInstallDirectoryExists = is_dir(LUMORA_ROOT . '/install');
$sidebarMaintenanceActive = $currentUser->can('manage_options') && $kernel->maintenance->isActive();

/**
 * A plugin-contributed condensed sidebar alert, in the same spot as the two core checks
 * above but without a core code change per plugin — see docs/DEVELOPER-APIS.md. Each entry:
 * ['variant' => 'error'|'warning', 'url' => string, 'title' => string, 'label' => string
 * (trusted HTML, not escaped — matches dashboard_widgets' own trusted-output convention)].
 *
 * @var array<int, array{variant: string, url: string, title: string, label: string}> $sidebarPluginAlerts
 */
$sidebarPluginAlerts = apply_filters('admin_sidebar_alerts', [], $currentUser);
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
            <span class="lp-admin__brand-label">Lumora Press</span>
            <span class="lp-admin__version"><?= esc_html((string) $version['version']) ?></span>
        </div>
        <?php if ($sidebarInstallDirectoryExists): ?>
            <a class="lp-alert lp-alert--error lp-admin__sidebar-alert" href="<?= esc_url(admin_url('dashboard')) ?>" title="The install/ directory still exists on the server and should be removed for security. See the Dashboard for details.">
                <code>install/</code> directory still present &mdash; remove it
            </a>
        <?php endif; ?>
        <?php if ($sidebarMaintenanceActive): ?>
            <a class="lp-alert lp-alert--warning lp-admin__sidebar-alert" href="<?= esc_url(admin_url('settings/maintenance-mode')) ?>" title="Visitors currently see the maintenance page instead of the site. Turn it off from Settings &rsaquo; Maintenance Mode or the Dashboard.">
                Maintenance mode is <strong>ON</strong>
            </a>
        <?php endif; ?>
        <?php foreach ($sidebarPluginAlerts as $sidebarPluginAlert): ?>
            <a class="lp-alert lp-alert--<?= esc_attr($sidebarPluginAlert['variant']) ?> lp-admin__sidebar-alert" href="<?= esc_url($sidebarPluginAlert['url']) ?>" title="<?= esc_attr($sidebarPluginAlert['title']) ?>">
                <?= $sidebarPluginAlert['label'] ?>
            </a>
        <?php endforeach; ?>
        <?php /* LP-154: same shared control row as the one right above .lp-admin__user below — see that one's comment. */ ?>
        <div class="lp-admin__sidebar-controls">
            <button type="button" class="lp-admin__nav-toggle-all" data-lp-nav-toggle-all aria-label="Expand all menu sections">&#8862;</button>
            <button type="button" class="lp-admin__sidebar-mode-toggle" data-lp-sidebar-mode-toggle aria-pressed="false" aria-label="Expand sidebar">&raquo;</button>
        </div>
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
                                <a href="<?= esc_url(admin_url("{$slug}/{$item['default_child']}")) ?>" title="<?= esc_attr($item['label']) ?>"><span class="lp-admin__nav-icon" aria-hidden="true"><?= esc_html($item['icon']) ?></span><span class="lp-admin__nav-label"><?= esc_html($item['label']) ?></span></a>
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
                            <a href="<?= esc_url(admin_url($slug)) ?>" title="<?= esc_attr($item['label']) ?>"><span class="lp-admin__nav-icon" aria-hidden="true"><?= esc_html($item['icon']) ?></span><span class="lp-admin__nav-label"><?= esc_html($item['label']) ?></span></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php /* LP-151/LP-154: one shared row for LP-092's expand/collapse-all toggle (hidden in collapsed-sidebar mode; only makes sense in the expanded layout) and the sidebar-mode switch between collapsed/icon and classic full-width (always visible in both modes) — see admin.css's `.lp-admin__sidebar-controls`/`html:not(.lp-admin-sidebar-expanded)` rules and admin/assets/js/sidebar-mode.js. */ ?>
        <div class="lp-admin__sidebar-controls">
            <button type="button" class="lp-admin__nav-toggle-all" data-lp-nav-toggle-all aria-label="Expand all menu sections">&#8862;</button>
            <button type="button" class="lp-admin__sidebar-mode-toggle" data-lp-sidebar-mode-toggle aria-pressed="false" aria-label="Expand sidebar">&raquo;</button>
        </div>
        <div class="lp-admin__user">
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
    <?php /* Dismissible backdrop behind the mobile off-canvas sidebar drawer; hidden entirely above the 782px breakpoint (see admin.css), and above it via display:none regardless of state. */ ?>
    <div class="lp-admin__sidebar-backdrop" data-lp-mobile-nav-backdrop></div>
    <main id="lp-admin-content" class="lp-admin__content">
        <?php /* Hamburger toggle for the mobile off-canvas sidebar; hidden entirely above the 782px breakpoint via admin.css, not just visually collapsed, so it's never in the desktop tab order. */ ?>
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
