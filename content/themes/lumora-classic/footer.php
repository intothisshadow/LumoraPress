<?php

/**
 * Default theme footer template: closing HTML, footer navigation, and conditional third-party script tags.
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
?>
</div>
<footer class="lp-site-footer">
    <?php if (is_active_sidebar('footer')): ?>
    <div class="lp-site-footer__widgets">
        <?php dynamic_sidebar('footer'); ?>
    </div>
    <?php endif; ?>
    <div class="lp-site-footer__inner">
        <nav class="lp-site-footer__nav" aria-label="Footer">
            <?php nav_menu('footer'); ?>
        </nav>
        <p class="lp-site-footer__powered-by"><?= powered_by_html() ?></p>
        <a class="lp-site-footer__feed-link" href="<?= esc_url(home_url('feed')) ?>">Subscribe via RSS</a>
        <?php $lpPrivacyPolicyUrl = privacy_policy_url(); ?>
        <?php if ($lpPrivacyPolicyUrl !== null): ?>
            <a class="lp-site-footer__privacy-link" href="<?= esc_url($lpPrivacyPolicyUrl) ?>">Privacy Policy</a>
        <?php endif; ?>
        <?php if (has_footer_html()): ?>
            <?php footer_html(); ?>
        <?php endif; ?>
    </div>
</footer>
<script src="<?= esc_url(core_asset_url('js/dynamic-style.js')) ?>"></script>
<?php
/*
 * Lightbox CSS/JS and Twitter/Bluesky embed scripts are core features,
 * not theme markup — do_action() hands off to FooterAssets::render()
 * (app/Core/Theme/FooterAssets.php), which only emits each one when it
 * was actually used earlier in this request.
 */
do_action('footer_assets');
?>
</body>
</html>
