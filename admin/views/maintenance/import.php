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
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\WordPressImporter\ImportProgress;
use LumoraPress\Plugins\WordPressImporter\WordPressImportService;
use LumoraPress\Plugins\WordPressImporter\WordPressSource;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * Progress polling (Import Options — detailed progress indicator):
 * a separate, lightweight GET the page's own JS (admin/assets/js/
 * update-progress.js, already built for Maintenance > Updates and
 * generic enough to reuse as-is here — see that file's own docblock)
 * hits every second or so while a Start/Resume Import POST below is
 * still running on another connection. Handled first, before any
 * session-write work or view rendering, and intentionally never checks
 * CSRF — this only ever reads ImportProgress's on-disk state, so there
 * is nothing here for CSRF to protect. Still requires the same admin
 * session/manage_options capability every other branch of this page
 * does, since that gate already ran in admin/index.php before this file
 * was even required.
 */
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
$sitePreview = null;
$dryRunCounts = null;
$summary = null;
$warnings = [];

/*
 * Connection fields are never persisted between requests — re-typed (or
 * resubmitted via the hidden fields below) on every Test Connection /
 * Preview / Start Import click, the same "credentials aren't stored
 * anywhere" posture this plugin's implementation plan calls for.
 */
$formValues = [
    'db_host' => is_string($_POST['db_host'] ?? null) ? $_POST['db_host'] : 'localhost',
    'db_port' => is_string($_POST['db_port'] ?? null) ? $_POST['db_port'] : '3306',
    'db_name' => is_string($_POST['db_name'] ?? null) ? $_POST['db_name'] : '',
    'db_user' => is_string($_POST['db_user'] ?? null) ? $_POST['db_user'] : '',
    'db_password' => is_string($_POST['db_password'] ?? null) ? $_POST['db_password'] : '',
    'db_prefix' => is_string($_POST['db_prefix'] ?? null) ? $_POST['db_prefix'] : 'wp_',
    'uploads_path' => is_string($_POST['uploads_path'] ?? null) ? $_POST['uploads_path'] : '',
];

