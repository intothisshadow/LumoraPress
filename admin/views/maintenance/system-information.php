<?php

/**
 * The admin Maintenance > System Information screen: PHP/server/application diagnostics.
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

$version = require LUMORA_ROOT . '/version.php';
?>
<h1 class="lp-admin__title">System Information</h1>

<section class="lp-admin__panel">
    <ul class="lp-admin__meta-list">
        <li><span>PHP Version</span><span><?= esc_html(PHP_VERSION) ?></span></li>
        <li><span>Lumora Press Version</span><span><?= esc_html((string) $version['version']) ?></span></li>
        <li><span>Active Theme</span><span><?= esc_html($kernel->theme->activeTheme() ?? '—') ?></span></li>
    </ul>
</section>
