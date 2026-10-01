<?php

/**
 * The admin Media Manager > Shortcodes screen: documents the shortcodes built into core, and points to the ones plugins provide.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.20.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */
/** @var bool $fontAwesomeActive */
/** @var bool $contactFormsActive */
/** @var bool $downloadsActive */
/** @var bool $linkDirectoryActive */
/** @var bool $galleryShortcodesActive */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// A real folder gives the examples a working id; fall back to a placeholder on a fresh site.
$exampleFolder = $kernel->folders->listAll()[0] ?? null;
$exampleFolderId = (int) ($exampleFolder->id ?? 1);
?>
<h1 class="lp-admin__title">Media Manager &mdash; Shortcodes</h1>

<p class="lp-field__hint">
    A shortcode is a short tag in square brackets you type into a post or page to show something the editor can't
    write for you. The Media buttons in the Visual and Markdown editors fill these in for you; this page lists what
    each one accepts. A shortcode with something missing or wrong in it shows nothing instead of an error.
</p>

<section class="lp-admin__panel">
    <h2><code>[lumora_folder_gallery]</code></h2>
    <p class="lp-field__hint">
        Shows the images in a Media folder as a grid of thumbnails. It reads the folder each time the page is shown,
        so an image added to or removed from the folder later appears or disappears without editing the page. At
        most 200 images are shown. Use the <strong>Insert Folder</strong> editor button to build it.
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list lp-admin__meta-list--attributes">
        <li><span><code>folder_id</code></span><span>The folder's numeric ID (required); the Insert Folder button fills it in for you. A folder that doesn't exist, or has no images, shows nothing.</span></li>
        <li><span><code>size</code></span><span>The thumbnail size: <code>small</code> (default), <code>medium</code>, <code>large</code> or <code>full</code>.</span></li>
        <li><span><code>link</code></span><span><code>none</code> (default) shows plain thumbnails; <code>full</code> makes each one open the full-size image in the lightbox.</span></li>
    </ul>

    <h3>Examples</h3>
    <pre><code>[lumora_folder_gallery folder_id="<?= $exampleFolderId ?>"]</code></pre>
    <pre><code>[lumora_folder_gallery folder_id="<?= $exampleFolderId ?>" size="medium" link="full"]</code></pre>
</section>

<section class="lp-admin__panel">
    <h2><code>[lumora_audio]</code> and <code>[lumora_video]</code></h2>
    <p class="lp-field__hint">
        Show an audio or video file from the Media Manager in a player. The file is looked up each time the page is
        shown, so swapping a video's poster image later updates every page that shows it. The player skin loads
        only on pages that contain one. Use the <strong>Insert Audio</strong> and <strong>Insert Video</strong>
        editor buttons to build them.
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list lp-admin__meta-list--attributes">
        <li><span><code>id</code></span><span>The Media item's numeric ID (required); the Insert Audio and Insert Video buttons fill it in for you. It must be an audio file for <code>[lumora_audio]</code> and a video file for <code>[lumora_video]</code>; anything else shows nothing.</span></li>
    </ul>
    <p class="lp-field__hint">
        The player's caption comes from the file's Caption field. A video also shows the poster image and subtitle
        track chosen on its Media details page.
    </p>

    <h3>Examples</h3>
    <pre><code>[lumora_audio id="12"]</code></pre>
    <pre><code>[lumora_video id="34"]</code></pre>
</section>

<section class="lp-admin__panel">
    <h2>Shortcodes from plugins</h2>
    <p class="lp-field__hint">These appear only while their plugin is active.</p>

    <?php if (!($fontAwesomeActive ?? false) && !($contactFormsActive ?? false) && !($downloadsActive ?? false) && !($linkDirectoryActive ?? false) && !($galleryShortcodesActive ?? false)): ?>
        <p class="lp-admin__widget-placeholder">No plugin that adds shortcodes is active.</p>
    <?php endif; ?>

    <ul class="lp-admin__meta-list lp-admin__meta-list--attributes">
        <?php if ($fontAwesomeActive ?? false): ?>
            <li><span><code>[icon name="camera"]</code></span><span>An icon from the Font Awesome library, from the Font Awesome plugin. Optional: <code>style</code> (<code>solid</code>, <code>regular</code>, <code>brands</code>, <code>light</code>, <code>thin</code>, <code>duotone</code>), <code>label</code> (read aloud by screen readers), <code>size</code> (<code>xs</code>, <code>sm</code>, <code>lg</code>, <code>1x</code> to <code>10x</code>), <code>rotate</code> (<code>90</code>, <code>180</code>, <code>270</code>), <code>flip</code> (<code>horizontal</code>, <code>vertical</code>, <code>both</code>), <code>animation</code> (<code>spin</code>, <code>pulse</code>, <code>beat</code>, <code>fade</code>, <code>bounce</code>, <code>shake</code> and a few more), <code>color</code> and <code>class</code>. The <strong>Insert Icon</strong> editor button picks the icon for you. See <a href="<?= esc_url(admin_url('appearance/font-awesome')) ?>">Appearance &rsaquo; Font Awesome</a>.</span></li>
        <?php endif; ?>
        <?php if ($contactFormsActive ?? false): ?>
            <li><span><code>[contact_form id="1"]</code></span><span>A contact form from the Contact Forms plugin. The <code>id</code> is the form's number; every form's ready-to-copy shortcode is listed on <a href="<?= esc_url(admin_url('contact-forms/all-forms')) ?>">Contact Forms &rsaquo; All Forms</a>.</span></li>
        <?php endif; ?>
        <?php if ($downloadsActive ?? false): ?>
            <li><span><code>[lumora_downloads]</code>, <code>[lumora_recent_downloads]</code></span><span>Lists of downloads, from the Downloads plugin. See <a href="<?= esc_url(admin_url('downloads/shortcodes')) ?>">Downloads &rsaquo; Shortcodes</a>.</span></li>
        <?php endif; ?>
        <?php if ($linkDirectoryActive ?? false): ?>
            <li><span><code>[lumora_link_directory]</code></span><span>A list of links, from the Link Directory plugin. See <a href="<?= esc_url(admin_url('link-directory/shortcodes')) ?>">Link Directory &rsaquo; Shortcodes</a>.</span></li>
        <?php endif; ?>
        <?php if ($galleryShortcodesActive ?? false): ?>
            <li><span><code>[lumora_gallery_album]</code>, <code>[lumora_gallery_newest]</code></span><span>Albums and newest images from a Lumora Gallery install, from the Lumora Gallery Shortcodes plugin. See <a href="<?= esc_url(admin_url('lumora-gallery-shortcodes/shortcodes')) ?>">Lumora Gallery &rsaquo; Shortcodes</a>.</span></li>
        <?php endif; ?>
    </ul>
</section>
