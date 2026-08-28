<?php

/**
 * The admin Visitor Stats > Settings screen, provided by the bundled Visitor & Post View Statistics plugin (LPP-014).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\VisitorStats\ViewStatsService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$viewStats = new ViewStatsService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;

if ($form === 'visitor_stats_settings' && Csrf::verify('visitor_stats_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('track_post_views', isset($_POST['track_post_views']) ? '1' : '0');

    header('Location: ' . admin_url('visitor-stats/settings') . '?saved=1');
    exit;
}

if ($form === 'visitor_stats_geoip_import' && Csrf::verify('visitor_stats_geoip_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $blocksFile = $_FILES['blocks_csv'] ?? null;
    $locationsFile = $_FILES['locations_csv'] ?? null;

    if (!is_array($blocksFile) || !is_array($locationsFile) || $blocksFile['error'] !== UPLOAD_ERR_OK || $locationsFile['error'] !== UPLOAD_ERR_OK) {
        $error = 'Both the Blocks and Locations CSV files are required.';
    } elseif (strtolower(pathinfo((string) $blocksFile['name'], PATHINFO_EXTENSION)) !== 'csv'
        || strtolower(pathinfo((string) $locationsFile['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = 'Both files must be .csv files.';
    } else {
        $geoipDirectory = LUMORA_ROOT . '/storage/geoip';
        $blocksDestination = $geoipDirectory . '/GeoLite2-Country-Blocks-IPv4.csv';
        $locationsDestination = $geoipDirectory . '/GeoLite2-Country-Locations-en.csv';

        if (!move_uploaded_file($blocksFile['tmp_name'], $blocksDestination) || !move_uploaded_file($locationsFile['tmp_name'], $locationsDestination)) {
            $error = 'Could not save the uploaded files.';
        } else {
            try {
                $viewStats->importGeoCsv($blocksDestination, $locationsDestination);

                header('Location: ' . admin_url('visitor-stats/settings') . '?imported=1');
                exit;
            } catch (\Throwable $exception) {
                $error = 'Import failed: ' . $exception->getMessage();
            }
        }
    }
}

$trackPostViews = $kernel->config->option('track_post_views', '0') === '1';
$geoipRangeCount = $viewStats->geoipRangeCount();
?>
<h1 class="lp-admin__title">Visitor Stats</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['imported'])): ?>
    <div class="lp-alert lp-alert--success">GeoLite2 country data imported.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Tracking</h2>
    <p class="lp-field__hint">
        A local, aggregate-only page-view counter for the Dashboard.
        Never sends data anywhere, never uses cookies or sessions, and
        never persists a raw IP address, a raw browser identification
        string, or a full referrer URL — only day-level totals. Only
        counts guest (logged-out) visitors; your own previewing never
        counts as a view. Off by default.
    </p>

    <form method="post" action="<?= esc_url(admin_url('visitor-stats/settings')) ?>">
        <?= Csrf::field('visitor_stats_settings') ?>
        <input type="hidden" name="form" value="visitor_stats_settings">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="track_post_views" value="1" <?= $trackPostViews ? 'checked' : '' ?>>
                Track post views
            </label>
            <span class="lp-field__hint">
                Adds a "Site Visitors" panel to the Dashboard once views
                start being recorded. Also gates the country/referrer/
                browser/device breakdowns below — there is no separate
                opt-out for those alone.
            </span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Country Breakdown</h2>
    <p class="lp-field__hint">
        Optional. Resolves a visitor's country from a local database —
        never an outbound lookup service — so this plugin needs its own
        copy of MaxMind's free GeoLite2 Country data. Create a free
        MaxMind account at
        <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener noreferrer">maxmind.com</a>
        and download the <strong>CSV</strong> format (not the .mmdb
        binary format) of GeoLite2 Country. Upload the two files from
        that download below — <code>GeoLite2-Country-Blocks-IPv4.csv</code>
        and <code>GeoLite2-Country-Locations-en.csv</code>. Leave this
        unconfigured and the country breakdown simply stays empty; every
        other breakdown works without it.
    </p>

    <p class="lp-field__hint">
        <strong>Status:</strong>
        <?= $geoipRangeCount > 0 ? esc_html((string) $geoipRangeCount) . ' ranges loaded' : 'Not installed' ?>
    </p>

    <form method="post" action="<?= esc_url(admin_url('visitor-stats/settings')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('visitor_stats_geoip_import') ?>
        <input type="hidden" name="form" value="visitor_stats_geoip_import">

        <p class="lp-field">
            <label for="blocks-csv">GeoLite2-Country-Blocks-IPv4.csv</label>
            <input type="file" id="blocks-csv" name="blocks_csv" accept=".csv">
        </p>

        <p class="lp-field">
            <label for="locations-csv">GeoLite2-Country-Locations-en.csv</label>
            <input type="file" id="locations-csv" name="locations_csv" accept=".csv">
        </p>

        <button type="submit" class="lp-button lp-button--primary">Import</button>
    </form>
</section>
