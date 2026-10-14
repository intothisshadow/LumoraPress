<?php

/**
 * The admin Settings > Performance screen: script loading, font hosting, reverse proxy, CDN, and speed checks.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */

use LumoraPress\Core\Http\CdnRewriter;
use LumoraPress\Core\Http\TrustedProxies;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\LocalFontService;
use LumoraPress\Services\PerformanceCheckService;
use LumoraPress\Services\ThumbnailService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$fonts = $kernel->localFonts;
$redirectTo = static function (string $query) {
    header('Location: ' . admin_url('settings/performance') . '?' . $query);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'performance_settings' && Csrf::verify('performance_settings', $token)) {
        $previousDisplay = (string) $kernel->config->option('perf_font_display', 'swap');
        $display = LocalFontService::normalizeDisplay(is_string($_POST['perf_font_display'] ?? null) ? $_POST['perf_font_display'] : 'swap');

        $kernel->config->setOption('perf_defer_js', ($_POST['perf_defer_js'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('perf_scripts_in_footer', ($_POST['perf_scripts_in_footer'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('perf_font_local', ($_POST['perf_font_local'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('perf_font_preload', ($_POST['perf_font_preload'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('perf_font_display', $display);

        // Only fonts.googleapis.com stylesheet addresses are kept, one per line.
        $fontUrls = [];

        foreach (preg_split('/[\r\n]+/', is_string($_POST['perf_font_urls'] ?? null) ? mb_substr($_POST['perf_font_urls'], 0, 5000) : '') ?: [] as $line) {
            $line = trim($line);

            if (LocalFontService::isGoogleFontsUrl($line) && !in_array($line, $fontUrls, true) && count($fontUrls) < 20) {
                $fontUrls[] = $line;
            }
        }

        $kernel->config->setOption('perf_font_urls', implode("\n", $fontUrls));

        if ($display !== $previousDisplay) {
            $fonts->applyDisplay($display);
        }

        $kernel->config->setOption('trusted_proxies', implode("\n", TrustedProxies::parse(is_string($_POST['trusted_proxies'] ?? null) ? mb_substr($_POST['trusted_proxies'], 0, 5000) : '')));

        $cdnSubmitted = trim(is_string($_POST['cdn_url'] ?? null) ? $_POST['cdn_url'] : '');
        $cdnUrl = $cdnSubmitted === '' ? '' : CdnRewriter::normalize($cdnSubmitted);

        if ($cdnUrl !== null) {
            $kernel->config->setOption('cdn_url', $cdnUrl);
        }

        $query = ['saved' => '1'];

        if ($cdnUrl === null) {
            $query['cdn_invalid'] = '1';
        }

        if (($_POST['download_fonts'] ?? '') === '1') {
            $done = 0;
            $failed = [];

            foreach ($fontUrls as $fontUrl) {
                $result = $fonts->download($fontUrl, $display);

                if ($result['ok']) {
                    ++$done;
                } else {
                    $failed[] = $result['message'];
                }
            }

            $query['fonts_done'] = (string) $done;
            $query['fonts_failed'] = (string) count($failed);

            if ($failed !== []) {
                $query['fonts_error'] = mb_substr($failed[0], 0, 200);
            }
        }

        $redirectTo(http_build_query($query));
    }

    if ($form === 'font_remove' && Csrf::verify('font_remove', $token)) {
        $fonts->remove(is_string($_POST['font_hash'] ?? null) ? $_POST['font_hash'] : '');
        $redirectTo('font_removed=1');
    }
}

$deferJs = $kernel->config->option('perf_defer_js', '1') !== '0';
$footerJs = $kernel->config->option('perf_scripts_in_footer', '1') !== '0';
$localFonts = $kernel->config->option('perf_font_local', '0') === '1';
$preloadFonts = $kernel->config->option('perf_font_preload', '1') !== '0';
$fontDisplay = LocalFontService::normalizeDisplay((string) $kernel->config->option('perf_font_display', 'swap'));
$savedFontUrls = trim((string) $kernel->config->option('perf_font_urls', ''));
$fontUrlsValue = $savedFontUrls !== '' ? $savedFontUrls : implode("\n", font_stylesheet_urls());
$hostedFonts = $fonts->entries();
$trustedProxyEntries = TrustedProxies::parse((string) $kernel->config->option('trusted_proxies', ''));
$cdnValue = (string) $kernel->config->option('cdn_url', '');

$proxyAddress = (string) ($_SERVER['LP_PROXY_ADDR'] ?? '');
$forwardedFor = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
$cloudflareAddress = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');

$checker = new PerformanceCheckService(
    config: $kernel->config,
    server: $_SERVER,
    ini: [
        'opcache.enable' => ini_get('opcache.enable'),
        'zlib.output_compression' => ini_get('zlib.output_compression'),
        'memory_limit' => ini_get('memory_limit'),
    ],
    extensions: array_map('strtolower', get_loaded_extensions()),
    apacheModules: function_exists('apache_get_modules') ? apache_get_modules() : [],
    webpSupported: ThumbnailService::supportsWebp(),
    cacheDriver: $kernel->cache->driverName(),
    siteUrl: home_url(),
    trustedProxies: $trustedProxyEntries,
    debugMode: $kernel->config->get('debug', false) === true,
);
$checks = $checker->checks();
$recommendations = $checker->recommendations();

$statusClass = static fn (string $status): string => match ($status) {
    PerformanceCheckService::STATUS_OK => 'lp-status-badge--success',
    PerformanceCheckService::STATUS_WARNING => 'lp-status-badge--warning',
    default => '',
};
$statusLabel = static fn (string $status): string => match ($status) {
    PerformanceCheckService::STATUS_OK => 'Good',
    PerformanceCheckService::STATUS_WARNING => 'Needs attention',
    default => 'Suggestion',
};
?>
<h1 class="lp-admin__title">Performance</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['cdn_invalid'])): ?>
    <div class="lp-alert lp-alert--error">The CDN address wasn't saved. Enter only the host, such as <code>https://cdn.example.com</code>, with no path or query.</div>
<?php endif; ?>

<?php if (isset($_GET['fonts_done'])): ?>
    <?php if ((int) ($_GET['fonts_failed'] ?? 0) === 0): ?>
        <div class="lp-alert lp-alert--success">Hosted <?= (int) $_GET['fonts_done'] ?> font stylesheet(s) locally.</div>
    <?php else: ?>
        <div class="lp-alert lp-alert--error"><?= (int) $_GET['fonts_failed'] ?> font stylesheet(s) could not be downloaded. <?= esc_html((string) ($_GET['fonts_error'] ?? '')) ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php if (isset($_GET['font_removed'])): ?>
    <div class="lp-alert lp-alert--success">Local fonts removed.</div>
<?php endif; ?>

<form method="post" action="<?= esc_url(admin_url('settings/performance')) ?>">
    <?= Csrf::field('performance_settings') ?>
    <input type="hidden" name="form" value="performance_settings">

    <section class="lp-admin__panel">
        <h2>JavaScript</h2>
        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="perf_defer_js" value="1" <?= $deferJs ? 'checked' : '' ?>>
                Defer scripts
            </label>
            <span class="lp-field__hint">Adds <code>defer</code> to the scripts Lumora Press and plugins load, so the browser keeps drawing the page while they download. A script can still opt out when it is enqueued.</span>
        </div>
        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="perf_scripts_in_footer" value="1" <?= $footerJs ? 'checked' : '' ?>>
                Load enqueued scripts in the footer
            </label>
            <span class="lp-field__hint">Scripts a plugin or theme queues are printed at the end of the page unless they ask for the head. The dark-mode switch always loads in the head so the page never flashes the wrong colours.</span>
        </div>
    </section>

    <section class="lp-admin__panel">
        <h2>Fonts</h2>
        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="perf_font_local" value="1" <?= $localFonts ? 'checked' : '' ?>>
                Host Google Fonts on this site
            </label>
            <span class="lp-field__hint">Serves fonts from your own site instead of Google, so visitors' browsers never contact Google and the page needs two fewer connections. Fonts are downloaded once, from the button below; until then the theme keeps loading them from Google.</span>
        </div>

        <p class="lp-field">
            <label for="perf-font-urls">Font stylesheets</label>
            <textarea id="perf-font-urls" name="perf_font_urls" rows="4" spellcheck="false"><?= esc_html($fontUrlsValue) ?></textarea>
            <span class="lp-field__hint">One <code>https://fonts.googleapis.com/css2?...</code> address per line. The fonts your active theme asks for are filled in automatically; add any others your theme loads.</span>
        </p>

        <p class="lp-field">
            <label for="perf-font-display">Font display</label>
            <select id="perf-font-display" name="perf_font_display">
                <option value="swap" <?= $fontDisplay === 'swap' ? 'selected' : '' ?>>Swap &mdash; show text at once, switch when the font arrives (recommended)</option>
                <option value="fallback" <?= $fontDisplay === 'fallback' ? 'selected' : '' ?>>Fallback &mdash; short wait, then keep the fallback font</option>
                <option value="optional" <?= $fontDisplay === 'optional' ? 'selected' : '' ?>>Optional &mdash; use the font only if it is already available</option>
                <option value="block" <?= $fontDisplay === 'block' ? 'selected' : '' ?>>Block &mdash; hide text until the font arrives</option>
                <option value="auto" <?= $fontDisplay === 'auto' ? 'selected' : '' ?>>Auto &mdash; let the browser decide</option>
            </select>
            <span class="lp-field__hint">Controls what visitors see while a font is still loading. Applies to Google Fonts addresses, whether hosted here or not.</span>
        </p>

        <div>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="perf_font_preload" value="1" <?= $preloadFonts ? 'checked' : '' ?>>
                Preload the main hosted fonts
            </label>
            <span class="lp-field__hint">Starts downloading the regular Latin font files as soon as the page begins loading. Only applies to fonts hosted on this site.</span>
        </div>
    </section>

    <section class="lp-admin__panel">
        <h2>Reverse Proxy</h2>
        <p class="lp-field">
            <label for="trusted-proxies">Trusted proxy addresses</label>
            <textarea id="trusted-proxies" name="trusted_proxies" rows="4" spellcheck="false" placeholder="203.0.113.10&#10;10.0.0.0/8"><?= esc_html(implode("\n", $trustedProxyEntries)) ?></textarea>
            <span class="lp-field__hint">Fill this in only if a proxy, load balancer, or CDN such as Cloudflare sits in front of this site. List its addresses, one IP or range (like <code>10.0.0.0/8</code>) per line. Requests from those addresses are trusted to say who the real visitor is and whether they used HTTPS, so login limits, comment limits, and the maintenance allowlist see the visitor instead of the proxy. Leave it empty if visitors reach this site directly. A line starting with <code>#</code> is ignored.</span>
        </p>
        <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
            <li>Connection reaches the server from: <code><?= esc_html($proxyAddress !== '' ? $proxyAddress : (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?></code></li>
            <li>Visitor address in use: <code><?= esc_html((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?></code><?= $proxyAddress !== '' ? ' (taken from the proxy\'s forwarded headers)' : '' ?></li>
            <?php if ($forwardedFor !== '' || $cloudflareAddress !== ''): ?>
                <li>Forwarded headers on this request: <code><?= esc_html($forwardedFor !== '' ? $forwardedFor : $cloudflareAddress) ?></code></li>
            <?php endif; ?>
        </ul>
    </section>

    <section class="lp-admin__panel">
        <h2>Content Delivery Network</h2>
        <p class="lp-field">
            <label for="cdn-url">CDN address</label>
            <input type="url" id="cdn-url" name="cdn_url" value="<?= esc_attr($cdnValue) ?>" placeholder="https://cdn.example.com" maxlength="200">
            <span class="lp-field__hint">Loads this theme's and Lumora Press's own scripts, styles, and images from the CDN instead of this server. Set up a pull zone that uses this site as its origin and mirrors its paths. Because scripts and fonts are fetched from another host, the CDN must send an <code>Access-Control-Allow-Origin</code> header for them. Uploaded media and the admin area still load from this site. Leave it empty to turn this off.</span>
        </p>
    </section>

    <p>
        <button type="submit" class="lp-button lp-button--primary">Save</button>
        <button type="submit" class="lp-button lp-button--secondary" name="download_fonts" value="1">Save and Download Fonts</button>
    </p>
</form>

<?php if ($hostedFonts !== []): ?>
    <section class="lp-admin__panel">
        <h2>Hosted Fonts</h2>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Stylesheet</th>
                    <th scope="col">Files</th>
                    <th scope="col">Downloaded</th>
                    <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hostedFonts as $hash => $entry): ?>
                    <tr>
                        <td><code><?= esc_html($entry['url']) ?></code></td>
                        <td><?= (int) $entry['files'] ?></td>
                        <td><?= esc_html(date('Y-m-d H:i', $entry['updated'])) ?></td>
                        <td>
                            <form method="post" action="<?= esc_url(admin_url('settings/performance')) ?>" data-lp-confirm="Remove these locally hosted fonts?">
                                <?= Csrf::field('font_remove') ?>
                                <input type="hidden" name="form" value="font_remove">
                                <input type="hidden" name="font_hash" value="<?= esc_attr((string) $hash) ?>">
                                <button type="submit" class="lp-button lp-button--danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Configuration Checks</h2>
    <table class="lp-table">
        <thead>
            <tr>
                <th scope="col">Check</th>
                <th scope="col">Status</th>
                <th scope="col">Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <th scope="row"><?= esc_html($check['label']) ?></th>
                    <td><span class="lp-status-badge <?= esc_attr($statusClass($check['status'])) ?>"><?= esc_html($statusLabel($check['status'])) ?></span></td>
                    <td><?= esc_html($check['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="lp-admin__panel">
    <h2>Recommendations</h2>
    <?php if ($recommendations === []): ?>
        <p class="lp-admin__widget-placeholder">Nothing to suggest &mdash; your setup looks good.</p>
    <?php else: ?>
        <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
            <?php foreach ($recommendations as $recommendation): ?>
                <li><strong><?= esc_html($recommendation['label']) ?>:</strong> <?= esc_html($recommendation['recommendation']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
