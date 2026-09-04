<?php

/**
 * The admin Settings > Media screen: upload and statistics options.
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

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'media_stats_settings' && Csrf::verify('media_stats_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('media_track_downloads', ($_POST['media_track_downloads'] ?? '') === '1' ? '1' : '0');

    header('Location: ' . admin_url('settings/media') . '?saved=1');
    exit;
} elseif ($form === 'lightbox_settings' && Csrf::verify('lightbox_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('lightbox_show_filenames', ($_POST['lightbox_show_filenames'] ?? '') === '1' ? '1' : '0');

    // 'full' plus every enabled registered thumbnail size are the only
    // valid values; an unrecognized value falls back to 'large' rather
    // than being stored as-is.
    $allowedLightboxSizes = ['full', ...array_keys(array_filter($kernel->thumbnails->sizes(), static fn (array $size): bool => $size['enabled']))];
    $submittedLightboxSize = is_string($_POST['lightbox_large_size'] ?? null) ? $_POST['lightbox_large_size'] : '';
    $kernel->config->setOption('lightbox_large_size', in_array($submittedLightboxSize, $allowedLightboxSizes, true) ? $submittedLightboxSize : 'large');

    header('Location: ' . admin_url('settings/media') . '?saved=1');
    exit;
}
?>
<h1 class="lp-admin__title">Media</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Statistics</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/media')) ?>">
        <?= Csrf::field('media_stats_settings') ?>
        <input type="hidden" name="form" value="media_stats_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="media_track_downloads" value="1" <?= $kernel->config->option('media_track_downloads', '1') !== '0' ? 'checked' : '' ?>>
            Track file downloads
        </label>
        <span class="lp-field__hint">Counts a download each time a document, archive, audio, or video file is fetched through its <code>/media/{id}/download</code> link, shown on that file's Media Manager details page. Image "views" aren't tracked &mdash; see <a href="<?= esc_url(admin_url('media/media')) ?>">Media Manager</a>'s built-in views for what's available.</span>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<?php
$lightboxSizeOptions = [];

foreach ($kernel->thumbnails->sizes() as $sizeName => $sizeInfo) {
    if ($sizeInfo['enabled']) {
        // The raw × character, not the &times; HTML entity — this
        // string is passed through esc_html() at output, which would
        // otherwise escape the entity's own "&" and show the literal
        // text "&times;" instead of rendering the symbol.
        $lightboxSizeOptions[$sizeName] = ucfirst($sizeName) . ' (' . $sizeInfo['width'] . '×' . $sizeInfo['height'] . ')';
    }
}

$lightboxSizeOptions['full'] = 'Original (full size)';
$currentLightboxSize = (string) $kernel->config->option('lightbox_large_size', 'large');
?>
<section class="lp-admin__panel">
    <h2>Media Viewer &amp; Lightbox</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/media')) ?>">
        <?= Csrf::field('lightbox_settings') ?>
        <input type="hidden" name="form" value="lightbox_settings">

        <p class="lp-field">
            <label for="lightbox-large-size">Lightbox image size</label>
            <select id="lightbox-large-size" name="lightbox_large_size">
                <?php foreach ($lightboxSizeOptions as $sizeName => $label): ?>
                    <option value="<?= esc_attr($sizeName) ?>" <?= $currentLightboxSize === $sizeName ? 'selected' : '' ?>><?= esc_html($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">Which size the lightbox loads when opened. Defaults to <strong>Large</strong> rather than the original — avoids loading a full-resolution file just to display it scaled down in a browser window. Choose <strong>Original (full size)</strong> to always show the raw upload instead.</span>
        </p>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="lightbox_show_filenames" value="1" <?= $kernel->config->option('lightbox_show_filenames', '0') === '1' ? 'checked' : '' ?>>
            Show filenames in the lightbox
        </label>
        <span class="lp-field__hint">Displays each image's filename alongside its dimensions at the bottom of the lightbox. Off by default, since filenames are rarely meaningful to visitors.</span>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
