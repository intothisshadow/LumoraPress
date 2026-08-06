<?php

/**
 * The shared admin page chrome's closing markup, included at the bottom of every admin view.
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

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
    </main>
</div>
<script src="<?= esc_url(admin_asset_url('js/nav-toggle.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/dynamic-style.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/admin-tabs.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/tag-input.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/category-quick-add.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/url-preview.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/custom-fields.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/featured-image-crop.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/thumbnail-bulk.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/theme-browser.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/plugin-browser.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/content-editor.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/theme-file-editor.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/folder-drag-drop.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/select-all.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/confirm-submit.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/auto-submit.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/color-field-reset.js')) ?>" defer></script>
<?php if (\LumoraPress\Core\Theme\MediaViewer::isUsed()): ?>
    <!-- Media Viewer & Lightbox (LP-031) — see content/themes/default/footer.php's identical block for why this is CDN-loaded and conditional. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.css">
    <script type="module" src="<?= esc_url(admin_asset_url('js/media-viewer.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
