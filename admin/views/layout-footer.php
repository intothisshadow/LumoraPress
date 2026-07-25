<?php
/** @var \LumoraPress\Core\Kernel $kernel */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
    </main>
</div>
<script src="<?= esc_url(admin_asset_url('js/nav-toggle.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/tag-input.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/thumbnail-bulk.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/theme-browser.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/plugin-browser.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/content-editor.js')) ?>" defer></script>
<?php if (\LumoraPress\Core\Theme\MediaViewer::isUsed()): ?>
    <!-- Media Viewer & Lightbox (LP-031) — see content/themes/default/footer.php's identical block for why this is CDN-loaded and conditional. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.css">
    <script type="module" src="<?= esc_url(admin_asset_url('js/media-viewer.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
