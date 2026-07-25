<?php
/** @var \LumoraPress\Core\Kernel $kernel */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<h1 class="lp-admin__title">Security</h1>

<section class="lp-admin__panel">
    <p>
        Most of LP-042's planned Security settings (password policy, session
        configuration, user-enumeration protection, security headers, and a
        rate-limiting UI) have no configurable backing yet &mdash; this page
        reserves the spot in the admin navigation that LP-042 planned for
        them. What's already built into Lumora Press today is always on and
        not yet configurable:
    </p>
    <ul>
        <li>CSRF protection on every administrative form</li>
        <li>Login throttling: 5 failed attempts from one IP within 15 minutes locks that IP out for 15 minutes</li>
        <li>Secure, HttpOnly session cookies</li>
    </ul>
    <p class="lp-field__hint">
        Lumora Press has no XML-RPC endpoint, so no XML-RPC setting is listed here (see MEMORY.md).
    </p>
</section>
