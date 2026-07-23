<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var array<string, array{label: string, capability: string|null}> $menu */
/** @var string $page */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<h1 class="lp-admin__title"><?= esc_html($menu[$page]['label']) ?></h1>
<section class="lp-admin__panel">
    <p>This section is part of the Lumora Press foundation and will be built out in a future phase.</p>
</section>
