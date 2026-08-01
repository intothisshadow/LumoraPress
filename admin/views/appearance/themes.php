<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * Three independent sections (LP-034): Theme Management, Branding, and
 * Custom CSS — each with its own "form" value and CSRF action name, same
 * dispatch pattern admin/views/settings.php uses for Feeds/Search/
 * Maintenance.
 */
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$postedSlug = trim((string) ($_POST['slug'] ?? ''));

/*
 * Every theme card (and its details-template counterpart) renders its own
 * Activate/Delete form, so this page has many forms sharing the page at
 * once. Csrf::field()/verify() are keyed by action *name*, and
 * Csrf::field() overwrites the session's token for a given name on every
 * call, so a bare 'activate_theme'/'delete_theme' name shared across all
 * of them would leave every form but the last-rendered one silently
 * submitting an already-invalidated token (see widgets.php's/menus.php's
 * own docblocks for the LP-012 incident this exact mistake caused). Each
 * action name below is scoped to the specific theme slug it acts on
 * instead.
 */
$csrfAction = match ($form) {
    'activate_theme' => 'activate_theme_' . $postedSlug,
    'delete_theme' => 'delete_theme_' . $postedSlug,
    default => $form,
};

if ($form === 'activate_theme' && Csrf::verify($csrfAction, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $slug = $postedSlug;
    $known = array_filter($kernel->themes->discover(), static fn ($info): bool => $info->slug === $slug);

    if ($known === []) {
        $error = 'That theme could not be found.';
    } else {
        $kernel->config->setOption('active_theme', $slug);

        header('Location: ' . admin_url('appearance/themes') . '?saved=1');
        exit;
    }
} elseif ($form === 'delete_theme' && Csrf::verify($csrfAction, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $slug = $postedSlug;
    $target = null;

    foreach ($kernel->themes->discover() as $info) {
        if ($info->slug === $slug) {
            $target = $info;

            break;
        }
    }

    if ($target === null) {
        $error = 'That theme could not be found.';
    } elseif ($target->isActive) {
        $error = 'The active theme cannot be deleted. Activate a different theme first.';
    } else {
        try {
            $kernel->themeInstaller->delete($slug);

            header('Location: ' . admin_url('appearance/themes') . '?deleted=1');
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
} elseif ($form === 'install_theme' && Csrf::verify('install_theme', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    if (!isset($_FILES['theme_zip']) || $_FILES['theme_zip']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a ZIP file to upload.';
    } elseif ($_FILES['theme_zip']['error'] !== UPLOAD_ERR_OK) {
        $error = 'The file upload failed. Please try again.';
    } else {
        try {
            $installed = $kernel->themeInstaller->install($_FILES['theme_zip']['tmp_name']);

            header('Location: ' . admin_url('appearance/themes') . '?installed=' . urlencode($installed->name));
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
} elseif ($form === 'branding' && Csrf::verify('branding', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('site_name', trim((string) ($_POST['site_name'] ?? '')));

    if (($_POST['remove_logo'] ?? '') === '1') {
        $kernel->config->setOption('site_logo_media_id', '');
    } elseif (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploaded = $kernel->media->upload($_FILES['logo'], $currentUser->id);
            $kernel->config->setOption('site_logo_media_id', (string) $uploaded['id']);
        } catch (\Throwable $exception) {
            $error = 'Logo upload failed: ' . $exception->getMessage();
        }
    }

    if (($_POST['remove_favicon'] ?? '') === '1') {
        $kernel->config->setOption('favicon_media_id', '');
    } elseif (isset($_FILES['favicon']) && $_FILES['favicon']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploaded = $kernel->media->upload($_FILES['favicon'], $currentUser->id);
            $kernel->config->setOption('favicon_media_id', (string) $uploaded['id']);
        } catch (\Throwable $exception) {
            $error = 'Favicon upload failed: ' . $exception->getMessage();
        }
    }

    if ($error === null) {
        header('Location: ' . admin_url('appearance/themes') . '?saved=1');
        exit;
    }
} elseif ($form === 'custom_css' && Csrf::verify('custom_css', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('custom_css', (string) ($_POST['custom_css'] ?? ''));

    header('Location: ' . admin_url('appearance/themes') . '?saved=1');
    exit;
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

<section class="lp-admin__panel">
    <h2>Themes</h2>

    <?php if (count($themeList) > 1): ?>
        <p class="lp-field lp-theme-search">
            <label for="theme-search">Search themes</label>
            <input type="search" id="theme-search" data-lp-theme-search placeholder="Search by name, author, or tag&hellip;">
        </p>
    <?php endif; ?>

    <div class="lp-theme-grid" data-lp-theme-grid>
        <?php foreach ($themeList as $info): ?>
            <?php
            $searchHaystack = strtolower($info->name . ' ' . $info->author . ' ' . implode(' ', $info->tags));
            $templateId = 'lp-theme-details-' . $info->slug;
            $previewUrl = site_url('') . '?lp_preview_theme=' . rawurlencode($info->slug);
            ?>
            <div class="lp-theme-card<?= $info->isActive ? ' lp-theme-card--active' : '' ?>" data-lp-theme-card data-theme-search="<?= esc_attr($searchHaystack) ?>">
                <div class="lp-theme-card__screenshot-wrap">
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
                                <?= Csrf::field('activate_theme_' . $info->slug) ?>
                                <input type="hidden" name="form" value="activate_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button">Activate</button>
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
                                <?= Csrf::field('activate_theme_' . $info->slug) ?>
                                <input type="hidden" name="form" value="activate_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--primary">Activate</button>
                            </form>
                            <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>" class="lp-admin__inline-form" onsubmit="return confirm('Delete this theme permanently? This cannot be undone.');">
                                <?= Csrf::field('delete_theme_' . $info->slug) ?>
                                <input type="hidden" name="form" value="delete_theme">
                                <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                <button type="submit" class="lp-button lp-button--danger">Delete</button>
                            </form>
                        <?php endif; ?>
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

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Custom CSS</h2>
    <form method="post" action="<?= esc_url(admin_url('appearance/themes')) ?>">
        <?= Csrf::field('custom_css') ?>
        <input type="hidden" name="form" value="custom_css">

        <p class="lp-field">
            <label for="custom-css">Additional CSS, applied on every public page</label>
            <textarea id="custom-css" name="custom_css" rows="12" class="lp-code-textarea"><?= esc_html((string) $kernel->config->option('custom_css', '')) ?></textarea>
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>
