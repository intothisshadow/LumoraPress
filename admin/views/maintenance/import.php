<?php

/**
 * The admin Maintenance > Import screen — the WordPress Importer plugin's connection form, run, and rollback flow (LPP-004).
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
use LumoraPress\Plugins\WordPressImporter\WordPressImportService;
use LumoraPress\Plugins\WordPressImporter\WordPressSource;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$importError = null;
$testResult = null;
$summary = null;
$warnings = [];

/*
 * Connection fields are never persisted between requests — re-typed (or
 * resubmitted via the hidden fields below) on every Test Connection /
 * Start Import click, the same "credentials aren't stored anywhere"
 * posture this plugin's implementation plan calls for.
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
            registry: $kernel->contentImportRegistry,
            sourceUploadsPath: rtrim($formValues['uploads_path'], '/'),
            downloads: $downloadsService,
        );
    };

    /*
     * removeAll()/lastImportSummary() are pure ContentImportRegistry
     * lookups that never touch the source WordPress database — this
     * builds the service with source: null (see
     * WordPressImportService's own constructor docblock) instead of
     * opening — and requiring the admin to re-enter credentials for —
     * a connection those two methods never use.
     */
    $buildRegistryOnlyService = static fn (): WordPressImportService => new WordPressImportService(
        source: null,
        userImporter: $kernel->userImporter,
        postImporter: $kernel->postImporter,
        pageImporter: $kernel->pageImporter,
        mediaImporter: $kernel->mediaImporter,
        commentImporter: $kernel->commentImporter,
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
        registry: $kernel->contentImportRegistry,
        sourceUploadsPath: '',
        downloads: $downloadsService,
    );

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
                $siteName = $source->siteOptions()['blogname'] ?? null;
                $testResult = [
                    'ok' => true,
                    'message' => 'Connected successfully, and the uploads folder is readable.'
                        . ($siteName !== null && $siteName !== '' ? " Source site: \"{$siteName}\"." : ''),
                ];
            }
        } catch (\Throwable $exception) {
            $testResult = ['ok' => false, 'message' => 'Could not connect: ' . $exception->getMessage()];
        }
    }

    if ($form === 'start_wordpress_import' && Csrf::verify('start_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        // A real site's content can take a while to walk row by row —
        // this runs as one long synchronous request (matching
        // DummyContentGenerator's own precedent; see this feature's
        // implementation plan for why no background-job/polling
        // infrastructure exists in this codebase yet) rather than
        // timing out at PHP's default execution limit.
        set_time_limit(0);

        try {
            $service = $buildImportService();
            $service->run([
                'users' => isset($_POST['include_users']),
                'categories' => isset($_POST['include_categories']),
                'media' => isset($_POST['include_media']),
                'downloads' => isset($_POST['include_downloads']),
                'pages' => isset($_POST['include_pages']),
                'posts' => isset($_POST['include_posts']),
                'comments' => isset($_POST['include_comments']),
            ]);

            // The service instance (and its in-memory warnings() log)
            // doesn't survive the redirect below — stashed in the
            // session for one read, the same "flash message" technique
            // as Csrf's own one-time token, since this screen has no
            // generic flash-message mechanism to reuse.
            $_SESSION['lp_wordpress_import_warnings'] = $service->warnings();

            header('Location: ' . admin_url('maintenance/import') . '?imported=1');
            exit;
        } catch (\Throwable $exception) {
            $importError = $exception->getMessage();
        }
    }

    if ($form === 'remove_wordpress_import' && Csrf::verify('remove_wordpress_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        // No source connection is needed to remove already-imported
        // content — every id to delete lives in content_import_records,
        // not the source database — so this constructs the service with
        // an unconnected/unused WordPressSource rather than requiring
        // the admin to re-enter DB credentials just to roll back.
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

    $summary = $buildRegistryOnlyService()->lastImportSummary();
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

    <section class="lp-admin__panel">
        <h2>WordPress Importer</h2>

        <p class="lp-field__hint">
            Imports users, categories, tags, media, pages, posts, and
            comments from an existing WordPress site via a direct database
            connection plus a local copy of its <code>wp-content/uploads</code>
            folder. The database can be the WordPress site's own live
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
            import — no manual page editing needed.
        </p>

        <?php if ($summary !== null): ?>
            <?php
            $pluralLabels = [
                'post' => 'posts', 'page' => 'pages', 'user' => 'users',
                'category' => 'categories', 'tag' => 'tags', 'comment' => 'comments', 'media' => 'media',
            ];
            ?>
            <p class="lp-field__hint">
                <strong>Last imported:</strong>
                <?= esc_html(implode(', ', array_map(
                    static fn (string $type, int $count): string => "{$count} " . ($count === 1 ? $type : ($pluralLabels[$type] ?? $type . 's')),
                    array_keys($summary['counts']),
                    array_values($summary['counts']),
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
        <?php else: ?>
            <?php
            // Two separate forms, each with its own CSRF action name and
            // its own copy of the connection fields, rather than one form
            // with two submit buttons sharing a token — see
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

            <h3>Start import</h3>

            <form method="post" action="<?= esc_url(admin_url('maintenance/import')) ?>">
                <?= Csrf::field('start_wordpress_import') ?>
                <input type="hidden" name="form" value="start_wordpress_import">

                <p class="lp-field__hint">Re-enter the same connection details above to start the import — they aren't carried over from the Test Connection form.</p>

                <p class="lp-field">
                    <label for="wp-import-2-db-host">Database host</label>
                    <input type="text" id="wp-import-2-db-host" name="db_host" value="<?= esc_attr($formValues['db_host']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-2-db-port">Database port</label>
                    <input type="text" id="wp-import-2-db-port" name="db_port" value="<?= esc_attr($formValues['db_port']) ?>">
                </p>
                <p class="lp-field">
                    <label for="wp-import-2-db-name">Database name</label>
                    <input type="text" id="wp-import-2-db-name" name="db_name" value="<?= esc_attr($formValues['db_name']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-2-db-user">Database username</label>
                    <input type="text" id="wp-import-2-db-user" name="db_user" value="<?= esc_attr($formValues['db_user']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-2-db-password">Database password</label>
                    <input type="password" id="wp-import-2-db-password" name="db_password" value="<?= esc_attr($formValues['db_password']) ?>">
                </p>
                <p class="lp-field">
                    <label for="wp-import-2-db-prefix">Table prefix</label>
                    <input type="text" id="wp-import-2-db-prefix" name="db_prefix" value="<?= esc_attr($formValues['db_prefix']) ?>" required>
                </p>
                <p class="lp-field">
                    <label for="wp-import-2-uploads-path">Uploads folder path (server filesystem)</label>
                    <input type="text" id="wp-import-2-uploads-path" name="uploads_path" value="<?= esc_attr($formValues['uploads_path']) ?>" required placeholder="/path/to/wp-content/uploads">
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
                </p>

                <div class="lp-alert lp-alert--warning">
                    A real site's content can take a long time to import.
                    This runs as one request — do not navigate away or
                    close the tab while it's in progress.
                </div>

                <button type="submit" class="lp-button lp-button--primary">Start Import</button>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="lp-admin__panel">
        <p class="lp-field__hint">Activate the WordPress Importer plugin to migrate content from an existing WordPress site.</p>
    </section>
<?php endif; ?>
