<?php

/**
 * A generic "this section is not built yet" placeholder for an admin menu entry with no view of its own.
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
/** @var array{label: string, capability: string|null} $activeEntry */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<h1 class="lp-admin__title"><?= esc_html($activeEntry['label']) ?></h1>
<section class="lp-admin__panel">
    <p>This section is part of the Lumora Press foundation and will be built out in a future phase.</p>
</section>
