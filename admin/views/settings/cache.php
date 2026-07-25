<?php
/** @var \LumoraPress\Core\Kernel $kernel */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<h1 class="lp-admin__title">Cache</h1>

<section class="lp-admin__panel">
    <p>
        Lumora Press does not yet have a site-wide page or object caching engine
        &mdash; there is nothing to configure here yet. This page reserves the
        spot in the admin navigation that LP-042 planned for it.
    </p>
    <p>
        The one caching-related setting that already exists today &mdash; how
        long generated RSS/Atom feeds are cached &mdash; lives under
        <a href="<?= esc_url(admin_url('settings/general')) ?>">Settings &rsaquo; General &rsaquo; Feeds</a>.
    </p>
</section>
