<?php

/**
 * The admin Maintenance > Import screen — the WordPress Importer plugin's connection form, dry run, run/resume, progress, and rollback flow (LPP-004).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */
/** @var bool $wordPressImporterActive */
/** @var bool $downloadsActive */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\Downloads\DownloadCategoryService;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\WordPressImporter\ImportProgress;
use LumoraPress\Plugins\WordPressImporter\WordPressConfigParser;
use LumoraPress\Plugins\WordPressImporter\WordPressImportService;
use LumoraPress\Plugins\WordPressImporter\WordPressSource;
use LumoraPress\Plugins\WordPressImporter\WordPressSourceInterface;
use LumoraPress\Plugins\WordPressImporter\WordPressXmlSource;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Progress polling: a lightweight GET the page's own JS hits every second
// while a Start/Resume Import POST runs on another connection. No CSRF
// check needed — it only reads ImportProgress's on-disk state.
if ($wordPressImporterActive && ($_GET['ajax'] ?? null) === 'progress') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    echo json_encode((new ImportProgress(LUMORA_ROOT))->read());
    exit;
}

$importError = null;
$testResult = null;
$detectResult = null;
$sitePreview = null;
$dryRunCounts = null;
$summary = null;
$redirectMappingReport = [];
$warnings = [];

// Connection fields are never persisted server-side — nothing is written
// to the session or database. Within one request/response, though, every
// entered field is carried forward via hidden inputs on whichever form
// didn't itself collect it, so submitting one form doesn't blank the others.
$formValues = [
    // 'database' (a live/local-copy MySQL connection) or 'wxr' (a local
    // WordPress WXR .xml export file) — see $buildSource below for the
    // dispatch this drives.
    'source_type' => ($_POST['source_type'] ?? null) === 'wxr' ? 'wxr' : 'database',
    'db_host' => is_string($_POST['db_host'] ?? null) ? $_POST['db_host'] : 'localhost',
    'db_port' => is_string($_POST['db_port'] ?? null) ? $_POST['db_port'] : '3306',
    'db_name' => is_string($_POST['db_name'] ?? null) ? $_POST['db_name'] : '',
    'db_user' => is_string($_POST['db_user'] ?? null) ? $_POST['db_user'] : '',
    'db_password' => is_string($_POST['db_password'] ?? null) ? $_POST['db_password'] : '',
    'db_prefix' => is_string($_POST['db_prefix'] ?? null) ? $_POST['db_prefix'] : 'wp_',
    'wxr_path' => is_string($_POST['wxr_path'] ?? null) ? $_POST['wxr_path'] : '',
    'uploads_path' => is_string($_POST['uploads_path'] ?? null) ? $_POST['uploads_path'] : '',
    'gallery_path' => is_string($_POST['gallery_path'] ?? null) ? $_POST['gallery_path'] : '',
    'wp_config_path' => is_string($_POST['wp_config_path'] ?? null) ? $_POST['wp_config_path'] : '',
];

