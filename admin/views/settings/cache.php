<?php

/**
 * The admin Settings > Cache screen (LP-037): cache driver configuration.
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

use LumoraPress\Core\Cache\CacheExclusions;
use LumoraPress\Core\Cache\CacheManager;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$cache = $kernel->cache;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'cache_settings' && Csrf::verify('cache_settings', $token)) {
        $kernel->config->setOption('cache_enabled', ($_POST['cache_enabled'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('cache_default_lifetime', (string) max(0, (int) ($_POST['cache_default_lifetime'] ?? 3600)));

        $browserLifetime = trim(is_string($_POST['cache_browser_lifetime'] ?? null) ? $_POST['cache_browser_lifetime'] : '');
        $kernel->config->setOption('cache_browser_lifetime', $browserLifetime === '' ? '' : (string) max(0, (int) $browserLifetime));
        $kernel->config->setOption('cache_debug_tags', ($_POST['cache_debug_tags'] ?? '') === '1' ? '1' : '0');

        $driverOverride = (string) ($_POST['cache_driver'] ?? 'auto');
        $kernel->config->setOption('cache_driver', in_array($driverOverride, array_merge(['auto'], $cache->registeredDriverSlugs()), true) ? $driverOverride : 'auto');

        foreach (array_keys(CacheManager::CONTENT_TYPES) as $contentType) {
            $kernel->config->setOption('cache_type_' . $contentType, ($_POST['cache_type_' . $contentType] ?? '') === '1' ? '1' : '0');
        }

        // Normalised through the parser so comments, blank lines and stray
        // spaces never reach the stored value.
        $exclusions = is_string($_POST['cache_exclusions'] ?? null) ? implode("\n", CacheExclusions::parse(mb_substr($_POST['cache_exclusions'], 0, 5000))) : '';
        $kernel->config->setOption('cache_exclusions', $exclusions);

        header('Location: ' . admin_url('settings/cache') . '?saved=1');
        exit;
    }

    if ($form === 'purge_all' && Csrf::verify('purge_all', $token)) {
        $cache->purgeAll();

        header('Location: ' . admin_url('settings/cache') . '?purged=1');
        exit;
    }

    if ($form === 'purge_home' && Csrf::verify('purge_home', $token)) {
        $cache->purgeUrl(BasePath::get() . '/');

        header('Location: ' . admin_url('settings/cache') . '?purged=1');
        exit;
    }

    if ($form === 'purge_url' && Csrf::verify('purge_url', $token)) {
        // Only a path on this site is purged; a pasted full address is
        // reduced to its path and a foreign host is rejected.
        $submitted = trim(is_string($_POST['purge_url'] ?? null) ? $_POST['purge_url'] : '');
        $parts = parse_url($submitted);
        $siteHost = (string) parse_url(site_url('/'), PHP_URL_HOST);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $hostMatches = !is_array($parts) || !isset($parts['host']) || strcasecmp((string) $parts['host'], $siteHost) === 0;

        if ($submitted !== '' && $hostMatches && $path !== '' && $path[0] === '/' && strlen($path) <= 500 && preg_match('/[\x00-\x1f\x7f\s,]/', $path) !== 1) {
            // The edge cache keys on the full URL path, so a subdirectory
            // install's base path is always part of what gets purged.
            $cache->purgeUrl(BasePath::get() . BasePath::stripFrom($path));
            header('Location: ' . admin_url('settings/cache') . '?purged=1');
        } else {
            header('Location: ' . admin_url('settings/cache') . '?purge_invalid=1');
        }

        exit;
    }
}

$driverName = $cache->driverName();
$serverSoftware = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');
$cacheEnabled = $kernel->config->option('cache_enabled', '1') !== '0';
$defaultLifetime = (string) $kernel->config->option('cache_default_lifetime', '3600');
$driverOverride = (string) $kernel->config->option('cache_driver', 'auto');
$browserLifetimeValue = (string) $kernel->config->option('cache_browser_lifetime', '');
$debugTags = $kernel->config->option('cache_debug_tags', '0') === '1';
$recentPurges = $cache->recentPurges();
$configurationWarnings = $cache->configurationWarnings($serverSoftware);
$exclusionsValue = (string) $kernel->config->option('cache_exclusions', '');
// Plugins can add their own rows (e.g. a CDN's connection status) here.
$extraDiagnostics = array_filter((array) apply_filters('cache_diagnostics', [], $cache), static fn ($value, $label): bool => is_string($label) && is_scalar($value), ARRAY_FILTER_USE_BOTH);
?>
<h1 class="lp-admin__title">Cache</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['purged'])): ?>
    <div class="lp-alert lp-alert--success">Cache purged.</div>
<?php endif; ?>

<?php if (isset($_GET['purge_invalid'])): ?>
    <div class="lp-alert lp-alert--error">That isn't a page on this site. Enter a path such as <code>/about</code>, or a full address on this site.</div>
<?php endif; ?>

<?php if ($configurationWarnings !== []): ?>
    <div class="lp-alert lp-alert--warning">
        <ul>
            <?php foreach ($configurationWarnings as $warning): ?>
                <li><?= esc_html($warning) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="lp-admin__panel lp-update__status-bar">
    <div class="lp-update__status-group">
        <p class="lp-update__status-label">Detected Cache Driver</p>
        <p class="lp-update__status-value">
            <?= esc_html($driverName === 'litespeed' ? 'LiteSpeed Cache' : ($driverName === 'null' ? 'None (Null driver)' : $driverName)) ?>
            <span class="lp-status-badge <?= $driverName !== 'null' ? 'lp-status-badge--success' : 'lp-status-badge--warning' ?>">
                <?= $driverName !== 'null' ? 'Active' : 'No cache integration' ?>
            </span>
        </p>
        <p class="lp-update__status-label">Detected Web Server</p>
        <p class="lp-update__status-value"><?= esc_html($serverSoftware) ?></p>
    </div>
    <div class="lp-update__status-group">
        <p class="lp-update__status-label">Cache Status</p>
        <p class="lp-update__status-value">
            <span class="lp-status-badge <?= $cacheEnabled && $driverName !== 'null' ? 'lp-status-badge--success' : 'lp-status-badge--warning' ?>">
                <?= $cacheEnabled ? ($driverName !== 'null' ? 'Enabled, ' . esc_html($driverName) . ' active' : 'Enabled, nothing to purge') : 'Disabled' ?>
            </span>
        </p>
        <p class="lp-update__status-label">Default Cache Lifetime</p>
        <p class="lp-update__status-value"><?= (int) $defaultLifetime ?> seconds</p>
    </div>
</section>

<?php if ($driverName === 'null'): ?>
    <div class="lp-alert lp-alert--warning">
        No supported reverse-proxy/edge cache was detected on this server. Lumora Press still sends standard
        HTTP caching headers (<code>Cache-Control</code>, <code>ETag</code>, conditional <code>304</code>
        responses) on every cacheable public page, which browsers and any CDN in front of this site will
        still honor &mdash; there just isn't a LiteSpeed-specific purge integration active. Only LiteSpeed/
        OpenLiteSpeed is supported out of the box; a plugin can add support for other caches and CDNs.
    </div>
<?php endif; ?>

<form method="post" action="<?= esc_url(admin_url('settings/cache')) ?>">
    <?= Csrf::field('cache_settings') ?>
    <input type="hidden" name="form" value="cache_settings">

<section class="lp-admin__panel">
    <h2>Settings</h2>
    <div>
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
                <?php foreach ($cache->registeredDriverSlugs() as $driverSlug): ?>
                    <?php if (!in_array($driverSlug, ['litespeed', 'null'], true)): ?>
                        <option value="<?= esc_attr($driverSlug) ?>" <?= $driverOverride === $driverSlug ? 'selected' : '' ?>><?= esc_html($driverSlug) ?> (from a plugin)</option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">Automatic detection checks the web server and the <code>X-LSCACHE</code> request header LiteSpeed's cache module adds. Override this only if detection guesses wrong on your host.</span>
        </p>

        <p class="lp-field">
            <label for="cache-default-lifetime">Default cache lifetime (seconds)</label>
            <input type="number" id="cache-default-lifetime" name="cache_default_lifetime" min="0" value="<?= esc_attr($defaultLifetime) ?>">
            <span class="lp-field__hint">How long a cacheable public page may be reused before it's considered stale. Content changes purge the cache immediately regardless of this value — see "Automatic Invalidation" below.</span>
        </p>

        <p class="lp-field">
            <label for="cache-browser-lifetime">Browser cache lifetime (seconds)</label>
            <input type="number" id="cache-browser-lifetime" name="cache_browser_lifetime" min="0" value="<?= esc_attr($browserLifetimeValue) ?>" placeholder="Same as the default lifetime">
            <span class="lp-field__hint">How long a visitor's browser may reuse a page without asking again. Leave it empty to use the default lifetime. A shorter value lets the server-side cache stay long while visitors still see a purge quickly.</span>
        </p>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="cache_debug_tags" value="1" <?= $debugTags ? 'checked' : '' ?>>
            Show cache tags in a response header
        </label>
        <span class="lp-field__hint">Adds an <code>X-Lumora-Cache-Tags</code> header to cacheable pages so the header test below can show which tags a page carries. Leave it off on a live site; it only helps when checking purges.</span>

    </div>
</section>

<section class="lp-admin__panel">
    <h2>What Gets Cached</h2>
    <p>Choose which kinds of public page may be cached for visitors who aren't logged in.</p>
    <?php foreach (CacheManager::CONTENT_TYPES as $typeKey => $typeLabel): ?>
        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="cache_type_<?= esc_attr($typeKey) ?>" value="1" <?= $kernel->config->option('cache_type_' . $typeKey, '1') !== '0' ? 'checked' : '' ?>>
                <?= esc_html($typeLabel) ?>
            </label>
        </div>
    <?php endforeach; ?>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>Not controlled here: feeds send their own caching headers, and the web server delivers uploaded files directly.</li>
        <li>Never cached: every admin page, anything requested by a logged-in visitor, a single post's page, and any page that contains a form, such as a comment form or a contact form. Those carry a one-time token that must never be shared between visitors.</li>
        <li>A logged-in visitor never receives a cached response, and a cached guest response is never served to a logged-in visitor &mdash; enforced per-request, not by a header a shared cache might ignore.</li>
    </ul>
</section>

<section class="lp-admin__panel">
    <h2>Never Cache</h2>
    <p class="lp-field">
        <label for="cache-exclusions">Pages to leave out of the cache</label>
        <textarea id="cache-exclusions" name="cache_exclusions" rows="5" spellcheck="false"><?= esc_html($exclusionsValue) ?></textarea>
        <span class="lp-field__hint">One path per line, such as <code>/members</code> or <code>/shop/*</code>. A <code>*</code> matches any characters. Only the path is compared, not the part after a <code>?</code>. Lines starting with <code>#</code> are ignored.</span>
    </p>
</section>

<p><button type="submit" class="lp-button lp-button--primary">Save</button></p>
</form>

<section class="lp-admin__panel">
    <h2>Manual Purge</h2>
    <p>Purges immediately, whether or not a driver is active — harmless with the Null driver (nothing to purge), and clears LiteSpeed's cache instantly when it is.</p>
    <form method="post" action="<?= esc_url(admin_url('settings/cache')) ?>" data-lp-confirm="Purge the entire cache?">
        <?= Csrf::field('purge_all') ?>
        <input type="hidden" name="form" value="purge_all">
        <button type="submit" class="lp-button lp-button--danger">Purge Entire Cache</button>
    </form>

    <form method="post" action="<?= esc_url(admin_url('settings/cache')) ?>">
        <?= Csrf::field('purge_home') ?>
        <input type="hidden" name="form" value="purge_home">
        <button type="submit" class="lp-button lp-button--secondary">Purge Homepage</button>
    </form>

    <form method="post" action="<?= esc_url(admin_url('settings/cache')) ?>">
        <?= Csrf::field('purge_url') ?>
        <input type="hidden" name="form" value="purge_url">
        <p class="lp-field">
            <label for="cache-purge-url">Purge one page</label>
            <input type="text" id="cache-purge-url" name="purge_url" placeholder="/about" maxlength="500" required>
            <span class="lp-field__hint">A path such as <code>/about</code>, or the page's full address.</span>
        </p>
        <button type="submit" class="lp-button lp-button--secondary">Purge Page</button>
    </form>
</section>

<section class="lp-admin__panel" data-lp-cache-test>
    <h2>Test Cache Headers</h2>
    <p>Requests your homepage the way an anonymous visitor would, then asks for it again to check that the server answers <code>304 Not Modified</code>.</p>
    <button type="button" class="lp-button lp-button--secondary" data-lp-cache-test-run data-lp-cache-test-url="<?= esc_attr(site_url('/')) ?>">Run Test</button>
    <p class="lp-field__hint" data-lp-cache-test-status role="status" aria-live="polite"></p>
    <table class="lp-table" data-lp-cache-test-results hidden>
        <thead>
            <tr>
                <th scope="col">Check</th>
                <th scope="col">Result</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    <?php if ($driverName === 'litespeed'): ?>
        <p class="lp-field__hint">LiteSpeed removes its own <code>X-LiteSpeed-Tag</code> and <code>X-LiteSpeed-Cache-Control</code> headers before a page reaches the browser, so they can't be shown here. Turn on "Show cache tags in a response header" to see the tags, and note that <code>X-LiteSpeed-Cache</code> reads <code>hit</code> when the page came from the cache.</p>
    <?php endif; ?>
</section>

<section class="lp-admin__panel">
    <h2>Automatic Invalidation</h2>
    <p>The cache is purged automatically when something a visitor can see changes. Saving a draft, a private post or page, or a comment that isn't approved purges nothing. An approved comment purges only the post listings and the page it was left on, unless a widget that shows comments is in use. Everything else purges the whole cache: a published post or page changing, categories, tags, media, users (name or avatar changes), deletions, and any site setting, widget, menu, or theme option. Menus and widgets repeat the same data on every page, so a smaller purge there could leave stale pages behind.</p>
</section>

<?php if ($extraDiagnostics !== []): ?>
    <section class="lp-admin__panel">
        <h2>Diagnostics</h2>
        <table class="lp-table">
            <tbody>
                <?php foreach ($extraDiagnostics as $diagnosticLabel => $diagnosticValue): ?>
                    <tr>
                        <th scope="row"><?= esc_html($diagnosticLabel) ?></th>
                        <td><?= esc_html((string) $diagnosticValue) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

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
