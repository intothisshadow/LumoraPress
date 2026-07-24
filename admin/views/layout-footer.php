<?php
/** @var \LumoraPress\Core\Kernel $kernel */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
    </main>
</div>
<script src="<?= esc_url(admin_asset_url('js/tag-input.js')) ?>" defer></script>
<script src="<?= esc_url(admin_asset_url('js/thumbnail-bulk.js')) ?>" defer></script>
</body>
</html>
