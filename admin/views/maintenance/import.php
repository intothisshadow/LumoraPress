<?php

/**
 * The admin Maintenance > Import screen: Lumora Press export import, plus the WordPress Importer plugin's connection, dry run, run/resume, progress, and rollback flow.
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

use LumoraPress\Core\Filesystem\SiblingDirectoryScanner;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\Downloads\DownloadCategoryService;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\WordPressImporter\ImportLock;
use LumoraPress\Plugins\WordPressImporter\ImportProgress;
use LumoraPress\Plugins\WordPressImporter\WordPressConfigParser;
use LumoraPress\Plugins\WordPressImporter\WordPressImportService;
use LumoraPress\Plugins\WordPressImporter\WordPressSource;
use LumoraPress\Plugins\WordPressImporter\WordPressSourceInterface;
use LumoraPress\Plugins\WordPressImporter\WordPressXmlSource;
use LumoraPress\Services\Import\ExistingContentMode;
use LumoraPress\Services\Import\LumoraPressExportSource;
use LumoraPress\Services\Import\LumoraPressImportService;

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

// Lumora Press export (.zip) import — core, so available whether or not
// the WordPress Importer plugin is active. Runs in one request, like the
// WordPress import below; the extracted archive is always removed after.
$nativeImportError = null;

// Shared by the upload and server-path forms. Returns an error message,
// or redirects to the summary on success.
$runNativeImport = static function (string $zipPath) use ($kernel, $currentUser): string {
    set_time_limit(0);
    $skip = ($_POST['existing_content'] ?? null) === 'skip';
    $exportSource = null;
    $result = null;

    try {
        $exportSource = LumoraPressExportSource::open(
            $zipPath,
            LUMORA_ROOT . '/storage/imports',
            is_string($_POST['uploads_folder'] ?? null) ? $_POST['uploads_folder'] : null,
        );
        $result = (new LumoraPressImportService(
            userImporter: $kernel->userImporter,
            postImporter: $kernel->postImporter,
            pageImporter: $kernel->pageImporter,
            mediaImporter: $kernel->mediaImporter,
            commentImporter: $kernel->commentImporter,
            menuImporter: $kernel->menuImporter,
            widgetImporter: $kernel->widgetImporter,
            categories: $kernel->categories,
            tags: $kernel->tags,
            folders: $kernel->folders,
            menus: $kernel->menus,
            widgets: $kernel->widgets,
            config: $kernel->config,
            registry: $kernel->contentImportRegistry,
            siteUrl: home_url(),
            uploadsUrl: home_url('content/uploads'),
            fallbackUserId: $currentUser->id,
        ))->import($exportSource->content(), $skip ? ExistingContentMode::Skip : ExistingContentMode::Overwrite);
        $result['siteName'] = $exportSource->content()->siteName;
        $result['mode'] = $skip ? 'skip' : 'overwrite';
    } catch (\RuntimeException $exception) {
        return 'Could not import this file: ' . $exception->getMessage();
    } finally {
        $exportSource?->cleanup();
    }

    $_SESSION['lp_lumora_press_import'] = $result;

    header('Location: ' . admin_url('maintenance/import') . '?lumora_imported=1');
    exit;
};

// One form for both sources, like Maintenance > Updates: the "Upload a
// File" / "File on This Server" tabs set import_source. A server file is
// one the admin copied there themselves (FTP, SFTP, a host's file
// manager) for exports bigger than the upload limit; it's left in place.
if (($_POST['form'] ?? null) === 'import_lumora_press_export' && Csrf::verify('import_lumora_press_export', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    if (($_POST['import_source'] ?? null) === 'server') {
        try {
            $nativeImportError = $runNativeImport(LumoraPressExportSource::resolveServerPath(is_string($_POST['export_path'] ?? null) ? $_POST['export_path'] : ''));
        } catch (\RuntimeException $exception) {
            $nativeImportError = $exception->getMessage();
        }
    } else {
        $upload = $_FILES['export_file'] ?? null;
        $uploadError = is_array($upload) ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $nativeImportError = 'That file is larger than this server accepts for uploads (' . ini_get('upload_max_filesize') . '). Copy it onto the server instead and use the "File on This Server" tab.';
        } elseif ($uploadError === UPLOAD_ERR_NO_FILE) {
            $nativeImportError = 'Choose a Lumora Press export file to upload.';
        } elseif ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            $nativeImportError = 'The upload failed. Please try again.';
        } else {
            $nativeImportError = $runNativeImport((string) $upload['tmp_name']);
        }
    }
}

// Falls back to $_GET so a Discover link can pre-fill it without a form of its own.
$nativeExportPath = is_string($_POST['export_path'] ?? null) ? $_POST['export_path'] : (is_string($_GET['export_path'] ?? null) ? $_GET['export_path'] : '');
$nativeUploadsFolder = is_string($_POST['uploads_folder'] ?? null) ? $_POST['uploads_folder'] : (is_string($_GET['uploads_folder'] ?? null) ? $_GET['uploads_folder'] : '');

// Each Discover link keeps whatever the other pre-fill currently holds.
$nativeImportUrl = static function (array $params, string $anchor) use ($nativeExportPath, $nativeUploadsFolder): string {
    $query = array_filter(['tab' => 'lumora-press', 'export_path' => $nativeExportPath, 'uploads_folder' => $nativeUploadsFolder, ...$params], static fn (string $value): bool => $value !== '');

    return admin_url('maintenance/import') . '?' . http_build_query($query) . '#' . $anchor;
};

// Another Lumora Press install on this same server is the usual source.
$nativeUploadsDiscovered = isset($_GET['discover_upload_folders'])
    ? array_map(static fn (string $site): string => $site . '/content/uploads', SiblingDirectoryScanner::scan(dirname(LUMORA_ROOT), 'content/uploads', [LUMORA_ROOT]))
    : null;
$nativeDiscovered = isset($_GET['discover_exports'])
    ? LumoraPressExportSource::discoverArchives([
        dirname(LUMORA_ROOT),
        LUMORA_ROOT,
        ...SiblingDirectoryScanner::scan(dirname(LUMORA_ROOT), null, [LUMORA_ROOT]),
    ])
    : null;

// WordPress is often installed alongside Lumora Press, but also in the directory
// Lumora Press itself sits in, or the one above it, so all of those are searched.
// $marker is a path that only a real WordPress root contains.
$discoverWordPressDirectories = static function (string $marker): array {
    $candidates = [];

    foreach ([LUMORA_ROOT, dirname(LUMORA_ROOT)] as $directory) {
        $resolved = realpath($directory);

        if ($resolved !== false && file_exists($resolved . '/' . $marker)) {
            $candidates[] = $resolved;
        }
    }

    return array_values(array_unique([
        ...$candidates,
        ...SiblingDirectoryScanner::scan(LUMORA_ROOT, $marker),
        ...SiblingDirectoryScanner::scan(dirname(LUMORA_ROOT), $marker, [LUMORA_ROOT]),
    ]));
};

$nativeImportResult = isset($_GET['lumora_imported']) && is_array($_SESSION['lp_lumora_press_import'] ?? null) ? $_SESSION['lp_lumora_press_import'] : null;

$importError = null;
$importPaused = false;
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
    // Falls back to $_GET (not just $_POST, unlike every other field
    // here) so a plain "Discover" link can pre-fill these two without a
    // form submission of its own — nothing to detect/parse, just a path
    // to suggest, so no state-changing POST/CSRF is warranted.
    'uploads_path' => is_string($_POST['uploads_path'] ?? null) ? $_POST['uploads_path'] : (is_string($_GET['uploads_path'] ?? null) ? $_GET['uploads_path'] : ''),
    'gallery_path' => is_string($_POST['gallery_path'] ?? null) ? $_POST['gallery_path'] : (is_string($_GET['gallery_path'] ?? null) ? $_GET['gallery_path'] : ''),
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

    // 'detect_wp_config_discovered' is the identical handler, reached by
    // clicking a "Discover" candidate below instead of typing a path by
    // hand — same logic, distinct CSRF action name so its own token
    // (rendered once, reused across every candidate form) doesn't
    // collide with the manual form's.
    if (in_array($form, ['detect_wp_config', 'detect_wp_config_discovered'], true) && Csrf::verify($form, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
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

    if ($form === 'test_wordpress_connection' && Csrf::verify('start_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
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
            // Each request runs stages only until a time budget is spent,
            // then answers; import-continue.js posts the form again until
            // everything is done. A long import therefore never depends on
            // the host's request timeout, and a stage that copies many files
            // (Media) stops and resumes at its own cursor. Progress is
            // persisted after every call, so an interruption leaves a
            // resumable batch behind rather than losing all progress.
            $isAjaxImportRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
            $sliceSeconds = max(1.0, (float) apply_filters('wordpress_importer_request_seconds', 15.0));
            $sliceDeadline = microtime(true) + $sliceSeconds;

            // A single non-resumable stage (a big Posts import) can still run
            // past the budget, and a proxy dropping the connection must not
            // abort the call before its progress is saved.
            set_time_limit(0);
            ignore_user_abort(true);

            $respondImportJson = static function (array $payload): never {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                header('Content-Type: application/json');
                echo json_encode($payload);
                exit;
            };

            $importProgress = new ImportProgress(LUMORA_ROOT);

            // Releases the session lock so the polling GET above can
            // observe progress instead of queuing behind this request.
            session_write_close();

            // Two requests driving one import (a form submitted from two tabs) would each
            // try to run the same stage. The second one waits; the lock frees itself when
            // the first one's process ends.
            $importLock = new ImportLock(LUMORA_ROOT);

            if (!$importLock->acquire()) {
                session_start();
                $busyMessage = 'Another request is already working on this import — waiting for it to finish…';

                if ($isAjaxImportRequest) {
                    $respondImportJson(['done' => false, 'busy' => true, 'detail' => $busyMessage, 'csrf_token' => Csrf::token('start_wordpress_import')]);
                }

                $importError = 'Another request is already working on this import. Wait a moment, then reload this page.';
            } else {
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
                    $service->rememberConnection($started['batchId'], $formValues);

                    do {
                        $remainingStages = array_values(array_diff($plannedStages, $completedStages));

                        if ($remainingStages !== []) {
                            $importProgress->stage($remainingStages[0]);
                        }

                        $result = $service->runNextStage($started['batchId'], max(0.5, $sliceDeadline - microtime(true)));

                        if ($result['stageComplete'] && $result['stage'] !== null) {
                            $completedStages[] = $result['stage'];
                        }
                    } while ($result['done'] === false && microtime(true) < $sliceDeadline);

                    if ($result['done'] === false) {
                        // Budget spent with work left. A JavaScript client posts
                        // again straight away; without it the page below shows
                        // the Resume panel for the same batch.
                        session_start();

                        if ($isAjaxImportRequest) {
                            $respondImportJson([
                                'done' => false,
                                'stages' => $importProgress->read()['stages'],
                                'detail' => $result['progress'] !== null
                                    ? WordPressImportService::stageLabel((string) $result['stage']) . ': ' . number_format($result['progress']['processed']) . ' of ' . number_format($result['progress']['total'])
                                    : '',
                                // A fresh single-use token for the next round, since
                                // a JSON response has no form to read one from.
                                'csrf_token' => Csrf::token('start_wordpress_import'),
                            ]);
                        }

                        $importPaused = true;
                    } else {
                        $importProgress->complete();

                        // The service's in-memory warnings() log doesn't survive
                        // the redirect, so it's stashed in the session for one read.
                        session_start();
                        $_SESSION['lp_wordpress_import_warnings'] = $result['warnings'];

                        $importedUrl = admin_url('maintenance/import') . '?imported=1';

                        if ($isAjaxImportRequest) {
                            $respondImportJson(['done' => true, 'redirect' => $importedUrl]);
                        }

                        header('Location: ' . $importedUrl);
                        exit;
                    }
                } catch (\Throwable $exception) {
                    $importProgress->complete();
                    $importError = $exception->getMessage();

                    // Needed before the rest of the page renders, and before a
                    // JSON error reply that needs a fresh token to retry with.
                    session_start();

                    if ($isAjaxImportRequest) {
                        $respondImportJson(['done' => false, 'error' => $importError, 'csrf_token' => Csrf::token('start_wordpress_import')]);
                    }
                }
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

    // Pre-fills the Resume form from what the interrupted import was started
    // with, except for anything the current request itself supplied. The
    // password is only there when it could be stored encrypted.
    if ($inProgress !== null) {
        foreach ($inProgress['connection'] as $field => $value) {
            if (array_key_exists($field, $formValues) && !isset($_POST[$field]) && !isset($_GET[$field])) {
                $formValues[$field] = $value;
            }
        }
    }
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

// Mirrors Maintenance > Tools' Sweep tab: the WordPress tab only exists
// while its plugin is active. The WordPress flow's own forms, redirects,
// and Discover links don't carry ?tab=, so their state reopens that tab.
$importTabs = ['lumora-press' => 'Lumora Press'];

if ($wordPressImporterActive) {
    $importTabs['wordpress'] = 'WordPress';
}

$wordPressStateKeys = ['removed', 'discover_uploads', 'discover_wp_config', 'uploads_path', 'gallery_path'];
$postedForm = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$isWordPressRequest = $wordPressImporterActive && (
    ($postedForm !== '' && !str_starts_with($postedForm, 'import_lumora_press_export'))
    || array_intersect_key($_GET, array_flip($wordPressStateKeys)) !== []
);
$activeImportTab = is_string($_GET['tab'] ?? null) && isset($importTabs[$_GET['tab']])
    ? $_GET['tab']
    : ($isWordPressRequest ? 'wordpress' : 'lumora-press');
?>
<h1 class="lp-admin__title">Import</h1>

<?php if ($nativeImportResult !== null): ?>
    <?php
    $nativeLabels = [
        'user' => ['user', 'users'], 'folder' => ['media folder', 'media folders'], 'media' => ['media file', 'media files'],
        'category' => ['category', 'categories'], 'tag' => ['tag', 'tags'], 'page' => ['page', 'pages'], 'post' => ['post', 'posts'],
        'comment' => ['comment', 'comments'], 'nav_menu' => ['menu', 'menus'], 'widget_instance' => ['widget', 'widgets'],
    ];
    $nativeCounts = is_array($nativeImportResult['counts'] ?? null) ? $nativeImportResult['counts'] : [];
    $nativeWarnings = is_array($nativeImportResult['warnings'] ?? null) ? $nativeImportResult['warnings'] : [];
    ?>
    <section class="lp-admin__panel">
        <div class="lp-alert lp-alert--success">
            Imported content from &ldquo;<?= esc_html((string) ($nativeImportResult['siteName'] ?? '')) ?>&rdquo;.
        </div>

        <p class="lp-field__hint">
            <strong>Added:</strong>
            <?php if ($nativeCounts === []): ?>
                nothing new — everything in this file had already been imported before, and was <?= esc_html(($nativeImportResult['mode'] ?? '') === 'skip' ? 'left unchanged' : 'updated in place') ?>.
            <?php else: ?>
                <?= esc_html(implode(', ', array_map(
                    static fn (string $type, int $count): string => $count . ' ' . ($nativeLabels[$type][$count === 1 ? 0 : 1] ?? $type),
                    array_keys($nativeCounts),
                    array_values($nativeCounts),
                ))) ?>.
            <?php endif; ?>
        </p>

        <?php if (($nativeCounts['user'] ?? 0) > 0): ?>
            <p class="lp-field__hint">
                Imported users have no password on this site yet — each one needs
                to use &ldquo;Forgot password?&rdquo; on the login screen before
                signing in.
            </p>
        <?php endif; ?>

        <?php if (($nativeCounts['nav_menu'] ?? 0) > 0): ?>
            <p class="lp-field__hint">
                Menus that were assigned to a theme location are only placed there
                if that location was empty; assign the rest from Appearance &rsaquo; Menus.
            </p>
        <?php endif; ?>

        <?php if ($nativeWarnings !== []): ?>
            <details class="lp-admin__panel">
                <summary><?= count($nativeWarnings) ?> item<?= count($nativeWarnings) === 1 ? '' : 's' ?> skipped or had a problem</summary>
                <ul>
                    <?php foreach ($nativeWarnings as $warning): ?>
                        <li><?= esc_html((string) $warning) ?></li>
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

<div class="lp-tabs">
    <div class="lp-tabs__list" role="tablist" aria-label="Import source">
        <?php foreach ($importTabs as $tabKey => $tabLabel): ?>
            <button type="button" class="lp-tabs__tab" id="lp-tab-<?= esc_attr($tabKey) ?>" role="tab" aria-selected="<?= $activeImportTab === $tabKey ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-<?= esc_attr($tabKey) ?>" tabindex="<?= $activeImportTab === $tabKey ? '0' : '-1' ?>"><?= esc_html($tabLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-lumora-press" role="tabpanel" aria-labelledby="lp-tab-lumora-press"<?= $activeImportTab === 'lumora-press' ? '' : ' hidden' ?>>
        <?php
        // A failed server-file attempt, or a Discover link, reopens its own tab.
        $nativeSource = ($_POST['import_source'] ?? null) === 'server'
            || (!isset($_POST['import_source']) && ($nativeExportPath !== '' || $nativeDiscovered !== null))
            ? 'server'
            : 'upload';
        ?>
        <section class="lp-admin__panel">
            <h2>Lumora Press Import</h2>

            <?php if ($nativeImportError !== null): ?>
                <div class="lp-alert lp-alert--error"><?= esc_html($nativeImportError) ?></div>
            <?php endif; ?>

            <p class="lp-field__hint">
                Imports a <code>.zip</code> file made by Maintenance &rsaquo; Export on
                another Lumora Press site (or this one): posts, pages, categories,
                tags, comments, media, users, menus, and widgets — whatever the file
                contains. Nothing already on this site is removed.
            </p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" enctype="multipart/form-data">
                <?= Csrf::field('import_lumora_press_export') ?>
                <input type="hidden" name="form" value="import_lumora_press_export">
                <input type="hidden" id="lumora-import-source" name="import_source" value="<?= esc_attr($nativeSource) ?>">

                <div class="lp-import-step">
                <h3 class="lp-import-step__title"><span class="lp-import-step__number" aria-hidden="true">1</span>Choose the export file</h3>

                <div class="lp-tabs" data-lp-tabs-input="lumora-import-source">
                    <div class="lp-tabs__list" role="tablist" aria-label="Where the export file is">
                        <button type="button" class="lp-tabs__tab" id="lp-tab-import-upload" role="tab" data-lp-tab-value="upload" aria-selected="<?= $nativeSource === 'upload' ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-import-upload" tabindex="<?= $nativeSource === 'upload' ? '0' : '-1' ?>">Upload a File</button>
                        <button type="button" class="lp-tabs__tab" id="lp-tab-import-server" role="tab" data-lp-tab-value="server" aria-selected="<?= $nativeSource === 'server' ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-import-server" tabindex="<?= $nativeSource === 'server' ? '0' : '-1' ?>">File on This Server</button>
                    </div>

                    <div class="lp-tabs__panel" id="lp-tabpanel-import-upload" role="tabpanel" aria-labelledby="lp-tab-import-upload"<?= $nativeSource === 'upload' ? '' : ' hidden' ?>>
                        <p class="lp-field">
                            <label for="lumora-import-file">Export file</label>
                            <input type="file" id="lumora-import-file" name="export_file" accept=".zip,application/zip">
                            <span class="lp-field__hint">
                                This server accepts uploads up to <?= esc_html((string) ini_get('upload_max_filesize')) ?>.
                                An export that includes uploaded files is usually larger than that — copy it
                                onto the server instead and use &ldquo;File on This Server&rdquo;.
                            </span>
                        </p>
                    </div>

                    <div class="lp-tabs__panel" id="lp-tabpanel-import-server" role="tabpanel" aria-labelledby="lp-tab-import-server"<?= $nativeSource === 'server' ? '' : ' hidden' ?>>
                        <div id="lumora-import-server">
                            <p class="lp-field__hint">
                                For an export too large to upload: copy the <code>.zip</code> onto this server
                                (by FTP, SFTP, or your host's file manager), then enter where it is. The file
                                is left where it is afterward.
                            </p>

                            <?php if ($nativeDiscovered === null): ?>
                                <p><a class="lp-button" href="<?= esc_url($nativeImportUrl(['discover_exports' => '1'], 'lumora-import-server')) ?>">Discover Export Files</a></p>
                            <?php elseif ($nativeDiscovered === []): ?>
                                <p class="lp-admin__widget-placeholder">
                                    No Lumora Press export files were found in <code><?= esc_html(dirname(LUMORA_ROOT)) ?></code>,
                                    this site's own folder, or the folders next to it. Enter the file's location by hand below.
                                </p>
                            <?php else: ?>
                                <ul class="lp-import-scan__list">
                                    <?php foreach ($nativeDiscovered as $candidate): ?>
                                        <li><a class="lp-button lp-button--link" href="<?= esc_url($nativeImportUrl(['export_path' => $candidate], 'lumora-import-server')) ?>"><?= esc_html($candidate) ?></a> (<?= esc_html(number_format((int) filesize($candidate) / 1048576, 1)) ?> MB)</li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <p class="lp-field">
                                <label for="lumora-import-path">Export file location (server filesystem)</label>
                                <input type="text" id="lumora-import-path" name="export_path" value="<?= esc_attr($nativeExportPath) ?>" placeholder="<?= esc_attr(dirname(LUMORA_ROOT)) ?>/lumora-press-export-my-site.zip">
                                <span class="lp-field__hint">The full path to the <code>.zip</code> file, readable by the web server.</span>
                            </p>
                        </div>
                    </div>
                </div>

                </div>

                <div class="lp-import-step">
                <h3 class="lp-import-step__title" id="lumora-import-uploads"><span class="lp-import-step__number" aria-hidden="true">2</span>Uploaded files kept separately (optional)</h3>

                <p class="lp-field__hint">
                    Building an export that includes every uploaded file can be too much for
                    some hosts. Instead, export with &ldquo;Include uploaded files&rdquo;
                    unchecked, copy the old site's <code>content/uploads</code> folder onto this
                    server (by FTP, SFTP, or your host's file manager — or use it where it is,
                    if both sites are on this server), and enter that folder here. Each media
                    file is then read from it, the same way the WordPress importer reads a
                    WordPress site's uploads folder. Leave blank for an export that already
                    includes its files.
                </p>

                <?php if ($nativeUploadsDiscovered === null): ?>
                    <p><a class="lp-button" href="<?= esc_url($nativeImportUrl(['discover_upload_folders' => '1'], 'lumora-import-uploads')) ?>">Discover Uploads Folders</a></p>
                <?php elseif ($nativeUploadsDiscovered === []): ?>
                    <p class="lp-admin__widget-placeholder">
                        No other site next to this one (in <code><?= esc_html(dirname(LUMORA_ROOT)) ?></code>)
                        has a <code>content/uploads</code> folder. Enter the folder's location by hand below.
                    </p>
                <?php else: ?>
                    <ul class="lp-import-scan__list">
                        <?php foreach ($nativeUploadsDiscovered as $candidate): ?>
                            <li><a class="lp-button lp-button--link" href="<?= esc_url($nativeImportUrl(['uploads_folder' => $candidate], 'lumora-import-uploads')) ?>"><?= esc_html($candidate) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="lp-field">
                    <label for="lumora-import-uploads-folder">Uploaded files folder (server filesystem)</label>
                    <input type="text" id="lumora-import-uploads-folder" name="uploads_folder" value="<?= esc_attr($nativeUploadsFolder) ?>" placeholder="/path/to/other-site/content/uploads">
                </p>

                </div>

                <div class="lp-import-step">
                <h3 class="lp-import-step__title"><span class="lp-import-step__number" aria-hidden="true">3</span>Import</h3>

                <p class="lp-field">
                    <label for="lumora-import-existing">When content was already imported from the same site</label>
                    <select id="lumora-import-existing" name="existing_content">
                        <option value="overwrite">Update it with the file's version</option>
                        <option value="skip"<?= ($_POST['existing_content'] ?? null) === 'skip' ? ' selected' : '' ?>>Leave it as it is, only add what's new</option>
                    </select>
                    <span class="lp-field__hint">
                        Importing the same site's export again never creates duplicates: each
                        post, page, comment, media item, and category remembers where it came
                        from. Menus and widgets imported before are always left as they are.
                    </span>
                </p>

                <div class="lp-alert lp-alert--warning">
                    A large export can take a while. Keep this tab open until the import finishes.
                </div>

                <button type="submit" class="lp-button lp-button--primary">Import</button>
                </div>
            </form>
        </section>

        <?php if (!$wordPressImporterActive): ?>
            <section class="lp-admin__panel">
                <h2>WordPress</h2>
                <p class="lp-field__hint">To bring content across from an existing WordPress site, activate the WordPress Importer plugin under Plugins.</p>
            </section>
        <?php endif; ?>
    </div>

<?php if ($wordPressImporterActive): ?>
    <div class="lp-tabs__panel" id="lp-tabpanel-wordpress" role="tabpanel" aria-labelledby="lp-tab-wordpress"<?= $activeImportTab === 'wordpress' ? '' : ' hidden' ?>>
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

    <details class="lp-admin__panel lp-import-about">
        <summary><strong>About the WordPress Importer</strong> — what it imports and what it needs</summary>

        <h3>Supported WordPress versions</h3>

        <p class="lp-field__hint">
            Supports any source running WordPress 3.5 or later, including
            the current WordPress release — there's no version to select.
            Tested with WordPress 7.1.
        </p>

        <h3>Import information</h3>

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
    </details>

    <section class="lp-admin__panel">
        <h2>Import from WordPress</h2>

        <?php
        // Shared by the Resume, Test Connection, and Import forms — each
        // still POSTs independently with its own CSRF token, this just
        // avoids three physical copies of the markup. Both field sets
        // render unconditionally; the radio alone decides which one the
        // server reads on submit, so neither needs `required`.

        $renderConnectionFields = static function (string $idPrefix) use ($formValues): void {
            ?>

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

            <details class="lp-import-help">
                <summary>Which one should I use?</summary>
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
            </details>

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
            <div class="lp-import-step lp-import-step--summary">
            <h3 class="lp-import-step__title">Previous import</h3>
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
            </div>
        <?php endif; ?>

        <?php if ($inProgress !== null): ?>
            <div class="lp-import-step lp-import-step--resume">
            <h3 class="lp-import-step__title">Resume the unfinished import</h3>
            <?php if ($importPaused): ?>
            <div class="lp-alert lp-alert--info">
                The import is part-way through (<?= count($inProgress['completedStages']) ?> of <?= count($inProgress['plannedStages']) ?> stage(s) finished).
                Choose Resume Import to carry on from where it stopped — the source connection details
                are filled in below<?= isset($inProgress['connection']['db_password']) || ($inProgress['connection']['source_type'] ?? '') === 'wxr' ? '' : ', but the database password has to be entered again' ?>; the content
                types originally selected are used again.
            </div>
            <?php else: ?>
            <div class="lp-alert lp-alert--warning">
                A previous import was interrupted after
                <?= count($inProgress['completedStages']) ?> of <?= count($inProgress['plannedStages']) ?> stage(s)<?= $inProgress['completedStages'] === [] ? '' : ' (' . esc_html(implode(', ', array_map([WordPressImportService::class, 'stageLabel'], $inProgress['completedStages']))) . ' completed so far)' ?>.
                The source connection details are filled in below from that
                import<?= isset($inProgress['connection']['db_password']) || ($inProgress['connection']['source_type'] ?? '') === 'wxr' ? '' : ' — enter the database password again' ?>.
                The content types originally selected are used again
                automatically; they can't be changed for a resumed import.
            </div>
            <?php endif; ?>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" data-lp-import-form data-lp-import-target="lp-import-progress-resume">
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
                <p class="lp-field__hint" data-lp-import-detail hidden></p>

                <button type="submit" class="lp-button lp-button--primary">Resume Import</button>
            </form>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" class="lp-admin__inline-form" data-lp-confirm="Remove everything this interrupted import created so far? This cannot be undone.">
                <?= Csrf::field('remove_wordpress_import') ?>
                <input type="hidden" name="form" value="remove_wordpress_import">
                <button type="submit" class="lp-button lp-button--danger">Discard This Import</button>
            </form>
            </div>
        <?php else: ?>
            <?php
            // Separate forms, each with its own CSRF action name, rather
            // than one form with buttons sharing a token — a shared name
            // across buttons silently breaks all but the last rendered.
            ?>
            <div class="lp-import-step lp-import-step--optional">
            <h3 class="lp-import-step__title">Optional shortcut: fill in from wp-config.php</h3>

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

            <?php
            // Finds a *candidate path* to type above — distinct from the
            // "Detect from wp-config.php" button above, which parses a
            // path already known to be correct.
            $wpConfigDiscovered = isset($_GET['discover_wp_config'])
                ? $discoverWordPressDirectories('wp-config.php')
                : null;
            ?>
            <?php if ($wpConfigDiscovered === null): ?>
                <p class="lp-field__hint">Lumora Press is installed in <code><?= esc_html(rtrim(LUMORA_ROOT, '/')) ?></code> — Discover also looks there, in the folder above it, and in the folders directly inside either.</p>
                <p><a class="lp-button" href="<?= esc_url(admin_url('maintenance/import')) ?>?discover_wp_config=1#wp-import-wp-config-path">Discover wp-config.php</a></p>
            <?php elseif ($wpConfigDiscovered === []): ?>
                <p class="lp-admin__widget-placeholder">
                    No <code>wp-config.php</code> was found in this install's own folder, the folder above it,
                    or any folder directly inside either — either the WordPress site isn't on this same
                    server, or this host's permissions don't allow reading that location.
                </p>
            <?php else: ?>
                <?php $discoveredWpConfigToken = Csrf::token('detect_wp_config_discovered'); ?>
                <ul class="lp-import-scan__list">
                    <?php foreach ($wpConfigDiscovered as $candidate): ?>
                        <li>
                            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" class="lp-admin__inline-form">
                                <input type="hidden" name="csrf_token" value="<?= esc_attr($discoveredWpConfigToken) ?>">
                                <input type="hidden" name="form" value="detect_wp_config_discovered">
                                <input type="hidden" name="wp_config_path" value="<?= esc_attr($candidate . '/wp-config.php') ?>">
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
                                <button type="submit" class="lp-button lp-button--link"><?= esc_html($candidate . '/wp-config.php') ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            </div>

            <?php
            // One form for the whole screen, so the source details only appear once.
            // Test Connection and Import are the two submit buttons: each one's
            // name/value picks the handler, and both share one CSRF action.
            ?>
            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" id="wp-import-form" data-lp-import-form data-lp-import-target="lp-import-progress">
                <?= Csrf::field('start_wordpress_import') ?>

                <div class="lp-import-step">
                <h3 class="lp-import-step__title"><span class="lp-import-step__number" aria-hidden="true">1</span>Where the content comes from</h3>
                <?php $renderConnectionFields('wp-import'); ?>
                </div>

                <div class="lp-import-step">
                <h3 class="lp-import-step__title"><span class="lp-import-step__number" aria-hidden="true">2</span>Source files and connection check</h3>

                <?php
                // Same "find a candidate, don't parse anything" shape as
                // the wp-config.php discovery above, but simpler: uploads_
                // path/gallery_path are plain values, not something to
                // detect from file content, so a bookmarkable GET link is
                // enough — no form/CSRF needed. Filling both together in
                // one click, since a discovered WordPress root is the
                // natural anchor for both wp-content/uploads and its
                // sibling wp-content/gallery.
                $uploadsDiscovered = isset($_GET['discover_uploads'])
                    ? $discoverWordPressDirectories('wp-content/uploads')
                    : null;
                ?>
                <?php if ($uploadsDiscovered === null): ?>
                    <p class="lp-field__hint">Lumora Press is installed in <code><?= esc_html(rtrim(LUMORA_ROOT, '/')) ?></code> — Discover also looks there, in the folder above it, and in the folders directly inside either.</p>
                    <p><a class="lp-button" href="<?= esc_url(admin_url('maintenance/import')) ?>?discover_uploads=1#wp-import-uploads-path">Discover Source Files</a></p>
                <?php elseif ($uploadsDiscovered === []): ?>
                    <p class="lp-admin__widget-placeholder">
                        No <code>wp-content/uploads</code> folder was found in this install's own folder, the folder
                        above it, or any folder directly inside either.
                    </p>
                <?php else: ?>
                    <ul class="lp-import-scan__list">
                        <?php foreach ($uploadsDiscovered as $candidate): ?>
                            <?php
                            $candidateUploads = $candidate . '/wp-content/uploads';
                            $candidateGallery = $candidate . '/wp-content/gallery';
                            $discoverUrl = admin_url('maintenance/import') . '?uploads_path=' . rawurlencode($candidateUploads)
                                . (is_dir($candidateGallery) ? '&gallery_path=' . rawurlencode($candidateGallery) : '')
                                . '#wp-import-uploads-path';
                            ?>
                            <li><a class="lp-button lp-button--link" href="<?= esc_url($discoverUrl) ?>"><?= esc_html($candidateUploads) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="lp-field">
                    <label for="wp-import-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="wp-import-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
                    <span class="lp-field__hint">An absolute path this server's PHP process can read — the source site's <code>wp-content/uploads</code> folder.</span>
                </p>

                <p class="lp-field">
                    <label for="wp-import-gallery-path">Gallery folder path (server filesystem)</label>
                    <input type="text" id="wp-import-gallery-path" name="gallery_path" value="<?= esc_attr($formValues['gallery_path']) ?>" placeholder="/path/to/wp-content/gallery">
                    <span class="lp-field__hint">
                        Only needed for "NextGEN Gallery" below — a local filesystem copy of the source
                        site's <code>wp-content/gallery</code> folder (a sibling of <code>wp-content/uploads</code>
                        above, not a subfolder of it). Leave blank if the source site never ran NextGEN Gallery.
                    </span>
                </p>

                <?php
                // Carried forward so submitting doesn't blank out the wp-config.php
                // path field, which lives only on the Detect form.
                ?>
                <input type="hidden" name="wp_config_path" value="<?= esc_attr($formValues['wp_config_path']) ?>">

                <button type="submit" name="form" value="test_wordpress_connection" class="lp-button lp-button--secondary">Test Connection</button>
                <span class="lp-field__hint">Checks the source and the uploads folder without importing anything.</span>
                </div>

            <?php
            // Renders once via this closure, inside the single Import
            // form below. Dry run and a real Start/Resume Import share
            // one form/CSRF action; a "Dry run" checkbox decides which
            // the server does. This closure avoids duplicating the
            // id-prefixing logic the smaller Resume form above also needs.
            $renderSharedImportFields = static function (string $idPrefix) use ($formValues, $renderConnectionFields): void {
                ?>


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

                <div class="lp-import-step">
                <h3 class="lp-import-step__title"><span class="lp-import-step__number" aria-hidden="true">3</span>What to import</h3>
            <?php $renderSharedImportFields('wp-import-run'); ?>
                </div>

                <div class="lp-import-step">
                <h3 class="lp-import-step__title"><span class="lp-import-step__number" aria-hidden="true">4</span>Run the import</h3>

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
                    It runs in short steps, so a slow host's request timeout
                    won't cut it off — keep this tab open while it works.
                    If it's interrupted anyway, revisiting this page offers
                    to resume from where it left off. (Doesn't apply to a
                    dry run above, which finishes immediately.)
                </div>

                <ul id="lp-import-progress" class="lp-update-progress" hidden></ul>
                <p class="lp-field__hint" data-lp-import-detail hidden></p>

                <button type="submit" name="form" value="start_wordpress_import" class="lp-button lp-button--primary">Import</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
    </div>
<?php endif; ?>
</div>
