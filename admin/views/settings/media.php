<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-042: Thumbnails and Media Import were previously two of several
 * sections on one big admin/views/settings.php — moved here verbatim
 * (same option keys, same CSRF action names) as Settings > Media now
 * that Settings has sub-pages.
 */
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'thumbnail_settings' && Csrf::verify('thumbnail_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    foreach (['small', 'medium', 'large'] as $sizeName) {
        $kernel->config->setOption("thumbnail_size_{$sizeName}_width", (string) max(1, (int) ($_POST["thumbnail_size_{$sizeName}_width"] ?? 150)));
        $kernel->config->setOption("thumbnail_size_{$sizeName}_height", (string) max(1, (int) ($_POST["thumbnail_size_{$sizeName}_height"] ?? 150)));
        $kernel->config->setOption("thumbnail_size_{$sizeName}_mode", ($_POST["thumbnail_size_{$sizeName}_mode"] ?? 'fit') === 'crop' ? 'crop' : 'fit');
        $kernel->config->setOption("thumbnail_size_{$sizeName}_enabled", ($_POST["thumbnail_size_{$sizeName}_enabled"] ?? '') === '1' ? '1' : '0');
    }
    $kernel->config->setOption('thumbnail_jpeg_quality', (string) max(0, min(100, (int) ($_POST['thumbnail_jpeg_quality'] ?? 82))));
    $kernel->config->setOption('thumbnail_webp_quality', (string) max(0, min(100, (int) ($_POST['thumbnail_webp_quality'] ?? 80))));
    $kernel->config->setOption('thumbnail_sharpen', ($_POST['thumbnail_sharpen'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('thumbnail_max_pixels', (string) max(1, (int) ($_POST['thumbnail_max_pixels'] ?? 25_000_000)));
    $kernel->config->setOption('default_featured_image_media_id', (string) max(0, (int) ($_POST['default_featured_image_media_id'] ?? 0)));

    header('Location: ' . admin_url('settings/media') . '?saved=1');
    exit;
} elseif ($form === 'media_import_settings' && Csrf::verify('media_import_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['media_import_allowed_directories'] ?? '')) ?: [];
    $directories = array_values(array_unique(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '')));
    $kernel->config->setOption('media_import_allowed_directories', json_encode($directories));

    header('Location: ' . admin_url('settings/media') . '?saved=1');
    exit;
} elseif ($form === 'media_stats_settings' && Csrf::verify('media_stats_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('media_track_downloads', ($_POST['media_track_downloads'] ?? '') === '1' ? '1' : '0');

    header('Location: ' . admin_url('settings/media') . '?saved=1');
    exit;
}
?>
<h1 class="lp-admin__title">Media</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Thumbnails</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/media')) ?>">
        <?= Csrf::field('thumbnail_settings') ?>
        <input type="hidden" name="form" value="thumbnail_settings">

        <?php foreach (['small' => ['150', '150', 'crop'], 'medium' => ['300', '300', 'fit'], 'large' => ['1024', '1024', 'fit']] as $sizeName => $defaults): ?>
            <fieldset class="lp-field">
                <legend><?= esc_html(ucfirst($sizeName)) ?></legend>

                <label class="lp-field--checkbox">
                    <input type="checkbox" name="thumbnail_size_<?= $sizeName ?>_enabled" value="1" <?= $kernel->config->option("thumbnail_size_{$sizeName}_enabled", '1') !== '0' ? 'checked' : '' ?>>
                    Enabled
                </label>

                <label for="thumbnail-<?= $sizeName ?>-width">Width</label>
                <input type="number" id="thumbnail-<?= $sizeName ?>-width" name="thumbnail_size_<?= $sizeName ?>_width" min="1" value="<?= esc_attr((string) $kernel->config->option("thumbnail_size_{$sizeName}_width", $defaults[0])) ?>">

                <label for="thumbnail-<?= $sizeName ?>-height">Height</label>
                <input type="number" id="thumbnail-<?= $sizeName ?>-height" name="thumbnail_size_<?= $sizeName ?>_height" min="1" value="<?= esc_attr((string) $kernel->config->option("thumbnail_size_{$sizeName}_height", $defaults[1])) ?>">

                <label for="thumbnail-<?= $sizeName ?>-mode">Mode</label>
                <select id="thumbnail-<?= $sizeName ?>-mode" name="thumbnail_size_<?= $sizeName ?>_mode">
                    <option value="fit" <?= $kernel->config->option("thumbnail_size_{$sizeName}_mode", $defaults[2]) === 'fit' ? 'selected' : '' ?>>Fit (preserve aspect ratio)</option>
                    <option value="crop" <?= $kernel->config->option("thumbnail_size_{$sizeName}_mode", $defaults[2]) === 'crop' ? 'selected' : '' ?>>Crop (exact dimensions)</option>
                </select>
            </fieldset>
        <?php endforeach; ?>

        <p class="lp-field">
            <label for="thumbnail-jpeg-quality">JPEG quality (0&ndash;100)</label>
            <input type="number" id="thumbnail-jpeg-quality" name="thumbnail_jpeg_quality" min="0" max="100" value="<?= esc_attr((string) $kernel->config->option('thumbnail_jpeg_quality', '82')) ?>">
        </p>

        <p class="lp-field">
            <label for="thumbnail-webp-quality">WebP quality (0&ndash;100)</label>
            <input type="number" id="thumbnail-webp-quality" name="thumbnail_webp_quality" min="0" max="100" value="<?= esc_attr((string) $kernel->config->option('thumbnail_webp_quality', '80')) ?>">
        </p>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="thumbnail_sharpen" value="1" <?= $kernel->config->option('thumbnail_sharpen', '0') === '1' ? 'checked' : '' ?>>
            Sharpen thumbnails after resizing
        </label>

        <p class="lp-field">
            <label for="thumbnail-max-pixels">Maximum source image pixels (memory safeguard)</label>
            <input type="number" id="thumbnail-max-pixels" name="thumbnail_max_pixels" min="1" value="<?= esc_attr((string) $kernel->config->option('thumbnail_max_pixels', '25000000')) ?>">
            <span class="lp-field__hint">Images larger than this are skipped (logged) rather than generating thumbnails for them.</span>
        </p>

        <p class="lp-field">
            <label for="default-featured-image">Default featured image (LP-040)</label>
            <select id="default-featured-image" name="default_featured_image_media_id">
                <option value="0">(None)</option>
                <?php foreach ($kernel->media->query(['type' => 'image'], 500, 0)['items'] as $imageOption): ?>
                    <option value="<?= (int) $imageOption['id'] ?>" <?= (int) $kernel->config->option('default_featured_image_media_id', '0') === (int) $imageOption['id'] ? 'selected' : '' ?>>
                        <?= esc_html((string) $imageOption['file_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">Used as the featured image (and Open Graph/Twitter Card image) for posts/pages that don't have one of their own.</span>
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Media Import</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/media')) ?>">
        <?= Csrf::field('media_import_settings') ?>
        <input type="hidden" name="form" value="media_import_settings">

        <p class="lp-field">
            <label for="media-import-directories">Allowed import directories (one absolute path per line)</label>
            <textarea id="media-import-directories" name="media_import_allowed_directories" rows="4"><?= esc_html(implode("\n", (array) (json_decode((string) $kernel->config->option('media_import_allowed_directories', '[]'), true) ?: []))) ?></textarea>
            <span class="lp-field__hint">Only these directories (and their subdirectories) can be scanned from Media Manager &rarr; Import from Server. Leave empty to disable server import entirely.</span>
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Statistics</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/media')) ?>">
        <?= Csrf::field('media_stats_settings') ?>
        <input type="hidden" name="form" value="media_stats_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="media_track_downloads" value="1" <?= $kernel->config->option('media_track_downloads', '1') !== '0' ? 'checked' : '' ?>>
            Track file downloads
        </label>
        <span class="lp-field__hint">Counts a download each time a document, archive, audio, or video file is fetched through its <code>/media/{id}/download</code> link, shown on that file's Media Manager details page. Image "views" aren't tracked &mdash; see <a href="<?= esc_url(admin_url('media')) ?>">Media Manager</a>'s built-in views for what's available.</span>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>
