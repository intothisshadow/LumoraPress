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

$geoipDirectory = LUMORA_ROOT . '/storage/geoip';
$blocksDestination = $geoipDirectory . '/GeoLite2-Country-Blocks-IPv4.csv';
$locationsDestination = $geoipDirectory . '/GeoLite2-Country-Locations-en.csv';
$geoipBatchState = null;

if ($form === 'visitor_stats_geoip_import' && Csrf::verify('visitor_stats_geoip_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $zipFile = $_FILES['geoip_zip'] ?? null;
    $blocksFile = $_FILES['blocks_csv'] ?? null;
    $locationsFile = $_FILES['locations_csv'] ?? null;

    if (is_array($zipFile) && $zipFile['error'] === UPLOAD_ERR_OK) {
        try {
            $viewStats->extractGeoZip($zipFile['tmp_name'], $blocksDestination, $locationsDestination);

            header('Location: ' . admin_url('visitor-stats/settings') . '?geoip_ready=1');
            exit;
        } catch (\Throwable $exception) {
            $error = 'Import failed: ' . $exception->getMessage();
        }
    } elseif (!is_array($blocksFile) || !is_array($locationsFile) || $blocksFile['error'] !== UPLOAD_ERR_OK || $locationsFile['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload either the GeoLite2 ZIP file, or both the Blocks and Locations CSV files. If the file is large (the Blocks CSV alone is often 20MB+), your host\'s PHP upload limit may be too low for a browser upload — use "Import From A Server Path" below instead.';
    } elseif (strtolower(pathinfo((string) $blocksFile['name'], PATHINFO_EXTENSION)) !== 'csv'
        || strtolower(pathinfo((string) $locationsFile['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = 'Both files must be .csv files.';
    } else {
        if (!move_uploaded_file($blocksFile['tmp_name'], $blocksDestination) || !move_uploaded_file($locationsFile['tmp_name'], $locationsDestination)) {
            $error = 'Could not save the uploaded files.';
        } else {
            header('Location: ' . admin_url('visitor-stats/settings') . '?geoip_ready=1');
            exit;
        }
    }
}

if ($form === 'visitor_stats_geoip_import_from_path' && Csrf::verify('visitor_stats_geoip_import_from_path', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    // Deliberately does not accept a free-form "read this exact file"
    // path for the CSV case — only a directory, within which just these
    // two exact, hardcoded filenames are ever read. A zip path is the
    // one exception: it's still one specific admin-supplied file, opened
    // and immediately validated as a real ZIP archive before anything
    // inside it is trusted, so it carries the same bounded risk as the
    // directory case rather than an arbitrary-file-read one. Mirrors the
    // same "admin-supplied path, not admin-supplied arbitrary read"
    // caution LP-041's Media Import allowlist already applies for the
    // same underlying reason.
    $suppliedPath = rtrim(trim((string) ($_POST['geoip_directory'] ?? '')), '/');

    if ($suppliedPath !== '' && is_file($suppliedPath) && strtolower(pathinfo($suppliedPath, PATHINFO_EXTENSION)) === 'zip') {
        try {
            $viewStats->extractGeoZip($suppliedPath, $blocksDestination, $locationsDestination);

            header('Location: ' . admin_url('visitor-stats/settings') . '?geoip_ready=1');
            exit;
        } catch (\Throwable $exception) {
            $error = 'Import failed: ' . $exception->getMessage();
        }
    } else {
        $blocksSource = $suppliedPath . '/GeoLite2-Country-Blocks-IPv4.csv';
        $locationsSource = $suppliedPath . '/GeoLite2-Country-Locations-en.csv';

        if ($suppliedPath === '' || (!is_dir($suppliedPath) && !is_file($suppliedPath))) {
            $error = 'That path does not exist or is not readable.';
        } elseif (!is_file($blocksSource) || !is_file($locationsSource)) {
            $error = 'That directory must contain both GeoLite2-Country-Blocks-IPv4.csv and GeoLite2-Country-Locations-en.csv, using those exact filenames — or point this at a GeoLite2 .zip file instead.';
        } else {
            if (!copy($blocksSource, $blocksDestination) || !copy($locationsSource, $locationsDestination)) {
                $error = 'Could not copy the CSV files into storage/geoip.';
            } else {
                header('Location: ' . admin_url('visitor-stats/settings') . '?geoip_ready=1');
                exit;
            }
        }
    }
}

if ($form === 'visitor_stats_geoip_import_batch' && Csrf::verify('visitor_stats_geoip_import_batch', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    // Each batch is capped (ViewStatsService::DEFAULT_IMPORT_BATCH_SIZE
    // rows) specifically so it finishes well within a shared host's
    // default execution-time limit — set_time_limit(0) is still applied
    // per batch as a safety margin, not because a batch is expected to
    // run long. The Blocks CSV itself can be ~450k rows, far more than
    // one request should attempt in a single pass; see MEMORY.md/
    // TODO-PLUGINS.md's LPP-014 addendum for why the prior one-shot
    // importGeoCsv() call was timing out at the webserver/proxy layer
    // even though the import itself completed successfully server-side.
    set_time_limit(0);

    $byteOffset = (int) ($_POST['byte_offset'] ?? 0);
    $isFirstBatch = ($_POST['is_first_batch'] ?? '1') === '1';
    $importedSoFar = (int) ($_POST['imported_so_far'] ?? 0);

    if (!is_file($blocksDestination) || !is_file($locationsDestination)) {
        $error = 'The GeoLite2 CSV files are no longer present in storage/geoip — upload or import them again.';
    } else {
        try {
            $result = $viewStats->importGeoCsvBatch($blocksDestination, $locationsDestination, $byteOffset, $isFirstBatch);
            $importedSoFar += $result['importedInBatch'];

            if ($result['done']) {
                header('Location: ' . admin_url('visitor-stats/settings') . '?imported=1');
                exit;
            }

            $geoipBatchState = [
                'byte_offset' => $result['nextByteOffset'],
                'is_first_batch' => false,
                'imported_so_far' => $importedSoFar,
            ];
        } catch (\Throwable $exception) {
            $error = 'Import failed: ' . $exception->getMessage();
        }
    }
}

if (isset($_GET['geoip_ready']) && $geoipBatchState === null && $error === null) {
    $geoipBatchState = [
        'byte_offset' => 0,
        'is_first_batch' => true,
        'imported_so_far' => 0,
    ];
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
        and download <strong>GeoLite2 Country</strong> in the
        <strong>CSV</strong> format (not the .mmdb binary format) — you
        can upload that ZIP file directly below, no need to unzip it
        yourself first. Leave this unconfigured and the country
        breakdown simply stays empty; every other breakdown works
        without it.
    </p>

    <p class="lp-field__hint">
        <strong>Status:</strong>
        <?php if ($geoipBatchState !== null): ?>
            Importing — <?= esc_html((string) $geoipBatchState['imported_so_far']) ?> ranges loaded so far…
        <?php else: ?>
            <?= $geoipRangeCount > 0 ? esc_html((string) $geoipRangeCount) . ' ranges loaded' : 'Not installed' ?>
        <?php endif; ?>
    </p>

    <?php if ($geoipBatchState !== null): ?>
        <div class="lp-alert lp-alert--warning">
            The country data is imported in batches to avoid a single
            request timing out on this server. This page will keep
            advancing on its own — leave it open until the status above
            shows a final range count. If JavaScript is disabled, click
            "Continue Import" below to advance one batch at a time.
        </div>
        <form method="post" action="<?= esc_url(admin_url('visitor-stats/settings')) ?>" id="geoip-import-continue">
            <?= Csrf::field('visitor_stats_geoip_import_batch') ?>
            <input type="hidden" name="form" value="visitor_stats_geoip_import_batch">
            <input type="hidden" name="byte_offset" value="<?= esc_html((string) $geoipBatchState['byte_offset']) ?>">
            <input type="hidden" name="is_first_batch" value="<?= $geoipBatchState['is_first_batch'] ? '1' : '0' ?>">
            <input type="hidden" name="imported_so_far" value="<?= esc_html((string) $geoipBatchState['imported_so_far']) ?>">

            <button type="submit" class="lp-button lp-button--primary">Continue Import</button>
        </form>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('visitor-stats/settings')) ?>" enctype="multipart/form-data">
            <?= Csrf::field('visitor_stats_geoip_import') ?>
            <input type="hidden" name="form" value="visitor_stats_geoip_import">

            <p class="lp-field">
                <label for="geoip-zip">MaxMind's GeoLite2 Country CSV ZIP</label>
                <input type="file" id="geoip-zip" name="geoip_zip" accept=".zip">
                <span class="lp-field__hint">The file MaxMind's download page gives you, unmodified — usually named something like <code>GeoLite2-Country-CSV_20260101.zip</code>.</span>
            </p>

            <details>
                <summary>Or upload the two CSV files separately</summary>
                <p class="lp-field">
                    <label for="blocks-csv">GeoLite2-Country-Blocks-IPv4.csv</label>
                    <input type="file" id="blocks-csv" name="blocks_csv" accept=".csv">
                </p>

                <p class="lp-field">
                    <label for="locations-csv">GeoLite2-Country-Locations-en.csv</label>
                    <input type="file" id="locations-csv" name="locations_csv" accept=".csv">
                </p>
            </details>

            <button type="submit" class="lp-button lp-button--primary">Import</button>
        </form>

        <h3 class="lp-admin__widget-subheading">Or Import From A Server Path</h3>
        <p class="lp-field__hint">
            The ZIP is usually 5–10MB and the unzipped Blocks CSV alone is
            often 20MB+ — many hosts cap a browser upload well below that,
            and <code>upload_max_filesize</code> usually can't be raised
            from within the application. If the form above times out or
            fails with a size error, upload the file to this server via
            FTP/SFTP instead, then enter its path here — either the
            <code>.zip</code> file itself, or a directory containing the two
            already-extracted CSVs (using their exact original filenames).
        </p>
        <form method="post" action="<?= esc_url(admin_url('visitor-stats/settings')) ?>">
            <?= Csrf::field('visitor_stats_geoip_import_from_path') ?>
            <input type="hidden" name="form" value="visitor_stats_geoip_import_from_path">

            <p class="lp-field">
                <label for="geoip-directory">ZIP file or directory path</label>
                <input type="text" id="geoip-directory" name="geoip_directory" placeholder="/home/username/GeoLite2-Country-CSV_20260101.zip">
                <span class="lp-field__hint">A path to the GeoLite2 <code>.zip</code> file, or a directory containing both <code>GeoLite2-Country-Blocks-IPv4.csv</code> and <code>GeoLite2-Country-Locations-en.csv</code>.</span>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Import From Path</button>
        </form>
    <?php endif; ?>
</section>