if ($wordPressImporterActive) {
    /*
     * WordPressImportService's class is guaranteed to already be loaded —
     * PluginManager::loadActive() required
     * content/plugins/wordpress-importer/wordpress-importer.php earlier
     * this same request, in include/bootstrap.php.
     *
     * $downloadsActive gates the Downloads plugin the same way — null
     * when it's inactive, so WordPressImportService falls back to its
     * own default (every download still imports as a plain Media item/
     * Redirect, just without a `downloads` table row on top).
     */
    $downloadsService = $downloadsActive ? new DownloadService(
        $kernel->database,
        (string) $kernel->config->get('table_prefix', 'lp_'),
        $kernel->media,
        $kernel->redirects,
        $kernel->folders,
    ) : null;

    $buildImportService = static function () use ($kernel, $formValues, $downloadsService): WordPressImportService {
        $source = WordPressSource::connect(
            host: $formValues['db_host'],
            database: $formValues['db_name'],
            username: $formValues['db_user'],
            password: $formValues['db_password'],
            tablePrefix: $formValues['db_prefix'],
            port: (int) $formValues['db_port'] > 0 ? (int) $formValues['db_port'] : 3306,
        );

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
        );
    };

    /*
     * removeAll()/lastImportSummary()/inProgressBatch() are pure
     * ContentImportRegistry lookups that never touch the source
     * WordPress database — this builds the service with source: null
     * (see WordPressImportService's own constructor docblock) instead of
     * opening — and requiring the admin to re-enter credentials for —
     * a connection those three methods never use.
     */
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
    );

    /**
     * @return array{site_settings: bool, users: bool, categories: bool, media: bool, downloads: bool, pages: bool, posts: bool, comments: bool, menus: bool, widgets: bool, stage_delay_ms: int}
     */
    $optionsFromPost = static function (): array {
        return [
            'site_settings' => isset($_POST['include_site_settings']),
            'users' => isset($_POST['include_users']),
            'categories' => isset($_POST['include_categories']),
            'media' => isset($_POST['include_media']),
            'downloads' => isset($_POST['include_downloads']),
            'pages' => isset($_POST['include_pages']),
            'posts' => isset($_POST['include_posts']),
            'comments' => isset($_POST['include_comments']),
            'menus' => isset($_POST['include_menus']),
            'widgets' => isset($_POST['include_widgets']),
            'stage_delay_ms' => max(0, (int) ($_POST['stage_delay_ms'] ?? 0)),
        ];
    };

    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'test_wordpress_connection' && Csrf::verify('test_wordpress_connection', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        try {
            $source = WordPressSource::connect(
                host: $formValues['db_host'],
                database: $formValues['db_name'],
                username: $formValues['db_user'],
                password: $formValues['db_password'],
                tablePrefix: $formValues['db_prefix'],
                port: (int) $formValues['db_port'] > 0 ? (int) $formValues['db_port'] : 3306,
            );

            if (!$source->testConnection()) {
                $testResult = ['ok' => false, 'message' => "Connected, but no `{$formValues['db_prefix']}posts` table was found. Check the table prefix."];
            } elseif (!is_dir($formValues['uploads_path'])) {
                $testResult = ['ok' => false, 'message' => 'Connected to the database, but the uploads folder path does not exist or is not readable by the web server.'];
            } else {
                $wpOptions = $source->siteOptions();
                $siteName = $wpOptions['blogname'] ?? null;
                $testResult = [
                    'ok' => true,
                    'message' => 'Connected successfully, and the uploads folder is readable.'
                        . ($siteName !== null && $siteName !== '' ? " Source site: \"{$siteName}\"." : ''),
                ];

                // A preview only — nothing here is written anywhere. See
                // "Site settings" below for the opt-in checkbox that
                // actually applies these on Start Import.
                $sitePreview = [
                    'Site title' => html_entity_decode($wpOptions['blogname'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'Tagline' => html_entity_decode($wpOptions['blogdescription'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'Timezone' => ($wpOptions['timezone_string'] ?? '') !== '' ? $wpOptions['timezone_string'] : (($wpOptions['gmt_offset'] ?? '') !== '' ? 'UTC' . ($wpOptions['gmt_offset'][0] === '-' ? '' : '+') . $wpOptions['gmt_offset'] : ''),
                    'Date format' => $wpOptions['date_format'] ?? '',
                    'Time format' => $wpOptions['time_format'] ?? '',
                    'Permalink structure' => $wpOptions['permalink_structure'] ?? '',
                ];
            }
        } catch (\Throwable $exception) {
            $testResult = ['ok' => false, 'message' => 'Could not connect: ' . $exception->getMessage()];
        }
    }

    if ($form === 'preview_wordpress_import' && Csrf::verify('preview_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        try {
            $dryRunCounts = $buildImportService()->dryRunCounts($optionsFromPost());
        } catch (\Throwable $exception) {
            $importError = 'Could not preview: ' . $exception->getMessage();
        }
    }

    if ($form === 'start_wordpress_import' && Csrf::verify('start_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        // A real site's content can take a while to walk row by row —
        // this runs as one long synchronous request (matching
        // DummyContentGenerator's own precedent; see this feature's
        // implementation plan for why no background-job/polling
        // infrastructure exists in this codebase yet) rather than
        // timing out at PHP's default execution limit. Each stage's own
        // progress is still persisted to the database as it completes
        // (WordPressImportService::runNextStage()), so an interruption
        // partway through — a host's own hard execution limit despite
        // this, a lost connection — leaves a resumable batch behind
        // rather than losing all progress.
        set_time_limit(0);

        $importProgress = new ImportProgress(LUMORA_ROOT);

        // See admin/views/maintenance/updates.php's identical pattern
        // (and UpdateProgress's own docblock) for why this releases the
        // session lock before a long-running operation: PHP's default
        // session handler locks the session file for the whole request,
        // so without this, the polling GET above would simply queue
        // behind this request and never observe anything until the
        // import was already done.
        session_write_close();

        try {
            $service = $buildImportService();
            $submittedOptions = $optionsFromPost();

            // A resumable batch always continues with its own original
            // options (see startOrResume()'s own docblock) — reflected
            // here too, so the progress bar's declared stage list
            // matches what will actually run, not what was just
            // resubmitted on the (possibly stripped-down, selection-less)
            // Resume form.
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

            // The service instance (and its in-memory warnings() log)
            // doesn't survive the redirect below — stashed in the
            // session for one read, the same "flash message" technique
            // as Csrf's own one-time token, since this screen has no
            // generic flash-message mechanism to reuse.
            session_start();
            $_SESSION['lp_wordpress_import_warnings'] = $result['warnings'];

            header('Location: ' . admin_url('maintenance/import') . '?imported=1');
            exit;
        } catch (\Throwable $exception) {
            $importProgress->complete();
            $importError = $exception->getMessage();

            // See the 'start_wordpress_import' branch's own
            // session_write_close() above for why this is needed before
            // the rest of the page renders — only reached on failure
            // here, since success already exited via the redirect above.
            session_start();
        }
    }

    if ($form === 'remove_wordpress_import' && Csrf::verify('remove_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        // No source connection is needed to remove already-imported
        // content — every id to delete lives in content_import_records,
        // not the source database — so this constructs the service with
        // an unconnected/unused WordPressSource rather than requiring
        // the admin to re-enter DB credentials just to roll back. Works
        // the same whether the batch being removed finished normally or
        // was left in-progress by an interruption — every id it created
        // so far is already recorded either way.
        $buildRegistryOnlyService()->removeAll();

        header('Location: ' . admin_url('maintenance/import') . '?removed=1');
        exit;
    }

    $imported = isset($_GET['imported']);
    $removed = isset($_GET['removed']);

    if ($imported && isset($_SESSION['lp_wordpress_import_warnings'])) {
        $warnings = $_SESSION['lp_wordpress_import_warnings'];
        unset($_SESSION['lp_wordpress_import_warnings']);
    }

    $registryOnlyService = $buildRegistryOnlyService();
    $inProgress = $registryOnlyService->inProgressBatch();
    $summary = $inProgress === null ? $registryOnlyService->lastImportSummary() : null;
}
?>
<h1 class="lp-admin__title">Import</h1>

<?php if ($wordPressImporterActive): ?>
    <?php if ($imported): ?>
        <div class="lp-alert lp-alert--success">WordPress import complete.</div>

        <?php if ($warnings !== []): ?>
            <div class="lp-alert lp-alert--warning">
                <strong><?= count($warnings) ?> item<?= count($warnings) === 1 ? '' : 's' ?> skipped or had a problem:</strong>
                <ul>
                    <?php foreach ($warnings as $warning): ?>
                        <li><?= esc_html($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($removed): ?>
        <div class="lp-alert lp-alert--success">All imported content was removed.</div>
    <?php endif; ?>

    <?php if ($importError !== null): ?>
        <div class="lp-alert lp-alert--error"><?= esc_html($importError) ?></div>
    <?php endif; ?>

    <?php if ($testResult !== null): ?>
        <div class="lp-alert <?= $testResult['ok'] ? 'lp-alert--success' : 'lp-alert--error' ?>"><?= esc_html($testResult['message']) ?></div>
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
            menus, and classic widgets from an existing WordPress site via
            a direct database connection plus a local copy of its
            <code>wp-content/uploads</code> folder. The database can be the WordPress site's own live
            server (point the host field at it directly) or a local copy
            you've restored from a backup — either way, the uploads folder
            must already be readable on this server's local filesystem; it
            is never fetched remotely. If the source site has Simple
            Download Monitor installed, its downloads (including their
            real download counts) are imported too — organized into
            matching Media Manager Folders when the file is hosted
            locally, or as a Redirect (with a working, seeded hit
            counter) when it only links to an external URL. Any page
            still using <code>[sdm_show_dl_from_category]</code>
            automatically renders a real list of those downloads after
            import — no manual page editing needed. Site title, tagline,
            timezone, date/time format, and permalink structure can
            optionally be imported too (off by default — see "Site
            settings" below).
        </p>

        <?php if ($summary !== null): ?>
            <?php
            $pluralLabels = [
                'post' => 'posts', 'page' => 'pages', 'user' => 'users',
                'category' => 'categories', 'tag' => 'tags', 'comment' => 'comments', 'media' => 'media',
                'nav_menu' => 'menus', 'widget_instance' => 'widgets',
            ];
            // "*_snap" entries are internal pre-import option/progress
            // snapshots (see WordPressImportService::removeAll()'s
            // docblock) — not real imported content, so they're excluded
            // from this user-facing summary line entirely.
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

            <p class="lp-field__hint">Remove the existing import before importing again.</p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" data-lp-confirm="Remove everything this import created? This cannot be undone.">
                <?= Csrf::field('remove_wordpress_import') ?>
                <input type="hidden" name="form" value="remove_wordpress_import">
                <button type="submit" class="lp-button lp-button--danger">Remove All Imported Content</button>
            </form>
        <?php elseif ($inProgress !== null): ?>
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

                <p class="lp-field">
                    <label for="wp-import-resume-db-host">Database host</label>
                    <input type="text" id="wp-import-resume-db-host" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-resume-db-port">Database port</label>
                    <input type="text" id="wp-import-resume-db-port" name="db_port" value="<?= esc_attr($formValues['db_port']) ?>">
                </p>
                <p class="lp-field">
                    <label for="wp-import-resume-db-name">Database name</label>
                    <input type="text" id="wp-import-resume-db-name" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-resume-db-user">Database username</label>
                    <input type="text" id="wp-import-resume-db-user" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-resume-db-password">Database password</label>
                    <input type="password" id="wp-import-resume-db-password" name="db_password" value="<?= esc_attr($formValues['db_password']) ?>">
                </p>
                <p class="lp-field">
                    <label for="wp-import-resume-db-prefix">Table prefix</label>
                    <input type="text" id="wp-import-resume-db-prefix" name="db_prefix" value="<?= esc_attr($formValues['db_prefix']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-resume-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="wp-import-resume-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
                </p>

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
            // Separate forms, each with its own CSRF action name and its
            // own copy of the connection fields, rather than one form
            // with multiple submit buttons sharing a token — see
            // CommentService's own docblock (and this project's SESSION.md
            // handoff notes) on why a shared CSRF action name across
            // multiple buttons on one page silently breaks every button
            // but the last one rendered.
            ?>
            <h3>Source database</h3>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" id="wp-import-test-form">
                <?= Csrf::field('test_wordpress_connection') ?>
                <input type="hidden" name="form" value="test_wordpress_connection">

                <p class="lp-field">
                    <label for="wp-import-db-host">Database host</label>
                    <input type="text" id="wp-import-db-host" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-db-port">Database port</label>
                    <input type="text" id="wp-import-db-port" name="db_port" value="<?= esc_attr($formValues['db_port']) ?>">
                </p>
                <p class="lp-field">
                    <label for="wp-import-db-name">Database name</label>
                    <input type="text" id="wp-import-db-name" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-db-user">Database username</label>
                    <input type="text" id="wp-import-db-user" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-db-password">Database password</label>
                    <input type="password" id="wp-import-db-password" name="db_password" value="<?= esc_attr($formValues['db_password']) ?>">
                </p>
                <p class="lp-field">
                    <label for="wp-import-db-prefix">Table prefix</label>
                    <input type="text" id="wp-import-db-prefix" name="db_prefix" value="<?= esc_attr($formValues['db_prefix']) ?>" required>
                </p>

                <h3>Source files</h3>

                <p class="lp-field">
                    <label for="wp-import-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="wp-import-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
                    <span class="lp-field__hint">An absolute path this server's PHP process can read — the source site's <code>wp-content/uploads</code> folder.</span>
                </p>

                <button type="submit" class="lp-button lp-button--secondary">Test Connection</button>
            </form>

            <?php
            /*
             * The "Content to import"/"Site settings"/"Import options"
             * fields render once via this closure and are echoed inside
             * both the Preview and Start Import forms below — each form
             * still POSTs independently with its own CSRF token and its
             * own copy of every field (see this section's own top-level
             * comment on why), this just avoids maintaining three
             * physically separate copies of the same markup in this file.
             */
            $renderSharedImportFields = static function (string $idPrefix) use ($formValues): void {
                ?>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-db-host">Database host</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-db-host" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-db-port">Database port</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-db-port" name="db_port" value="<?= esc_attr($formValues['db_port']) ?>">
                </p>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-db-name">Database name</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-db-name" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-db-user">Database username</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-db-user" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-db-password">Database password</label>
                    <input type="password" id="<?= esc_attr($idPrefix) ?>-db-password" name="db_password" value="<?= esc_attr($formValues['db_password']) ?>">
                </p>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-db-prefix">Table prefix</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-db-prefix" name="db_prefix" value="<?= esc_attr($formValues['db_prefix']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="<?= esc_attr($idPrefix) ?>-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="<?= esc_attr($idPrefix) ?>-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
                </p>

                <h4>Site settings</h4>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_site_settings" value="1">
                        Site title, tagline, timezone, date/time format, and permalink structure
                    </label>
                    <span class="lp-field__hint">
                        Overwrites this site's own Settings &rsaquo; General/Permalinks values with the
                        source site's. Off by default — leave unchecked to keep this site's existing settings.
                        A source permalink structure using a tag Lumora Press doesn't support (e.g.
                        <code>%post_id%</code>) is skipped and noted in the warnings below rather than applied.
                        Homepage, Reading, Discussion, Media, and Privacy settings have no Lumora Press
                        equivalent yet and are never imported.
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
                        Widgets (only types with a Lumora Press equivalent; the rest are skipped and listed in the warnings below)
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
                <?php
            };
            ?>

            <h3>Preview</h3>

            <p class="lp-field__hint">Re-enter the same connection details above — they aren't carried over from the Test Connection form. Reads the source database only; nothing is imported.</p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>">
                <?= Csrf::field('preview_wordpress_import') ?>
                <input type="hidden" name="form" value="preview_wordpress_import">
                <?php $renderSharedImportFields('wp-import-preview'); ?>
                <button type="submit" class="lp-button lp-button--secondary">Preview (Dry Run)</button>
            </form>

            <h3>Start import</h3>

            <p class="lp-field__hint">Re-enter the same connection details above again — they aren't carried over from the Preview form either.</p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>" data-lp-update-progress-form data-lp-update-progress-url="<?= esc_url(admin_url('maintenance/import')) ?>?ajax=progress" data-lp-update-progress-target="lp-import-progress-start">
                <?= Csrf::field('start_wordpress_import') ?>
                <input type="hidden" name="form" value="start_wordpress_import">
                <?php $renderSharedImportFields('wp-import-start'); ?>

                <div class="lp-alert lp-alert--warning">
                    A real site's content can take a long time to import.
                    This runs as one request — do not navigate away or
                    close the tab while it's in progress. If it's interrupted
                    anyway, revisiting this page offers to resume from where
                    it left off.
                </div>

                <ul id="lp-import-progress-start" class="lp-update-progress" hidden></ul>

                <button type="submit" class="lp-button lp-button--primary">Start Import</button>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="lp-admin__panel">
        <p class="lp-field__hint">Activate the WordPress Importer plugin to migrate content from an existing WordPress site.</p>
    </section>
<?php endif; ?>
