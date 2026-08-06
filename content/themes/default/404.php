<?php

/**
 * Default theme template for a page-not-found response.
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
get_header(); ?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title">Page Not Found</h1>
        <p class="lp-empty-state">The page you were looking for could not be found.</p>
        <p><a class="lp-button" href="<?= esc_url(site_url()) ?>">Return Home</a></p>
    </main>
</div>
<?php get_footer(); ?>