if ($wordPressImporterActive) {
    // WordPressImportService's class is guaranteed to already be loaded.
    // $downloadsActive gates the Downloads plugin the same way — null
    // when inactive, so a download still imports as a plain Media item/
    // Redirect, just without a `downloads` table row on top.
    $downloadCategoriesService = $downloadsActive ? new DownloadCategoryService(
        $kernel->database,
        (string) $kernel->config->get('table_prefix', 'lp_'),
    ) : null;
    $downloadsService = $downloadsActive ? new DownloadService(
        $kernel->database,
        (string) $kernel->config->get('table_prefix', 'lp_'),
        $kernel->media,
        $kernel->redirects,
        $downloadCategoriesService,
        $kernel->thumbnails,
        $kernel->mediaStats,
    ) : null;

    // Dispatches on the "Source type" radio to build whichever
    // WordPressSourceInterface implementation the admin picked —
    // WordPressImportService never has to know which one it's driving.
    $buildSource = static function () use ($formValues): WordPressSourceInterface {
        if ($formValues['source_type'] === 'wxr') {
            return new WordPressXmlSource($formValues['wxr_path']);
        }

        return WordPressSource::connect(
            host: $formValues['db_host'],
            database: $formValues['db_name'],
            username: $formValues['db_user'],
            password: $formValues['db_password'],
            tablePrefix: $formValues['db_prefix'],
            port: (int) $formValues['db_port'] > 0 ? (int) $formValues['db_port'] : 3306,
        );
    };

    $buildImportService = static function () use ($kernel, $formValues, $downloadsService, $downloadCategoriesService, $buildSource): WordPressImportService {
        $source = $buildSource();

        return new WordPressImportService(
            source: $source,
            userImporter: $kernel->userImporter,
            postImporter: $kernel->postImporter,
            pageImporter: $kernel->pageImporter,
            mediaImporter: $kernel->mediaImporter,
            commentImporter: $kernel->commentImporter,
            menuImporter: $kernel->menuImporter,
            widgetImporter: $kernel->widgetImporter,
            users: $kernel->users,
            posts: $kernel->posts,
            pages: $kernel->pages,
            media: $kernel->media,
            mediaStats: $kernel->mediaStats,
            thumbnails: $kernel->thumbnails,
            comments: $kernel->comments,
            categories: $kernel->categories,
            tags: $kernel->tags,
            folders: $kernel->folders,
            redirects: $kernel->redirects,
            menus: $kernel->menus,
            widgets: $kernel->widgets,
            config: $kernel->config,
            registry: $kernel->contentImportRegistry,
            sourceUploadsPath: rtrim($formValues['uploads_path'], '/'),
            downloads: $downloadsService,
            downloadCategories: $downloadCategoriesService,
            sourceGalleryPath: $formValues['gallery_path'] !== '' ? rtrim($formValues['gallery_path'], '/') : null,
        );
    };

    // removeAll()/lastImportSummary()/inProgressBatch() are pure
    // ContentImportRegistry lookups that never touch the source database,
    // so this builds the service with source: null rather than requiring
    // the admin to re-enter credentials those methods never use.
    $buildRegistryOnlyService = static fn (): WordPressImportService => new WordPressImportService(
        source: null,
        userImporter: $kernel->userImporter,
        postImporter: $kernel->postImporter,
        pageImporter: $kernel->pageImporter,
        mediaImporter: $kernel->mediaImporter,
        commentImporter: $kernel->commentImporter,
        menuImporter: $kernel->menuImporter,
        widgetImporter: $kernel->widgetImporter,
        users: $kernel->users,
        posts: $kernel->posts,
        pages: $kernel->pages,
        media: $kernel->media,
        mediaStats: $kernel->mediaStats,
        thumbnails: $kernel->thumbnails,
        comments: $kernel->comments,
        categories: $kernel->categories,
        tags: $kernel->tags,
        folders: $kernel->folders,
        redirects: $kernel->redirects,
        menus: $kernel->menus,
        widgets: $kernel->widgets,
        config: $kernel->config,
        registry: $kernel->contentImportRegistry,
        sourceUploadsPath: '',
        downloads: $downloadsService,
        downloadCategories: $downloadCategoriesService,
    );

    /**
     * @return array{site_settings: bool, users: bool, categories: bool, media: bool, nextgen_galleries: bool, downloads: bool, pages: bool, posts: bool, comments: bool, menus: bool, widgets: bool, stage_delay_ms: int, existing_content: string}
     */
    $optionsFromPost = static function (): array {
        return [
            'site_settings' => isset($_POST['include_site_settings']),
            'users' => isset($_POST['include_users']),
            'categories' => isset($_POST['include_categories']),
            'media' => isset($_POST['include_media']),
            'nextgen_galleries' => isset($_POST['include_nextgen_galleries']),
            'downloads' => isset($_POST['include_downloads']),
            'pages' => isset($_POST['include_pages']),
            'posts' => isset($_POST['include_posts']),
            'comments' => isset($_POST['include_comments']),
            'menus' => isset($_POST['include_menus']),
            'widgets' => isset($_POST['include_widgets']),
            'stage_delay_ms' => max(0, (int) ($_POST['stage_delay_ms'] ?? 0)),
            'existing_content' => in_array($_POST['existing_content'] ?? null, ['skip', 'overwrite'], true) ? $_POST['existing_content'] : '',
        ];
    };

    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'detect_wp_config' && Csrf::verify('detect_wp_config', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        try {
            $detected = (new WordPressConfigParser())->parse($formValues['wp_config_path']);

            // Only overwrite fields wp-config.php actually named — a
            // password-less local dev database, for example, has no
            // DB_PASSWORD-driven value here, so the admin's own manual
            // entry (or the field's existing default) is left as-is
            // rather than being blanked out.
            foreach ($detected as $key => $value) {
                $formValues[$key] = $value;
            }

            $missing = array_diff(['db_host', 'db_name', 'db_user', 'db_prefix'], array_keys($detected));

            $detectResult = ['ok' => true, 'message' => $missing === []
                ? 'Detected database connection details from wp-config.php.'
                : 'Detected some database connection details from wp-config.php — enter the rest (' . implode(', ', $missing) . ') by hand.'];

            if (!isset($detected['uploads_path'])) {
                $detectResult['message'] .= ' Could not locate the uploads folder relative to wp-config.php — enter its path manually.';
            }
        } catch (\Throwable $exception) {
            $detectResult = ['ok' => false, 'message' => 'Could not read wp-config.php: ' . $exception->getMessage()];
        }
    }

    if ($form === 'test_wordpress_connection' && Csrf::verify('test_wordpress_connection', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        try {
            $source = $buildSource();

            if (!$source->testConnection()) {
                // Only WordPressSource::testConnection() can return false —
                // WXR's constructor already throws, so this is DB-only.
                $testResult = ['ok' => false, 'message' => "Connected, but no `{$formValues['db_prefix']}posts` table was found. Check the table prefix."];
            } elseif (!is_dir($formValues['uploads_path'])) {
                $testResult = ['ok' => false, 'message' => 'The uploads folder path does not exist or is not readable by the web server.'];
            } else {
                $wpOptions = $source->siteOptions();
                $siteName = $wpOptions['blogname'] ?? null;
                $testResult = [
                    'ok' => true,
                    'message' => ($formValues['source_type'] === 'wxr' ? 'The WXR file is valid, and' : 'Connected successfully, and') . ' the uploads folder is readable.'
                        . ($siteName !== null && $siteName !== '' ? " Source site: \"{$siteName}\"." : ''),
                ];

                // A preview only — nothing here is written. "Site
                // settings" below is the opt-in checkbox that applies these.
                $sitePreview = [
                    'Site title' => html_entity_decode($wpOptions['blogname'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'Tagline' => html_entity_decode($wpOptions['blogdescription'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'Timezone' => ($wpOptions['timezone_string'] ?? '') !== '' ? $wpOptions['timezone_string'] : (($wpOptions['gmt_offset'] ?? '') !== '' ? 'UTC' . ($wpOptions['gmt_offset'][0] === '-' ? '' : '+') . $wpOptions['gmt_offset'] : ''),
                    'Date format' => $wpOptions['date_format'] ?? '',
                    'Time format' => $wpOptions['time_format'] ?? '',
                    'Permalink structure' => $wpOptions['permalink_structure'] ?? '',
                    'Homepage' => ($wpOptions['show_on_front'] ?? 'posts') === 'page'
                        ? 'Static page (WordPress page ID ' . ($wpOptions['page_on_front'] ?? '?') . ')'
                        : 'Latest posts',
                    'Blog pages show at most' => ($wpOptions['posts_per_page'] ?? '') !== '' ? $wpOptions['posts_per_page'] . ' posts' : '',
                    'Discourage search engines' => ($wpOptions['blog_public'] ?? '1') === '0' ? 'Yes' : 'No',
                    'Comments on new posts' => ucfirst((string) ($wpOptions['default_comment_status'] ?? 'open')),
                    'Comment must be manually approved' => ($wpOptions['comment_moderation'] ?? '0') === '1' ? 'Yes' : 'No',
                    'Show avatars' => ($wpOptions['show_avatars'] ?? '1') === '0' ? 'No' : 'Yes',
                    'Thumbnail size' => (($wpOptions['thumbnail_size_w'] ?? '') !== '' ? $wpOptions['thumbnail_size_w'] . '×' . ($wpOptions['thumbnail_size_h'] ?? '?') : ''),
                    'Privacy policy page' => ((int) ($wpOptions['wp_page_for_privacy_policy'] ?? '0')) > 0
                        ? 'WordPress page ID ' . $wpOptions['wp_page_for_privacy_policy']
                        : '(none)',
                ];
            }
        } catch (\Throwable $exception) {
            $testResult = ['ok' => false, 'message' => ($formValues['source_type'] === 'wxr' ? 'Could not read the WXR file: ' : 'Could not connect: ') . $exception->getMessage()];
        }
    }

    if ($form === 'start_wordpress_import' && Csrf::verify('start_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        // Dry run shares this form/CSRF action with a real import — since
        // Csrf::verify() is one-time-use, branching here (not a second
        // elseif) is required. The Resume form never submits dry_run, so
        // an in-progress resume always takes the real-run branch below.
        // Test Connection already checked uploads_path, but that's a
        // separate, skippable submission — repeated here as a hard gate
        // (only when Media is selected) so media/featured images don't
        // silently import empty instead of failing loudly.
        $importOptionsToRun = $optionsFromPost();

        if (($importOptionsToRun['media'] ?? false) && !is_dir($formValues['uploads_path'])) {
            $importError = 'The uploads folder path does not exist or is not readable by the web server. Fix it, or uncheck "Media (attachments)" under Content to import.';
        } elseif (isset($_POST['dry_run'])) {
            try {
                $dryRunCounts = $buildImportService()->dryRunCounts($importOptionsToRun);
            } catch (\Throwable $exception) {
                $importError = 'Could not preview: ' . $exception->getMessage();
            }
        } else {
            // Runs as one long synchronous request rather than timing out
            // at PHP's default execution limit. Each stage's progress is
            // persisted as it completes, so an interruption leaves a
            // resumable batch behind rather than losing all progress.
            set_time_limit(0);

            $importProgress = new ImportProgress(LUMORA_ROOT);

            // Releases the session lock so the polling GET above can
            // observe progress instead of queuing behind this request.
            session_write_close();

            try {
                $service = $buildImportService();
                $submittedOptions = $importOptionsToRun;

                // A resumable batch continues with its own original
                // options, reflected here so the progress bar's stage
                // list matches what actually runs, not the Resume form.
                $preExisting = $service->inProgressBatch();
                $plannedStages = $preExisting['plannedStages'] ?? $service->plannedStages($submittedOptions);
                $completedStages = $preExisting['completedStages'] ?? [];

                $importProgress->reset(array_map(
                    static fn (string $stage): array => ['key' => $stage, 'label' => WordPressImportService::stageLabel($stage)],
                    $plannedStages,
                ));

                foreach ($completedStages as $alreadyDoneStage) {
                    $importProgress->stage($alreadyDoneStage);
                }

                $started = $service->startOrResume($submittedOptions);

                do {
                    $remainingStages = array_values(array_diff($plannedStages, $completedStages));

                    if ($remainingStages !== []) {
                        $importProgress->stage($remainingStages[0]);
                    }

                    $result = $service->runNextStage($started['batchId']);

                    if ($result['stage'] !== null) {
                        $completedStages[] = $result['stage'];
                    }
                } while ($result['done'] === false);

                $importProgress->complete();

                // The service's in-memory warnings() log doesn't survive
                // the redirect, so it's stashed in the session for one read.
                session_start();
                $_SESSION['lp_wordpress_import_warnings'] = $result['warnings'];

                header('Location: ' . admin_url('maintenance/import') . '?imported=1');
                exit;
            } catch (\Throwable $exception) {
                $importProgress->complete();
                $importError = $exception->getMessage();

                // Needed before the rest of the page renders — only
                // reached on failure, since success already redirected.
                session_start();
            }
        }
    }

    if ($form === 'remove_wordpress_import' && Csrf::verify('remove_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        // No source connection is needed — every id to delete lives in
        // content_import_records, not the source database — so this
        // avoids requiring the admin to re-enter DB credentials to roll back.
        $buildRegistryOnlyService()->removeAll();

        header('Location: ' . admin_url('maintenance/import') . '?removed=1');
        exit;
    }

    $imported = isset($_GET['imported']);
    $removed = isset($_GET['removed']);

    // Deliberately *not* unset after this read — an earlier version lost
    // genuine warnings the moment this screen was viewed a second time
    // before the admin read them. Left in session, it keeps showing the
    // most recent import's warnings until a new import overwrites it.
    if ($imported && isset($_SESSION['lp_wordpress_import_warnings'])) {
        $warnings = $_SESSION['lp_wordpress_import_warnings'];
    }

    // Separates a genuine follow-up action from a routine per-item
    // warning, so the summary screen can surface "look at this" items
    // in their own section instead of one long flat list.
    $actionNeededWarnings = array_values(array_filter($warnings, [WordPressImportService::class, 'isActionNeededWarning']));
    $routineWarnings = array_values(array_filter($warnings, static fn (string $warning): bool => !WordPressImportService::isActionNeededWarning($warning)));

    $registryOnlyService = $buildRegistryOnlyService();
    $inProgress = $registryOnlyService->inProgressBatch();
    $summary = $inProgress === null ? $registryOnlyService->lastImportSummary() : null;
    $redirectMappingReport = $summary !== null ? $registryOnlyService->redirectMappingReport($summary['batchId']) : [];

// Redirect Mapping report — a plain read-only GET, same reasoning as the
// ?ajax=progress branch above. Streamed rather than saved to disk since
// the data already lives in the database.
    if (($_GET['download'] ?? null) === 'redirect_mapping' && $redirectMappingReport !== []) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="redirect-mapping-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'wb');
        fputcsv($output, ['Old URL', 'New URL'], ',', '"', '\\');

        foreach ($redirectMappingReport as $row) {
            fputcsv($output, [$row['sourcePath'], $row['targetUrl']], ',', '"', '\\');
        }

        fclose($output);
        exit;
    }
}
?>
<h1 class="lp-admin__title">Import</h1>

<?php
// A one-time "Import Summary" screen shown immediately after a real
// import completes ($imported is true only right after that redirect).
// A `return` mid-view is safe since admin/index.php still requires
// layout-footer.php afterward regardless.
if ($wordPressImporterActive && $imported):
    $pluralLabels = [
        'post' => 'posts', 'page' => 'pages', 'user' => 'users',
        'category' => 'categories', 'tag' => 'tags', 'comment' => 'comments', 'media' => 'media',
        'nav_menu' => 'menus', 'widget_instance' => 'widgets',
    ];
    $displayCounts = $summary !== null
        ? array_filter($summary['counts'], static fn (string $type): bool => !str_ends_with($type, '_snap'), ARRAY_FILTER_USE_KEY)
        : [];
    ?>
    <section class="lp-admin__panel">
        <div class="lp-alert lp-alert--success">WordPress import complete.</div>

        <?php if ($displayCounts !== []): ?>
            <p class="lp-field__hint">
                <strong>Imported:</strong>
                <?= esc_html(implode(', ', array_map(
                    static fn (string $type, int $count): string => "{$count} " . ($count === 1 ? $type : ($pluralLabels[$type] ?? $type . 's')),
                    array_keys($displayCounts),
                    array_values($displayCounts),
                ))) ?>
            </p>
        <?php endif; ?>

        <?php if ($actionNeededWarnings !== []): ?>
            <div class="lp-alert lp-alert--warning">
                <strong>Action needed — <?= count($actionNeededWarnings) ?> item<?= count($actionNeededWarnings) === 1 ? '' : 's' ?>:</strong>
                <ul>
                    <?php foreach ($actionNeededWarnings as $warning): ?>
                        <li><?= esc_html($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($redirectMappingReport !== []): ?>
            <details class="lp-admin__panel">
                <summary><strong>Redirect Mapping</strong> (<?= count($redirectMappingReport) ?> old URL<?= count($redirectMappingReport) === 1 ? '' : 's' ?> now redirecting to new ones)</summary>
                <p class="lp-field__hint">
                    Every redirect this import created — a Simple Download
                    Monitor download that only linked off-site, or a
                    <code>_wp_old_slug</code> entry for a post/page whose
                    slug changed on the source site.
                    <a href="<?= esc_url(admin_url('maintenance/import') . '?download=redirect_mapping') ?>">Download as CSV</a>.
                </p>
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th scope="col">Old URL</th>
                            <th scope="col">New URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($redirectMappingReport as $row): ?>
                            <tr>
                                <td><code>/<?= esc_html($row['sourcePath']) ?></code></td>
                                <td><code><?= esc_html($row['targetUrl']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <?php if ($routineWarnings !== []): ?>
            <details class="lp-admin__panel">
                <summary><?= count($routineWarnings) ?> other item<?= count($routineWarnings) === 1 ? '' : 's' ?> skipped or had a problem</summary>
                <ul>
                    <?php foreach ($routineWarnings as $warning): ?>
                        <li><?= esc_html($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('maintenance/import')) ?>">Continue</a></p>
    </section>
    <?php
    return;
endif;
?>

<?php if ($wordPressImporterActive): ?>
    <?php if ($removed): ?>
        <div class="lp-alert lp-alert--success">All imported content was removed.</div>
    <?php endif; ?>

    <?php if ($importError !== null): ?>
        <div class="lp-alert lp-alert--error"><?= esc_html($importError) ?></div>
    <?php endif; ?>

    <?php if ($testResult !== null): ?>
        <div class="lp-alert <?= $testResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($testResult['message']) ?></div>
    <?php endif; ?>

    <?php if ($detectResult !== null): ?>
        <div class="lp-alert <?= $detectResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($detectResult['message']) ?></div>
    <?php endif; ?>

    <?php if ($sitePreview !== null): ?>
        <div class="lp-alert lp-alert--info">
            <strong>Source site settings (preview only — nothing is applied yet):</strong>
            <ul>
                <?php foreach ($sitePreview as $label => $value): ?>
                    <li><?= esc_html($label) ?>: <?= $value !== '' ? '<code>' . esc_html($value) . '</code>' : '<em>(not set)</em>' ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($dryRunCounts !== null): ?>
        <?php
        $pluralLabels = [
            'post' => 'posts', 'page' => 'pages', 'user' => 'users',
            'category' => 'categories', 'tag' => 'tags', 'comment' => 'comments', 'media' => 'media',
            'download' => 'downloads', 'nav_menu' => 'menus', 'widget_instance' => 'widgets',
            'nextgen_gallery' => 'NextGEN galleries', 'nextgen_picture' => 'NextGEN Gallery images',
        ];
        ?>
        <div class="lp-alert lp-alert--info">
            <strong>Preview (approximate — nothing was imported):</strong>
            <ul>
                <?php foreach ($dryRunCounts as $type => $count): ?>
                    <li><?= (int) $count ?> <?= esc_html($count === 1 ? $type : ($pluralLabels[$type] ?? $type . 's')) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="lp-field__hint">Actual imported counts can be lower — a missing uploads file, malformed row, or unsupported widget type is only caught during a real import.</p>
        </div>
    <?php endif; ?>

    <section class="lp-admin__panel">
        <h2>WordPress Importer</h2>

        <p class="lp-field__hint">
            Imports users, categories, tags, media, pages, posts, comments,
            menus, and classic widgets from an existing WordPress site — via
            a direct database connection, or a WordPress WXR (<code>.xml</code>)
            export file — plus a local copy of its <code>wp-content/uploads</code>
            folder. The database can be the WordPress site's own live
            server (point the host field at it directly) or a local copy
            you've restored from a backup; either way, and whichever source
            type is used, the uploads folder must already be readable on
            this server's local filesystem — it is never fetched remotely.
            A WXR export carries only content (posts, pages, media,
            comments, users, terms) — it has no representation of site
            settings, widgets, or a plugin's own custom tables, so those
            are only ever imported from a direct database connection; a
            WXR-sourced user also always imports as Subscriber, since WXR
            carries no role data at all. Site title, tagline, timezone,
            date/time format, and permalink structure can optionally be
            imported too from a database connection (off by default — see
            "Site settings" below).
        </p>

        <p class="lp-field__hint">Also recognizes data from these WordPress plugins, if installed on the source site:</p>
        <ul class="lp-field__hint">
            <li>
                <strong>Simple Download Monitor</strong> — its downloads
                import as real Media items (filed into matching Media
                Manager Folders) or Redirects (with a working, seeded hit
                counter) for an external-URL-only download, carrying their
                real download counts across from a database connection —
                a WXR export's own <code>sdm_count_offset</code> value is
                used alone, since the per-visit download log itself is
                never exported. Any page still using
                <code>[sdm_show_dl_from_category]</code> automatically
                renders a real list of those downloads after import — no
                manual page editing needed.
            </li>
            <li>
                <strong>Media Library Folders</strong> — its Media Library
                folder organization imports into matching Media Manager
                Folders, preserving the source site's own nesting, instead
                of every attachment landing with no folder at all.
            </li>
            <li>
                <strong>NextGEN Gallery</strong> — each gallery's images
                import as real Media items filed into a Media Manager
                Folder named after the gallery. Requires a local filesystem
                copy of the source site's own <code>wp-content/gallery</code>
                folder (separate from the uploads folder above — see the
                "Gallery folder path" field below). A page using
                <code>[nggallery id=...]</code>, <code>[album id=...]</code>,
                <code>[ngg_images ...]</code>, or <code>[ngg src=... ids=...]</code>
                automatically renders a real image grid after import — no
                manual page editing needed. <code>[nggtags ...]</code> and
                <code>[ngg_slideshow ...]</code> still have no Lumora Press
                equivalent and are listed in the warnings below instead.
            </li>
        </ul>

        <?php
        // Shared by the Resume, Test Connection, and Import forms — each
        // still POSTs independently with its own CSRF token, this just
        // avoids three physical copies of the markup. Both field sets
        // render unconditionally; the radio alone decides which one the
        // server reads on submit, so neither needs `required`.

        $renderConnectionFields = static function (string $idPrefix) use ($formValues): void {
            ?>
            <ul class="lp-field__hint">
                <li>
                    <strong>Direct database connection</strong> when you can reach the source site's
                    database directly (its own live server, or a locally restored backup) — it's the
                    only way to also bring in Site Settings, Widgets, and real user roles, none of
                    which a WXR export can carry.
                </li>
                <li>
                    <strong>WXR (.xml) export file</strong> when you only have an export file (from
                    WordPress's own Tools &rsaquo; Export, or a host/migration that hands you one) and
                    no database access — it still imports users, categories/tags, media, pages, posts,
                    comments, and menus, but every imported user lands as Subscriber and there's
                    nothing to import for Site Settings or Widgets.
                </li>
                <li>
                    Either way, you'll also need a local filesystem copy of the source site's
                    <code>wp-content/uploads</code> folder, readable by this server's PHP process —
                    neither option fetches files remotely, and a WXR export's own attachment entries
                    only record where each file used to live, not the file itself.
                </li>
            </ul>

            <p class="lp-field lp-field--radio">
                <label>
                    <input type="radio" name="source_type" value="database" <?= $formValues['source_type'] === 'database' ? 'checked' : '' ?>>
                    Direct database connection
                </label>
                <label>
                    <input type="radio" name="source_type" value="wxr" <?= $formValues['source_type'] === 'wxr' ? 'checked' : '' ?>>
                    WordPress WXR (.xml) export file
                </label>
            </p>

            <p class="lp-field">
                <label for="<?= esc_attr($idPrefix) ?>-db-host">Database host</label>
                <input type="text" id="<?= esc_attr($idPrefix) ?>-db-host" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>">
            </p>
            <p class="lp-field">
                <label for="<?= esc_attr($idPrefix) ?>-db-port">Database port</label>
                <input type="text" id="<?= esc_attr($idPrefix) ?>-db-port" name="db_port" value="<?= esc_attr($formValues['db_port']) ?>">
            </p>
            <p class="lp-field">
                <label for="<?= esc_attr($idPrefix) ?>-db-name">Database name</label>
                <input type="text" id="<?= esc_attr($idPrefix) ?>-db-name" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>">
            </p>
            <p class="lp-field">
                <label for="<?= esc_attr($idPrefix) ?>-db-user">Database username</label>
                <input type="text" id="<?= esc_attr($idPrefix) ?>-db-user" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>">
            </p>
            <p class="lp-field">
                <label for="<?= esc_attr($idPrefix) ?>-db-password">Database password</label>
                <input type="password" id="<?= esc_attr($idPrefix) ?>-db-password" name="db_password" value="<?= esc_attr($formValues['db_password']) ?>">
            </p>
            <p class="lp-field">
                <label for="<?= esc_attr($idPrefix) ?>-db-prefix">Table prefix</label>
                <input type="text" id="<?= esc_attr($idPrefix) ?>-db-prefix" name="db_prefix" value="<?= esc_attr($formValues['db_prefix']) ?>">
                <span class="lp-field__hint">Only used with "Direct database connection" above.</span>
            </p>

            <p class="lp-field">
                <label for="<?= esc_attr($idPrefix) ?>-wxr-path">Path to WXR (.xml) export file</label>
                <input type="text" id="<?= esc_attr($idPrefix) ?>-wxr-path" name="wxr_path" value="<?= esc_attr($formValues['wxr_path']) ?>" placeholder="/path/to/export.xml">
                <span class="lp-field__hint">Only used with "WordPress WXR (.xml) export file" above — an absolute path this server's PHP process can read.</span>
            </p>
            <?php
        };
        ?>

        <?php if ($summary !== null): ?>
            <?php
            $pluralLabels = [
                'post' => 'posts', 'page' => 'pages', 'user' => 'users',
                'category' => 'categories', 'tag' => 'tags', 'comment' => 'comments', 'media' => 'media',
                'nav_menu' => 'menus', 'widget_instance' => 'widgets',
            ];
            // "*_snap" entries are internal pre-import snapshots, not
            // real imported content, so excluded from this summary line.
            $displayCounts = array_filter($summary['counts'], static fn (string $type): bool => !str_ends_with($type, '_snap'), ARRAY_FILTER_USE_KEY);
            ?>
            <p class="lp-field__hint">
                <strong>Last imported:</strong>
                <?= esc_html(implode(', ', array_map(
                    static fn (string $type, int $count): string => "{$count} " . ($count === 1 ? $type : ($pluralLabels[$type] ?? $type . 's')),
                    array_keys($displayCounts),
                    array_values($displayCounts),
                ))) ?>
                <?php if ($summary['createdAt'] !== null): ?>
                    at <?= esc_html($summary['createdAt']->format('Y-m-d H:i')) ?>
                <?php endif; ?>
            </p>

            <?php if ($redirectMappingReport !== []): ?>
                <details class="lp-admin__panel">
                    <summary><strong>Redirect Mapping</strong> (<?= count($redirectMappingReport) ?> old URL<?= count($redirectMappingReport) === 1 ? '' : 's' ?> now redirecting to new ones)</summary>
                    <p class="lp-field__hint">
                        Every redirect this import created — a Simple Download
                        Monitor download that only linked off-site, or a
                        <code>_wp_old_slug</code> entry for a post/page whose
                        slug changed on the source site.
                        <a href="<?= esc_url(admin_url('maintenance/import') . '?download=redirect_mapping') ?>">Download as CSV</a>.
                    </p>
                    <table class="lp-table">
                        <thead>
                            <tr>
                                <th scope="col">Old URL</th>
                                <th scope="col">New URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($redirectMappingReport as $row): ?>
                                <tr>
                                    <td><code>/<?= esc_html($row['sourcePath']) ?></code></td>
                                    <td><code><?= esc_html($row['targetUrl']) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <p class="lp-field__hint">
                Remove the existing import before importing again — or, to bring in
                new content from the same source without starting over, use the
                Import form below with "Skip existing content" or "Overwrite existing
                content" selected under Import Options.
            </p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" data-lp-confirm="Remove everything this import created? This cannot be undone.">
                <?= Csrf::field('remove_wordpress_import') ?>
                <input type="hidden" name="form" value="remove_wordpress_import">
                <button type="submit" class="lp-button lp-button--danger">Remove All Imported Content</button>
            </form>
        <?php endif; ?>

        <?php if ($inProgress !== null): ?>
            <div class="lp-alert lp-alert--warning">
                A previous import was interrupted after
                <?= count($inProgress['completedStages']) ?> of <?= count($inProgress['plannedStages']) ?> stage(s)
                (<?= esc_html(implode(', ', array_map([WordPressImportService::class, 'stageLabel'], $inProgress['completedStages']))) ?> completed so far).
                Re-enter the same source connection details to continue —
                the content types originally selected are used again
                automatically; they can't be changed for a resumed import.
            </div>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('maintenance/import')) ?>?ajax=progress" data-lp-update-progress-target="lp-import-progress-resume">
                <?= Csrf::field('start_wordpress_import') ?>
                <input type="hidden" name="form" value="start_wordpress_import">

                <?php $renderConnectionFields('wp-import-resume'); ?>
                <p class="lp-field">
                    <label for="wp-import-resume-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="wp-import-resume-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
                </p>
                <p class="lp-field">
                    <label for="wp-import-resume-gallery-path">Gallery folder path (server filesystem)</label>
                    <input type="text" id="wp-import-resume-gallery-path" name="gallery_path" value="<?= esc_attr($formValues['gallery_path']) ?>" placeholder="/path/to/wp-content/gallery">
                    <span class="lp-field__hint">Only needed if the interrupted import's plan includes a NextGEN Gallery stage.</span>
                </p>
                <input type="hidden" name="wp_config_path" value="<?= esc_attr($formValues['wp_config_path']) ?>">

                <ul id="lp-import-progress-resume" class="lp-update-progress" hidden></ul>

                <button type="submit" class="lp-button lp-button--primary">Resume Import</button>
            </form>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" class="lp-admin__inline-form" data-lp-confirm="Remove everything this interrupted import created so far? This cannot be undone.">
                <?= Csrf::field('remove_wordpress_import') ?>
                <input type="hidden" name="form" value="remove_wordpress_import">
                <button type="submit" class="lp-button lp-button--danger">Discard This Import</button>
            </form>
        <?php else: ?>
            <?php
            // Separate forms, each with its own CSRF action name, rather
            // than one form with buttons sharing a token — a shared name
            // across buttons silently breaks all but the last rendered.
            ?>
            <h3>Auto-detect from wp-config.php</h3>

            <p class="lp-field__hint">
                Only relevant for a direct database connection below — a WXR export needs no
                <code>wp-config.php</code> at all. If the source site's <code>wp-config.php</code> is
                readable on this server's local filesystem (the same requirement as the uploads folder
                path below), point this at it to pre-fill the database connection and uploads folder
                fields below. Nothing is read from <code>wp-config.php</code> beyond its
                <code>DB_*</code>/<code>$table_prefix</code>/<code>WP_CONTENT_DIR</code>/<code>UPLOADS</code>
                values — it is never executed. Every pre-filled field below stays fully editable; use
                this as a shortcut, not a requirement.
            </p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" id="wp-import-detect-form">
                <?= Csrf::field('detect_wp_config') ?>
                <input type="hidden" name="form" value="detect_wp_config">

                <p class="lp-field">
                    <label for="wp-import-wp-config-path">Path to wp-config.php</label>
                    <input type="text" id="wp-import-wp-config-path" name="wp_config_path" value="<?= esc_attr($formValues['wp_config_path']) ?>" placeholder="/path/to/wordpress/wp-config.php">
                </p>

                <?php
                // This form only collects wp_config_path; every other
                // field is carried forward as a hidden input so Detect
                // doesn't blank them out.
                ?>
                <input type="hidden" name="source_type" value="<?= esc_attr($formValues['source_type']) ?>">
                <input type="hidden" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>">
                <input type="hidden" name="db_port" value="<?= esc_attr($formValues['db_port']) ?>">
                <input type="hidden" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>">
                <input type="hidden" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>">
                <input type="hidden" name="db_password" value="<?= esc_attr($formValues['db_password']) ?>">
                <input type="hidden" name="db_prefix" value="<?= esc_attr($formValues['db_prefix']) ?>">
                <input type="hidden" name="wxr_path" value="<?= esc_attr($formValues['wxr_path']) ?>">
                <input type="hidden" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>">
                <input type="hidden" name="gallery_path" value="<?= esc_attr($formValues['gallery_path']) ?>">

                <button type="submit" class="lp-button lp-button--secondary">Detect from wp-config.php</button>
            </form>

            <h3>Source</h3>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" id="wp-import-test-form">
                <?= Csrf::field('test_wordpress_connection') ?>
                <input type="hidden" name="form" value="test_wordpress_connection">

                <?php $renderConnectionFields('wp-import'); ?>

                <h3>Source files</h3>

                <p class="lp-field">
                    <label for="wp-import-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="wp-import-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
                    <span class="lp-field__hint">An absolute path this server's PHP process can read — the source site's <code>wp-content/uploads</code> folder.</span>
                </p>

                <?php
                // Carried forward so Test Connection doesn't blank out
                // fields that live only on the Detect/Import forms.
                ?>
                <input type="hidden" name="gallery_path" value="<?= esc_attr($formValues['gallery_path']) ?>">
                <input type="hidden" name="wp_config_path" value="<?= esc_attr($formValues['wp_config_path']) ?>">

                <button type="submit" class="lp-button lp-button--secondary">Test Connection</button>
            </form>

            <?php
            // Renders once via this closure, inside the single Import
            // form below. Dry run and a real Start/Resume Import share
            // one form/CSRF action; a "Dry run" checkbox decides which
            // the server does. This closure avoids duplicating the
            // id-prefixing logic the smaller Resume form above also needs.
            $renderSharedImportFields = static function (string $idPrefix) use ($formValues, $renderConnectionFields): void {
                $renderConnectionFields($idPrefix);
                ?>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
                </p>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-gallery-path">Gallery folder path (server filesystem)</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-gallery-path" name="gallery_path" value="<?= esc_attr($formValues['gallery_path']) ?>" placeholder="/path/to/wp-content/gallery">
                    <span class="lp-field__hint">
                        Only needed for "NextGEN Gallery" below — a local filesystem copy of the source
                        site's <code>wp-content/gallery</code> folder (a sibling of <code>wp-content/uploads</code>
                        above, not a subfolder of it). Leave blank if the source site never ran NextGEN Gallery.
                    </span>
                </p>

                <?php
                // Carried forward so submitting Import doesn't blank out
                // the wp-config.php path field that lives only on the
                // Detect form.
                ?>
                <input type="hidden" name="wp_config_path" value="<?= esc_attr($formValues['wp_config_path']) ?>">

                <h4>Site settings</h4>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_site_settings" value="1">
                        Site, Homepage, Reading, Discussion, Media, and Privacy settings
                    </label>
                    <span class="lp-field__hint">
                        Overwrites this site's own Settings &rsaquo; General/Permalinks/Reading/Discussion/
                        Media/Privacy values with the source site's. Off by default — leave unchecked to keep
                        this site's existing settings. A source permalink structure using a tag Lumora Press
                        doesn't support (e.g. <code>%post_id%</code>) is skipped and noted in the warnings
                        below rather than applied. The Homepage and Privacy Policy Page settings each name a
                        WordPress page — only applied once the "Pages" content type below has actually
                        imported that page; a page that wasn't imported is skipped and noted in the warnings
                        instead of pointing at content that doesn't exist here. Only ever available from a
                        direct database connection — a WXR export has no representation of site options at
                        all, so this checkbox has nothing to import from a WXR-sourced import.
                    </span>
                </p>

                <h4>Content to import</h4>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_users" value="1" checked>
                        Users (authors)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_categories" value="1" checked>
                        Categories &amp; tags
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_media" value="1" checked>
                        Media (attachments)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_nextgen_galleries" value="1" checked>
                        NextGEN Gallery galleries (if installed — requires the "Gallery folder path" above)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_downloads" value="1" checked>
                        Downloads (Simple Download Monitor, if installed)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_pages" value="1" checked>
                        Pages
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_posts" value="1" checked>
                        Posts
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_comments" value="1" checked>
                        Comments (on imported posts only — not pages)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_menus" value="1" checked>
                        Menus (each becomes a named menu — not auto-assigned to a location; do that afterward from Appearance &rsaquo; Menus)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_widgets" value="1" checked>
                        Widgets (only types with a Lumora Press equivalent; the rest are skipped and listed in the warnings below — none at all from a WXR-sourced import, which never carries widget configuration)
                    </label>
                </p>

                <h4>Import options</h4>

                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-stage-delay">Delay between stages (milliseconds)</label>
                    <input type="number" id="<?= esc_attr($idPrefix) ?>-stage-delay" name="stage_delay_ms" value="0" min="0" step="100">
                    <span class="lp-field__hint">
                        Only useful when the source is a live production server rather than a local/staging copy —
                        pauses briefly between each stage (Users, Categories, Media, Pages, Posts, ...) so this
                        import doesn't hammer a shared-hosting site's database and web server back-to-back for its
                        entire duration. Leave at 0 for a local or staging source.
                    </span>
                </p>

                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-existing-content">When content already exists</label>
                    <select id="<?= esc_attr($idPrefix) ?>-existing-content" name="existing_content">
                        <option value="">Refuse to start (default) — remove the existing import first</option>
                        <option value="skip">Skip existing content — leave it unchanged, only import what's new</option>
                        <option value="overwrite">Overwrite existing content — update it with the source's current values</option>
                    </select>
                    <span class="lp-field__hint">
                        Only matters when re-importing from the <em>same</em> source after content from it was
                        already imported before ("Last imported" above). Every Post, Page, Comment, and Media
                        attachment this import creates is matched back to the source's own id, so "Skip"/
                        "Overwrite" can tell "already imported this one" apart from "genuinely new since last
                        time" — Users already always reuse a matching existing account regardless of this
                        setting. Downloads and NextGEN Gallery images are always created fresh either way, not
                        yet covered by this setting. "Overwrite" never replaces a Media item's underlying file,
                        only its alt text/caption/description/folder, and never changes a Comment's author or
                        thread position, only its content/status — see this plugin's own README for the exact
                        boundaries of what each type's Overwrite actually updates.
                    </span>
                </p>
                <?php
            };
            ?>

            <h3>Import</h3>

            <p class="lp-field__hint">Connection details from Test Connection above are already filled in below — double-check them before importing.</p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('maintenance/import')) ?>?ajax=progress" data-lp-update-progress-target="lp-import-progress">
                <?= Csrf::field('start_wordpress_import') ?>
                <input type="hidden" name="form" value="start_wordpress_import">
                <?php $renderSharedImportFields('wp-import-run'); ?>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="dry_run" value="1">
                        Dry run (preview only — makes no changes)
                    </label>
                    <span class="lp-field__hint">
                        Reads the source database and reports approximate counts per content type for the
                        selection above, without importing or changing anything. Uncheck to actually import.
                    </span>
                </p>

                <div class="lp-alert lp-alert--warning">
                    A real site's content can take a long time to import.
                    This runs as one request — do not navigate away or
                    close the tab while it's in progress. If it's interrupted
                    anyway, revisiting this page offers to resume from where
                    it left off. (Doesn't apply to a dry run above, which
                    finishes immediately.)
                </div>

                <ul id="lp-import-progress" class="lp-update-progress" hidden></ul>

                <button type="submit" class="lp-button lp-button--primary">Import</button>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="lp-admin__panel">
        <p class="lp-field__hint">Activate the WordPress Importer plugin to migrate content from an existing WordPress site.</p>
    </section>
<?php endif; ?>
