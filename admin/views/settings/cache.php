<?php
/** @var \LumoraPress\Core\Kernel $kernel */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-037: this page used to be a placeholder reserving the spot LP-042
 * planned for it ("Lumora Press does not yet have a site-wide page or
 * object caching engine"). $kernel->cache (CacheManager) now exists.
 */
$cache = $kernel->cache;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'cache_settings' && Csrf::verify('cache_settings', $token)) {
        $kernel->config->setOption('cache_enabled', ($_POST['cache_enabled'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('cache_default_lifetime', (string) max(0, (int) ($_POST['cache_default_lifetime'] ?? 3600)));

        $driverOverride = (string) ($_POST['cache_driver'] ?? 'auto');
        $kernel->config->setOption('cache_driver', in_array($driverOverride, ['auto', 'null', 'litespeed'], true) ? $driverOverride : 'auto');

        header('Location: ' . admin_url('settings/cache') . '?saved=1');
        exit;
    }

    if ($form === 'purge_all' && Csrf::verify('purge_all', $token)) {
        $cache->purgeAll();

        header('Location: ' . admin_url('settings/cache') . '?purged=1');
        exit;
    }
}

$driverName = $cache->driverName();
$serverSoftware = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');
$cacheEnabled = $kernel->config->option('cache_enabled', '1') !== '0';
$defaultLifetime = (string) $kernel->config->option('cache_default_lifetime', '3600');
$driverOverride = (string) $kernel->config->option('cache_driver', 'auto');
$recentPurges = $cache->recentPurges();
?>
<h1 class="lp-admin__title">Cache</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['purged'])): ?>
    <div class="lp-alert lp-alert--success">Cache purged.</div>
<?php endif; ?>

<section class="lp-admin__panel lp-update__status-bar">
    <div class="lp-update__status-group">
        <p class="lp-update__status-label">Detected Cache Driver</p>
        <p class="lp-update__status-value">
            <?= $driverName === 'litespeed' ? 'LiteSpeed Cache' : 'None (Null driver)' ?>
            <span class="lp-status-badge <?= $driverName === 'litespeed' ? 'lp-status-badge--success' : 'lp-status-badge--warning' ?>">
                <?= $driverName === 'litespeed' ? 'Active' : 'No cache integration' ?>
            </span>
        </p>
        <p class="lp-update__status-label">Detected Web Server</p>
        <p class="lp-update__status-value"><?= esc_html($serverSoftware) ?></p>
    </div>
    <div class="lp-update__status-group">
        <p class="lp-update__status-label">Cache Status</p>
        <p class="lp-update__status-value">
            <span class="lp-status-badge <?= $cacheEnabled && $driverName === 'litespeed' ? 'lp-status-badge--success' : 'lp-status-badge--warning' ?>">
                <?= $cacheEnabled ? ($driverName === 'litespeed' ? 'Enabled, LiteSpeed active' : 'Enabled, nothing to purge') : 'Disabled' ?>
            </span>
        </p>
        <p class="lp-update__status-label">Default Cache Lifetime</p>
        <p class="lp-update__status-value"><?= (int) $defaultLifetime ?> seconds</p>
    </div>
</section>

<?php if ($driverName !== 'litespeed'): ?>
    <div class="lp-alert lp-alert--warning">
        No supported reverse-proxy/edge cache was detected on this server. Lumora Press still sends standard
        HTTP caching headers (<code>Cache-Control</code>, <code>ETag</code>, conditional <code>304</code>
        responses) on every cacheable public page, which browsers and any CDN in front of this site will
        still honor &mdash; there just isn't a LiteSpeed-specific purge integration active. Only LiteSpeed/
        OpenLiteSpeed is supported today; Cloudflare, Varnish, and other reverse proxies are planned for a
        future release (see <code>TODO.md</code>'s LP-037 entry).
    </div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Settings</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/cache')) ?>">
        <?= Csrf::field('cache_settings') ?>
        <input type="hidden" name="form" value="cache_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="cache_enabled" value="1" <?= $cacheEnabled ? 'checked' : '' ?>>
            Enable HTTP caching
        </label>
        <span class="lp-field__hint">When off, every page is sent with <code>Cache-Control: no-store</code> and purge headers are never emitted, regardless of the driver below.</span>

        <p class="lp-field">
            <label for="cache-driver">Cache driver</label>
            <select id="cache-driver" name="cache_driver">
                <option value="auto" <?= $driverOverride === 'auto' ? 'selected' : '' ?>>Automatic detection (recommended)</option>
                <option value="litespeed" <?= $driverOverride === 'litespeed' ? 'selected' : '' ?>>LiteSpeed (force on)</option>
                <option value="null" <?= $driverOverride === 'null' ? 'selected' : '' ?>>None (force off, HTTP headers only)</option>
            </select>
            <span class="lp-field__hint">Automatic detection checks the web server and the <code>X-LSCACHE</code> request header LiteSpeed's cache module adds. Override this only if detection guesses wrong on your host.</span>
        </p>

        <p class="lp-field">
            <label for="cache-default-lifetime">Default cache lifetime (seconds)</label>
            <input type="number" id="cache-default-lifetime" name="cache_default_lifetime" min="0" value="<?= esc_attr($defaultLifetime) ?>">
            <span class="lp-field__hint">How long a cacheable public page may be reused before it's considered stale. Content changes purge the cache immediately regardless of this value — see "Automatic Invalidation" below.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Manual Purge</h2>
    <p>Purges immediately, whether or not a driver is active — harmless with the Null driver (nothing to purge), and clears LiteSpeed's cache instantly when it is.</p>
    <form method="post" action="<?= esc_url(admin_url('settings/cache')) ?>" data-lp-confirm="Purge the entire cache?">
        <?= Csrf::field('purge_all') ?>
        <input type="hidden" name="form" value="purge_all">
        <button type="submit" class="lp-button lp-button--danger">Purge Entire Cache</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Automatic Invalidation</h2>
    <p>The entire cache is purged automatically whenever any of the following change: posts, pages, categories, tags, comments (posted or moderated), or any site setting/widget/menu/theme option. This is a deliberate simplicity trade-off for now &mdash; always correct, though less targeted than purging only the specific pages a change actually affects.</p>
</section>

<section class="lp-admin__panel">
    <h2>What Gets Cached</h2>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>Cacheable for guests: the homepage, category/tag/date archives, search results, and standalone pages.</li>
        <li>Never cached: every admin page, anything requested by a logged-in visitor, and a single post's page (it embeds a one-time comment form token that must never be shared between visitors).</li>
        <li>A logged-in visitor never receives a cached response, and a cached guest response is never served to a logged-in visitor &mdash; enforced per-request, not by a header a shared cache might ignore.</li>
    </ul>
</section>

<section class="lp-admin__panel">
    <h2>Recent Purges</h2>
    <?php if ($recentPurges === []): ?>
        <p class="lp-admin__widget-placeholder">No purges recorded yet.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Type</th>
                    <th scope="col">Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentPurges as $purge): ?>
                    <tr>
                        <td><?= esc_html((string) $purge['at']) ?></td>
                        <td><?= esc_html((string) $purge['type']) ?></td>
                        <td><code><?= esc_html((string) $purge['value']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
