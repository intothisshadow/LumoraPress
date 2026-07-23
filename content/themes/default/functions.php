<?php

declare(strict_types=1);

/**
 * Default theme setup: widget areas, nav menu locations, and a single
 * text widget proving the widget architecture (per Phase 1 scope, this
 * is intentionally the only widget type shipped).
 */

register_sidebar('primary', 'Primary Sidebar', 'Appears alongside posts and pages.');

register_nav_menu('primary', 'Primary Menu');
register_nav_menu('footer', 'Footer Menu');
register_nav_menu('social', 'Social Links Menu');
register_nav_menu('secondary', 'Secondary Menu');

register_widget('text', 'Text', static function (array $settings): void {
    $title = (string) ($settings['title'] ?? '');
    $text = (string) ($settings['text'] ?? '');

    echo '<section class="lp-widget lp-widget--text">';

    if ($title !== '') {
        echo '<h3 class="lp-widget__title">' . esc_html($title) . '</h3>';
    }

    echo '<div class="lp-widget__content">' . nl2br(esc_html($text)) . '</div>';
    echo '</section>';
});
