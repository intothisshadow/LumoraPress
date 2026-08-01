<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var array<string, array{label: string, icon: string, capability: string|null, default_child?: string, children?: array<string, array{label: string, icon: string, capability: string|null}>}> $menu */
/** @var string $page */
/** @var string|null $subpage */
/** @var array{label: string, capability: string|null} $activeEntry */
/** @var array<int, array{label: string, url: string|null}> $breadcrumbs */
/** @var \LumoraPress\Models\User $currentUser */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$version = require LUMORA_ROOT . '/version.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc_html($activeEntry['label']) ?> &lsaquo; Lumora Press</title>
    <link rel="stylesheet" href="<?= esc_url(admin_asset_url('css/admin.css')) ?>">
</head>
<body class="lp-admin">
<a class="lp-skip-link" href="#lp-admin-content">Skip to content</a>
<div class="lp-admin__shell">
    <aside class="lp-admin__sidebar">
        <div class="lp-admin__brand">
            Lumora Press
            <span class="lp-admin__version"><?= esc_html((string) $version['version']) ?></span>
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
                                <a href="<?= esc_url(admin_url("{$slug}/{$item['default_child']}")) ?>"><span class="lp-admin__nav-icon" aria-hidden="true"><?= esc_html($item['icon']) ?></span><?= esc_html($item['label']) ?></a>
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
                            <a href="<?= esc_url(admin_url($slug)) ?>"><span class="lp-admin__nav-icon" aria-hidden="true"><?= esc_html($item['icon']) ?></span><?= esc_html($item['label']) ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="lp-admin__user">
            <span class="lp-admin__user-name"><?= esc_html($currentUser->displayName) ?></span>
            <a class="lp-admin__logout" href="<?= esc_url(admin_url('logout')) ?>">Log Out</a>
        </div>
    </aside>
    <main id="lp-admin-content" class="lp-admin__content">
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
