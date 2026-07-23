<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var array<string, array{label: string, capability: string|null}> $menu */
/** @var string $page */
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
    <title><?= esc_html($menu[$page]['label']) ?> &lsaquo; Lumora Press</title>
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
        <nav class="lp-admin__nav" aria-label="Admin menu">
            <ul>
                <?php foreach ($menu as $slug => $item): ?>
                    <?php if ($item['capability'] !== null && !$currentUser->can($item['capability'])) {
                        continue;
                    } ?>
                    <li class="lp-admin__nav-item<?= $slug === $page ? ' is-active' : '' ?>">
                        <a href="<?= esc_url(admin_url($slug)) ?>"><?= esc_html($item['label']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="lp-admin__user">
            <span class="lp-admin__user-name"><?= esc_html($currentUser->displayName) ?></span>
            <a class="lp-admin__logout" href="<?= esc_url(admin_url('logout')) ?>">Log Out</a>
        </div>
    </aside>
    <main id="lp-admin-content" class="lp-admin__content">
