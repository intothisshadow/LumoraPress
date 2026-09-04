<?php

/**
 * The admin Appearance > Themes screen: switch, install, or remove themes.
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
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Controllers\Admin\ThemesController;
use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Two independent sections — Theme Management and Branding — each with its
// own "form" value. POST handling lives in ThemesController; this view
// dispatches to it and turns the result into a redirect or $error string.
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

if ($form !== '') {
    $controller = new ThemesController($kernel->themes, $kernel->themeInstaller, $kernel->config, $kernel->media);

    $result = match ($form) {
        'activate_theme' => $controller->activateTheme($_POST, $csrfToken),
        'delete_theme' => $controller->deleteTheme($_POST, $csrfToken),
        'bulk_delete_themes' => $controller->bulkDeleteThemes($_POST, $csrfToken),
        'update_theme' => $controller->updateTheme($_POST, $_FILES, $csrfToken),
        'install_theme' => $controller->installTheme($_FILES, $csrfToken),
        'branding' => $controller->saveBranding($_POST, $_FILES, $currentUser->id, $csrfToken),
        default => null,
    };

    if ($result !== null) {
        if ($result->redirectUrl !== null) {
            redirect($result->redirectUrl);
        }

        $error = $result->errorMessage;
    }
}

$themeList = $kernel->themes->discover();
$currentLogoId = (int) $kernel->config->option('site_logo_media_id', '');
$currentFaviconId = (int) $kernel->config->option('favicon_media_id', '');
$currentLogo = $currentLogoId > 0 ? $kernel->media->find($currentLogoId) : null;
$currentFavicon = $currentFaviconId > 0 ? $kernel->media->find($currentFaviconId) : null;
?>
<h1 class="lp-admin__title">Appearance</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['installed'])): ?>
    <div class="lp-alert lp-alert--success">Installed &ldquo;<?= esc_html((string) $_GET['installed']) ?>&rdquo;.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Theme deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['bulk_deleted'])): ?>
    <?php $bulkDeletedThemeCount = (int) $_GET['bulk_deleted']; ?>
    <?php $bulkSkippedThemeCount = (int) ($_GET['bulk_skipped'] ?? 0); ?>
    <div class="lp-alert lp-alert--success">
        <?= $bulkDeletedThemeCount ?> theme<?= $bulkDeletedThemeCount === 1 ? '' : 's' ?> deleted.
        <?php if ($bulkSkippedThemeCount > 0): ?>
            <?= $bulkSkippedThemeCount ?> skipped (active, or already removed).
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="lp-alert lp-alert--success">Updated &ldquo;<?= esc_html((string) $_GET['updated']) ?>&rdquo;.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Themes</h2>

    <?php if (count($themeList) > 1): ?>
        <p class="lp-field lp-theme-search">
            <label for="theme-search">Search themes</label>
            <input type="search" id="theme-search" data-lp-theme-search placeholder="Search by name, author, or tag&hellip;">
        </p>
    <?php endif; ?>

    <?php $hasDeletableThemes = array_filter($themeList, static fn (\LumoraPress\Core\Theme\ThemeInfo $info): bool => !$info->isActive) !== []; ?>

    <?php if ($hasDeletableThemes): ?>
        <form id="themes-bulk-form" method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__bulk-actions" data-lp-bulk-form>
            <?= Csrf::field('bulk_delete_themes') ?>
            <input type="hidden" name="form" value="bulk_delete_themes">
            <label class="lp-visually-hidden" for="themes-select-all">Select all</label>
            <input type="checkbox" id="themes-select-all" data-lp-select-all="theme_slugs[]" data-lp-select-all-scope="section">
            <label class="lp-visually-hidden" for="themes-bulk-action">Bulk action</label>
            <select id="themes-bulk-action" name="bulk_action">
                <option value="">Bulk actions</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="lp-button lp-button--secondary" data-lp-confirm="Delete the selected themes permanently? This cannot be undone.">Apply</button>
        </form>
    <?php endif; ?>

    <div class="lp-theme-grid" data-lp-theme-grid>
        <?php foreach ($themeList as $info): ?>
            <?php
            $searchHaystack = strtolower($info->name . ' ' . $info->author . ' ' . implode(' ', $info->tags));
            $templateId = 'lp-theme-details-' . $info->slug;
            $previewUrl = site_url('') . '?lp_preview_theme=' . rawurlencode($info->slug);
            // Computed once and reused by both the card and its details
            // template — a second Csrf::field() call for the same action
            // would overwrite the first form's token.
            $activateCsrfField = Csrf::field('activate_theme_' . $info->slug);
            $deleteCsrfField = Csrf::field('delete_theme_' . $info->slug);
            ?>
            <div class="lp-theme-card<?= $info->isActive ? ' lp-theme-card--active' : '' ?>" data-lp-theme-card data-theme-search="<?= esc_attr($searchHaystack) ?>">
                <div class="lp-theme-card__screenshot-wrap">
                    <?php if (!$info->isActive): ?>
                        <label class="lp-theme-card__select">
                            <span class="lp-visually-hidden">Select "<?= esc_html($info->name) ?>"</span>
                            <input type="checkbox" name="theme_slugs[]" value="<?= esc_attr($info->slug) ?>" form="themes-bulk-form">
                        </label>
                    <?php endif; ?>
                    <?php if ($info->screenshotUrl !== null): ?>
                        <img class="lp-theme-card__screenshot" src="<?= esc_url($info->screenshotUrl) ?>" alt="">
                    <?php else: ?>
                        <div class="lp-theme-card__screenshot lp-theme-card__screenshot--placeholder" aria-hidden="true"></div>
                    <?php endif; ?>
                </div>
                <div class="lp-theme-card__body">
                    <h3 class="lp-theme-card__name">
                        <?= esc_html($info->name) ?>
                        <?php if ($info->isActive): ?>
                            <span class="lp-theme-card__badge">Active</span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($info->description !== ''): ?>
                        <p class="lp-theme-card__description"><?= esc_html($info->description) ?></p>
                    <?php endif; ?>
                    <p class="lp-theme-card__meta">
                        <?php if ($info->version !== ''): ?><span>Version <?= esc_html($info->version) ?></span><?php endif; ?>
                        <?php if ($info->author !== ''): ?><span>By <?= esc_html($info->author) ?></span><?php endif; ?>
                    </p>
                    <div class="lp-theme-card__actions">
                        <button type="button" class="lp-button lp-button--secondary" data-lp-theme-details-trigger data-theme-template="<?= esc_attr($templateId) ?>">Details</button>
                        <?php if (!$info->isActive): ?>
                            <a class="lp-button lp-button--secondary" href="<?= esc_url($previewUrl) ?>" target="_blank" rel="noopener noreferrer">Preview</a>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form">
                                <?= $activateCsrfField ?>
                                <input type="hidden" name="form" value="activate_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--primary">Activate</button>
                            </form>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this theme permanently? This cannot be undone.">
                                <?= $deleteCsrfField ?>
                                <input type="hidden" name="form" value="delete_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <template id="<?= esc_attr($templateId) ?>">
                <div class="lp-theme-details">
                    <?php if ($info->screenshots !== []): ?>
                        <div class="lp-theme-details__gallery">
                            <?php foreach ($info->screenshots as $screenshot): ?>
                                <img src="<?= esc_url($screenshot) ?>" alt="<?= esc_attr($info->name) ?> screenshot">
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="lp-theme-details__gallery lp-theme-details__gallery--placeholder" aria-hidden="true"></div>
                    <?php endif; ?>

                    <h3 class="lp-theme-details__name">
                        <?= esc_html($info->name) ?>
                        <?php if ($info->isActive): ?><span class="lp-theme-card__badge">Active</span><?php endif; ?>
                    </h3>

                    <?php if ($info->description !== ''): ?>
                        <p class="lp-theme-details__description"><?= esc_html($info->description) ?></p>
                    <?php endif; ?>

                    <dl class="lp-theme-details__facts">
                        <?php if ($info->version !== ''): ?><dt>Version</dt><dd><?= esc_html($info->version) ?></dd><?php endif; ?>
                        <?php if ($info->author !== ''): ?>
                            <dt>Author</dt>
                            <dd><?php if ($info->authorUri !== ''): ?><a href="<?= esc_url($info->authorUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->author) ?></a><?php else: ?><?= esc_html($info->author) ?><?php endif; ?></dd>
                        <?php endif; ?>
                        <?php if ($info->themeUri !== ''): ?><dt>Homepage</dt><dd><a href="<?= esc_url($info->themeUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->themeUri) ?></a></dd><?php endif; ?>
                        <?php if ($info->license !== ''): ?>
                            <dt>License</dt>
                            <dd><?php if ($info->licenseUri !== ''): ?><a href="<?= esc_url($info->licenseUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->license) ?></a><?php else: ?><?= esc_html($info->license) ?><?php endif; ?></dd>
                        <?php endif; ?>
                        <?php if ($info->requiresAtLeast !== ''): ?><dt>Requires Lumora Press</dt><dd><?= esc_html($info->requiresAtLeast) ?>+</dd><?php endif; ?>
                        <?php if ($info->requiresPhp !== ''): ?><dt>Requires PHP</dt><dd><?= esc_html($info->requiresPhp) ?>+</dd><?php endif; ?>
                        <dt>Folder</dt>
                        <dd><code><?= esc_html($info->relativePath) ?></code></dd>
                    </dl>

                    <?php if ($info->tags !== []): ?>
                        <ul class="lp-theme-details__tags">
                            <?php foreach ($info->tags as $tag): ?>
                                <li><?= esc_html($tag) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($info->readmeFile !== null): ?>
                        <details class="lp-theme-details__document">
                            <summary>View README</summary>
                            <pre><?= esc_html((string) $kernel->themes->documentContent($info->slug, $info->readmeFile)) ?></pre>
                        </details>
                    <?php endif; ?>

                    <?php if ($info->changelogFile !== null): ?>
                        <details class="lp-theme-details__document">
                            <summary>View CHANGELOG</summary>
                            <pre><?= esc_html((string) $kernel->themes->documentContent($info->slug, $info->changelogFile)) ?></pre>
                        </details>
                    <?php endif; ?>

                    <?php do_action('lp_theme_details_panel', $info); ?>

                    <div class="lp-theme-details__actions">
                        <?php if (!$info->isActive): ?>
                            <a class="lp-button lp-button--secondary" href="<?= esc_url($previewUrl) ?>" target="_blank" rel="noopener noreferrer">Preview</a>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form">
                                <?= $activateCsrfField ?>
                                <input type="hidden" name="form" value="activate_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--primary">Activate</button>
                            </form>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this theme permanently? This cannot be undone.">
                                <?= $deleteCsrfField ?>
                                <input type="hidden" name="form" value="delete_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--danger">Delete</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" enctype="multipart/form-data" class="lp-admin__inline-form lp-theme-details__update-form" data-lp-confirm="This will overwrite this theme's current files with the contents of the uploaded ZIP. Continue?">
                            <?= Csrf::field('update_theme_' . $info->slug) ?>
                            <input type="hidden" name="form" value="update_theme">
                            <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                            <label class="lp-visually-hidden" for="<?= esc_attr($templateId) ?>-zip">Theme ZIP file to update &ldquo;<?= esc_html($info->name) ?>&rdquo; with</label>
                            <input type="file" id="<?= esc_attr($templateId) ?>-zip" name="theme_zip" accept=".zip" required>
                            <button type="submit" class="lp-button lp-button--secondary">Update from ZIP</button>
                        </form>
                        <button type="button" class="lp-button" data-lp-theme-dialog-close>Close</button>
                    </div>
                </div>
            </template>
        <?php endforeach; ?>
    </div>
    <p class="lp-theme-grid__empty" data-lp-theme-empty hidden>No themes match your search.</p>

    <dialog class="lp-theme-dialog" data-lp-theme-dialog aria-label="Theme details"></dialog>

    <h3>Install a Theme</h3>
    <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('install_theme') ?>
        <input type="hidden" name="form" value="install_theme">

        <p class="lp-field">
            <label for="theme-zip">Theme ZIP file</label>
            <input type="file" id="theme-zip" name="theme_zip" accept=".zip" required>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Upload &amp; Install</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Branding</h2>
    <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('branding') ?>
        <input type="hidden" name="form" value="branding">

        <p class="lp-field">
            <label for="site-name">Site name</label>
            <input type="text" id="site-name" name="site_name" value="<?= esc_attr((string) $kernel->config->option('site_name', '')) ?>">
        </p>

        <p class="lp-field">
            <label for="site-logo">Site logo</label>
            <?php if ($currentLogo !== null): ?>
                <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentLogo)) ?>" alt="Current site logo">
                <label class="lp-field--checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
            <?php endif; ?>
            <input type="file" id="site-logo" name="logo" accept="image/*">
        </p>

        <p class="lp-field">
            <label for="site-favicon">Favicon</label>
            <?php if ($currentFavicon !== null): ?>
                <img class="lp-branding-preview lp-branding-preview--favicon" src="<?= esc_url($kernel->media->url($currentFavicon)) ?>" alt="Current favicon">
                <label class="lp-field--checkbox"><input type="checkbox" name="remove_favicon" value="1"> Remove current favicon</label>
            <?php endif; ?>
            <input type="file" id="site-favicon" name="favicon" accept="image/png,image/x-icon,.ico">
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
