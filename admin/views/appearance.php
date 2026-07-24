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

if ($form === 'activate_theme' && Csrf::verify('activate_theme', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $known = array_filter($kernel->themes->discover(), static fn ($info): bool => $info->slug === $slug);

    if ($known === []) {
        $error = 'That theme could not be found.';
    } else {
        $kernel->config->setOption('active_theme', $slug);

        header('Location: ' . admin_url('appearance') . '?saved=1');
        exit;
    }
} elseif ($form === 'install_theme' && Csrf::verify('install_theme', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    if (!isset($_FILES['theme_zip']) || $_FILES['theme_zip']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a ZIP file to upload.';
    } elseif ($_FILES['theme_zip']['error'] !== UPLOAD_ERR_OK) {
        $error = 'The file upload failed. Please try again.';
    } else {
        try {
            $installed = $kernel->themeInstaller->install($_FILES['theme_zip']['tmp_name']);

            header('Location: ' . admin_url('appearance') . '?installed=' . urlencode($installed->name));
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
        header('Location: ' . admin_url('appearance') . '?saved=1');
        exit;
    }
} elseif ($form === 'custom_css' && Csrf::verify('custom_css', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('custom_css', (string) ($_POST['custom_css'] ?? ''));

    header('Location: ' . admin_url('appearance') . '?saved=1');
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

<section class="lp-admin__panel">
    <h2>Themes</h2>
    <div class="lp-theme-grid">
        <?php foreach ($themeList as $info): ?>
            <div class="lp-theme-card<?= $info->isActive ? ' lp-theme-card--active' : '' ?>">
                <?php if ($info->screenshotUrl !== null): ?>
                    <img class="lp-theme-card__screenshot" src="<?= esc_url($info->screenshotUrl) ?>" alt="">
                <?php else: ?>
                    <div class="lp-theme-card__screenshot lp-theme-card__screenshot--placeholder" aria-hidden="true"></div>
                <?php endif; ?>
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
                    <?php if (!$info->isActive): ?>
                        <form method="post" action="<?= esc_url(admin_url('appearance')) ?>">
                            <?= Csrf::field('activate_theme') ?>
                            <input type="hidden" name="form" value="activate_theme">
                            <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                            <button type="submit" class="lp-button">Activate</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <h3>Install a Theme</h3>
    <form method="post" action="<?= esc_url(admin_url('appearance')) ?>" enctype="multipart/form-data">
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
    <form method="post" action="<?= esc_url(admin_url('appearance')) ?>" enctype="multipart/form-data">
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
    <form method="post" action="<?= esc_url(admin_url('appearance')) ?>">
        <?= Csrf::field('custom_css') ?>
        <input type="hidden" name="form" value="custom_css">

        <p class="lp-field">
            <label for="custom-css">Additional CSS, applied on every public page</label>
            <textarea id="custom-css" name="custom_css" rows="12" class="lp-code-textarea"><?= esc_html((string) $kernel->config->option('custom_css', '')) ?></textarea>
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>
