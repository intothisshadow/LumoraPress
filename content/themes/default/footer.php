</div>
<footer class="lp-site-footer">
    <div class="lp-site-footer__inner">
        <nav class="lp-site-footer__nav" aria-label="Footer">
            <?php nav_menu('footer'); ?>
        </nav>
        <p class="lp-site-footer__copyright">&copy; <?= esc_html(date('Y')) ?> <?= esc_html(site_name()) ?>.</p>
        <a class="lp-site-footer__feed-link" href="<?= esc_url(home_url('feed')) ?>">Subscribe via RSS</a>
    </div>
</footer>
</body>
</html>
