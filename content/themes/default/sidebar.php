<?php

/**
 * Default theme sidebar template: renders the primary widget area when it has widgets assigned.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
if (is_active_sidebar('primary')): ?>
<aside class="lp-sidebar" aria-label="Sidebar">
    <?php dynamic_sidebar('primary'); ?>
</aside>
<?php endif; ?>
