<?php

/**
 * The admin Lumora Gallery Shortcodes > Shortcodes screen (LPP-015): documents [lumora_gallery_album]/[lumora_gallery_newest] for site admins.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Plugins\LumoraGalleryShortcodes\GallerySettingsService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LPP-015. Only reachable while the plugin is active (admin/index.php
 * only adds this menu entry in that case), so GallerySettingsService's
 * class is guaranteed to already be loaded — PluginManager::loadActive()
 * required content/plugins/lumora-gallery-shortcodes/
 * lumora-gallery-shortcodes.php earlier this same request, in
 * include/bootstrap.php. Mirrors settings.php's identical reasoning.
 *
 * Unlike Downloads' own Shortcodes page (admin/views/downloads/
 * shortcodes.php), which pulls real category names/IDs from this
 * site's own database for its example values, this plugin has no local
 * album data to draw from — every example below uses an obviously-a-
 * placeholder id/name rather than implying a real Gallery album exists.
 */
$configured = (new GallerySettingsService())->isConfigured();
?>
<h1 class="lp-admin__title">Lumora Gallery Shortcodes &mdash; Shortcodes</h1>

<?php if (!$configured): ?>
    <div class="lp-alert lp-alert--warning">
        No Gallery database connection is configured yet — both shortcodes
        below will render nothing until you set one up on
        <a href="<?= esc_url(admin_url('lumora-gallery-shortcodes/settings')) ?>">Lumora Gallery Shortcodes &rsaquo; Settings</a>.
    </div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2><code>[lumora_gallery_album]</code></h2>
    <p class="lp-field__hint">
        Embeds images from one album on the connected Gallery site —
        every image, the newest few, or a hand-picked set. Thumbnails
        link to the real full-size image and open it in this site's own
        PhotoSwipe lightbox. Renders nothing if the album can't be
        resolved (wrong id/folder, private album, or the Gallery
        database is unreachable).
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list">
        <li><span><code>album_id</code></span><span>An album's exact numeric ID, from the connected Gallery site's own database.</span></li>
        <li><span><code>folder</code></span><span>An album's folder path instead of its ID (e.g. <code>"xena/season-1"</code>). Ignored when <code>album_id</code> is also given.</span></li>
        <li><span><code>count</code></span><span>Show the newest <em>N</em> images in the album, newest first, instead of every image. Needs <code>album_id</code>/<code>folder</code> to know which album.</span></li>
        <li><span><code>image_id</code></span><span>One or more specific images by id, comma-separated (e.g. <code>"4,9,12"</code>). An image id is already globally unique in the Gallery's own schema, so <code>album_id</code>/<code>folder</code> is optional here — given anyway, it only decides which album the "View album" link points at.</span></li>
    </ul>

    <h3>Examples</h3>
    <p class="lp-field__hint">Every image in an album, by ID:</p>
    <pre><code>[lumora_gallery_album album_id="12"]</code></pre>

    <p class="lp-field__hint">Every image in an album, by folder path:</p>
    <pre><code>[lumora_gallery_album folder="some-album"]</code></pre>

    <p class="lp-field__hint">The newest 6 images in an album:</p>
    <pre><code>[lumora_gallery_album album_id="12" count="6"]</code></pre>

    <p class="lp-field__hint">Specific images, in the order given:</p>
    <pre><code>[lumora_gallery_album image_id="4,9,12"]</code></pre>
</section>

<section class="lp-admin__panel">
    <h2><code>[lumora_gallery_newest]</code></h2>
    <p class="lp-field__hint">
        The newest images across the entire connected Gallery site —
        every public album, approved images only — not scoped to one
        album. Links back to the Gallery site itself rather than one
        album's page, since the images shown can each belong to a
        different album.
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list">
        <li><span><code>count</code></span><span>How many images to show, newest first. Defaults to 10 if omitted.</span></li>
    </ul>

    <h3>Examples</h3>
    <p class="lp-field__hint">The newest 10 images gallery-wide (the default):</p>
    <pre><code>[lumora_gallery_newest]</code></pre>

    <p class="lp-field__hint">The newest 20:</p>
    <pre><code>[lumora_gallery_newest count="20"]</code></pre>
</section>
