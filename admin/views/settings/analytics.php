<?php

/**
 * The admin Settings > Analytics screen, provided by the bundled Site Analytics plugin.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.26.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\SiteAnalytics\SiteAnalyticsService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the plugin is active, so the service class is loaded.
$service = SiteAnalyticsService::instance();
$errors = [];
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$submitted = null;

if ($form === 'site_analytics_settings' && Csrf::verify('site_analytics_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $result = $service->sanitize($_POST);

    if ($result['errors'] === []) {
        $service->saveSettings($result['settings']);

        header('Location: ' . admin_url('settings/analytics') . '?saved=1');
        exit;
    }

    $errors = $result['errors'];
    $submitted = $result['settings'];
}

$settings = $submitted ?? $service->settings();
$cspHosts = $service->cspHosts();
?>
<h1 class="lp-admin__title">Analytics</h1>

<?php if (isset($_GET['saved']) && $errors === []): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="lp-alert lp-alert--error">
        <p>Nothing was saved. Please check:</p>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= esc_html($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= esc_url(admin_url('settings/analytics')) ?>">
    <?= Csrf::field('site_analytics_settings') ?>
    <input type="hidden" name="form" value="site_analytics_settings">

    <section class="lp-admin__panel">
        <h2>Provider</h2>
        <p class="lp-field__hint">
            Pick a traffic-statistics service and enter its ID; the tracking tag is added to every public page for you.
            This site makes no request to the provider itself &mdash; only your visitors' browsers do.
            For a built-in counter that uses no third-party service, see Visitor Stats.
        </p>

        <p class="lp-field">
            <label for="analytics-provider">Analytics provider</label>
            <select id="analytics-provider" name="provider">
                <?php foreach (SiteAnalyticsService::PROVIDERS as $key => $label): ?>
                    <option value="<?= esc_attr($key) ?>" <?= $settings['provider'] === $key ? 'selected' : '' ?>><?= esc_html($label) ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <div data-lp-analytics-provider="plausible">
            <p class="lp-field">
                <label for="analytics-plausible-domain">Domain</label>
                <input type="text" id="analytics-plausible-domain" name="plausible_domain" value="<?= esc_attr($settings['plausible_domain']) ?>" placeholder="example.com" autocomplete="off">
                <span class="lp-field__hint">The site domain exactly as it is registered in Plausible, without <code>https://</code>.</span>
            </p>
            <p class="lp-field">
                <label for="analytics-plausible-script">Script URL (self-hosted only)</label>
                <input type="url" id="analytics-plausible-script" name="plausible_script_url" value="<?= esc_attr($settings['plausible_script_url']) ?>" placeholder="<?= esc_attr(SiteAnalyticsService::PLAUSIBLE_DEFAULT_SCRIPT) ?>">
                <span class="lp-field__hint">Leave empty for Plausible's hosted service. For your own Plausible server, enter the full <code>https://</code> address of its script.</span>
            </p>
        </div>

        <div data-lp-analytics-provider="fathom">
            <p class="lp-field">
                <label for="analytics-fathom-id">Site ID</label>
                <input type="text" id="analytics-fathom-id" name="fathom_site_id" value="<?= esc_attr($settings['fathom_site_id']) ?>" placeholder="ABCDEFGH" autocomplete="off">
                <span class="lp-field__hint">Shown in your Fathom site's tracking code.</span>
            </p>
        </div>

        <div data-lp-analytics-provider="matomo">
            <p class="lp-field">
                <label for="analytics-matomo-url">Matomo address</label>
                <input type="url" id="analytics-matomo-url" name="matomo_url" value="<?= esc_attr($settings['matomo_url']) ?>" placeholder="https://analytics.example.com/">
                <span class="lp-field__hint">The <code>https://</code> address where Matomo is installed, or your Matomo Cloud address.</span>
            </p>
            <p class="lp-field">
                <label for="analytics-matomo-site">Site ID</label>
                <input type="text" id="analytics-matomo-site" name="matomo_site_id" value="<?= esc_attr($settings['matomo_site_id']) ?>" placeholder="1" inputmode="numeric" autocomplete="off">
            </p>
        </div>

        <div data-lp-analytics-provider="ga4">
            <p class="lp-field">
                <label for="analytics-ga4-id">Measurement ID</label>
                <input type="text" id="analytics-ga4-id" name="ga4_id" value="<?= esc_attr($settings['ga4_id']) ?>" placeholder="G-XXXXXXXXXX" autocomplete="off">
                <span class="lp-field__hint">Found in Google Analytics under Admin &rsaquo; Data streams.</span>
            </p>
        </div>
    </section>

    <section class="lp-admin__panel">
        <h2>Privacy</h2>

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="skip_signed_in" value="1" <?= $settings['skip_signed_in'] ? 'checked' : '' ?>>
                Don't count signed-in users
            </label>
            <span class="lp-field__hint">Keeps your own visits, and your authors' and editors', out of the numbers.</span>
        </p>

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="respect_dnt" value="1" <?= $settings['respect_dnt'] ? 'checked' : '' ?>>
                Respect Do Not Track
            </label>
            <span class="lp-field__hint">Visitors whose browser sends Do Not Track or Global Privacy Control are not counted. The admin, the maintenance page and previews of unpublished posts are never counted.</span>
        </p>

        <p class="lp-field__hint">
            Plausible and Fathom do not use cookies. Google Analytics sets cookies and, depending on where your visitors live, may need a consent notice; Matomo's cookie use depends on how your Matomo is configured. Lumora Press does not provide a consent banner.
        </p>
    </section>

    <section class="lp-admin__panel">
        <h2>Content-Security-Policy</h2>
        <p class="lp-field__hint">
            This site's security policy blocks outside scripts by default. Only the hosts below, needed by the selected provider, are added. They apply once the settings are saved.
        </p>

        <?php if ($cspHosts === []): ?>
            <p class="lp-field__hint">No provider is selected, so nothing extra is allowed.</p>
        <?php else: ?>
            <ul class="lp-admin__meta-list">
                <?php foreach ($cspHosts as $directive => $hosts): ?>
                    <li><span><code><?= esc_html($directive) ?></code></span><span><?= esc_html(implode(' ', $hosts)) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="lp-admin__panel">
        <h2>Search engine verification</h2>
        <p class="lp-field__hint">Paste the verification code (or the whole <code>&lt;meta&gt;</code> tag) from the search engine; it is added to every public page.</p>

        <p class="lp-field">
            <label for="analytics-google-verification">Google Search Console</label>
            <input type="text" id="analytics-google-verification" name="google_verification" value="<?= esc_attr($settings['google_verification']) ?>" autocomplete="off">
        </p>

        <p class="lp-field">
            <label for="analytics-bing-verification">Bing Webmaster Tools</label>
            <input type="text" id="analytics-bing-verification" name="bing_verification" value="<?= esc_attr($settings['bing_verification']) ?>" autocomplete="off">
        </p>
    </section>

    <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
</form>

<script src="<?= esc_url(site_url('content/plugins/site-analytics/assets/admin-analytics.js')) ?>" defer></script>
