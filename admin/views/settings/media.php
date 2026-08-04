<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-042: this page originally held Thumbnails, Media Import, and
 * Statistics — moved here verbatim from one big admin/views/settings.php
 * now that Settings has sub-pages. LP-061 moved Thumbnails and Media
 * Import a second time, onto their corresponding LP-060 Media Manager
 * sub-pages (Thumbnails, Import from Server), since both are more
 * naturally reached from where the rest of that workflow lives. Only
 * Statistics remains here.
 */
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'media_stats_settings' && Csrf::verify('media_stats_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
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
