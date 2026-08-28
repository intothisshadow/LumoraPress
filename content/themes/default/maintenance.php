<?php

/**
 * Default theme template shown to visitors while Maintenance Mode is active (LP-033).
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
/** @var string $page_title */
/** @var string $message */
/** @var \DateTimeImmutable|null $return_at */
get_header();
?>
<div id="lp-content" class="lp-content">
    <div class="lp-maintenance">
        <h1 class="lp-page-title"><?= esc_html($page_title) ?></h1>
        <p class="lp-maintenance__message"><?= esc_html($message) ?></p>

        <?php if ($return_at !== null): ?>
            <p class="lp-maintenance__return-at">
                We expect to be back around <?= esc_html(the_date($return_at)) ?> at <?= esc_html(the_time($return_at)) ?>.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php get_footer(); ?>
