<?php

/**
 * The admin Maintenance > Tools screen — a home for small admin utilities: the double-encoded-text repair (LP-113), Export/Import Settings (LP-140), and, when active, the Dummy Content plugin's generator (LPP-005).
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
/** @var bool $dummyContentActive */
/** @var bool $downloadsActive */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\DummyContent\DummyContentGenerator;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// A plain GET with no state change needs no CSRF check, but it does need
// to clear admin/index.php's output buffer before sending a raw file body.
if (($_GET['export_settings'] ?? '') === '1') {
    $exportJson = $kernel->settingsPortability->export();
    $exportFilename = 'lumorapress-settings-' . date('Y-m-d') . '.json';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
    header('Content-Length: ' . (string) strlen($exportJson));
    echo $exportJson;
    exit;
}

// Import Settings uses a stage -> inspect -> confirm/cancel upload flow:
// stage_settings_import moves the upload aside and redirects with a token
// so the confirmation screen doesn't need the file re-uploaded;
// confirm_settings_import applies it, cancel_settings_import discards it.
$settingsImportError = null;
$settingsImportApplied = null;

// $form is redefined identically inside the $dummyContentActive block
// below — reading the same POST value twice into the same-named
// variable is harmless since both reads see the same request.
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'stage_settings_import' && Csrf::verify('stage_settings_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    if (!isset($_FILES['settings_file']) || $_FILES['settings_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $settingsImportError = 'Please choose an exported settings file to upload.';
    } elseif ($_FILES['settings_file']['error'] !== UPLOAD_ERR_OK) {
        $settingsImportError = 'The file upload failed. Please try again.';
    } else {
        try {
            $token = $kernel->settingsPortability->stage($_FILES['settings_file']['tmp_name']);

            header('Location: ' . admin_url('maintenance/tools') . '?settings_import_pending=' . urlencode($token));
            exit;
        } catch (\RuntimeException $exception) {
            $settingsImportError = $exception->getMessage();
        }
    }
} elseif ($form === 'confirm_settings_import' && Csrf::verify('confirm_settings_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

    try {
        $settingsImportApplied = $kernel->settingsPortability->finalize($token);

        header('Location: ' . admin_url('maintenance/tools') . '?settings_imported=' . $settingsImportApplied);
        exit;
    } catch (\RuntimeException $exception) {
        $settingsImportError = $exception->getMessage();
    }
} elseif ($form === 'cancel_settings_import') {
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    $kernel->settingsPortability->discardStaged($token);

    header('Location: ' . admin_url('maintenance/tools'));
    exit;
}

$settingsImportPending = null;
$pendingSettingsImportToken = is_string($_GET['settings_import_pending'] ?? null) ? $_GET['settings_import_pending'] : null;

if ($pendingSettingsImportToken !== null) {
    try {
        $settingsImportPending = $kernel->settingsPortability->inspectStaged($pendingSettingsImportToken);
        $settingsImportPending['token'] = $pendingSettingsImportToken;
    } catch (\RuntimeException $exception) {
        $settingsImportError = $exception->getMessage();
    }
}

$settingsImportedCount = isset($_GET['settings_imported']) ? (int) $_GET['settings_imported'] : null;

// This page is always reachable (a fixed core menu slot), so every
// plugin-specific section below must gate on $dummyContentActive.
$dummyContentGenerated = false;
$dummyContentRemoved = false;
$dummyContentError = null;
$dummyContentSummary = null;

if ($dummyContentActive) {
    // DummyContentGenerator's class is guaranteed to already be loaded.
    // Constructed here directly from $kernel's own services, not itself
    // a Kernel property.
    $generator = new DummyContentGenerator(
        userImporter: $kernel->userImporter,
        postImporter: $kernel->postImporter,
        pageImporter: $kernel->pageImporter,
        mediaImporter: $kernel->mediaImporter,
        commentImporter: $kernel->commentImporter,
        users: $kernel->users,
        posts: $kernel->posts,
        pages: $kernel->pages,
        media: $kernel->media,
        comments: $kernel->comments,
        categories: $kernel->categories,
        tags: $kernel->tags,
        registry: $kernel->contentImportRegistry,
        tempPath: rtrim(LUMORA_ROOT, '/') . '/storage/dummy-content/tmp',
    );

    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'generate_dummy_content' && Csrf::verify('generate_dummy_content', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $volume = is_string($_POST['volume'] ?? null) && in_array($_POST['volume'], ['small', 'medium', 'large'], true)
            ? $_POST['volume']
            : 'small';

        if (!isset($_POST['confirm_dummy_content'])) {
            $dummyContentError = 'Please confirm you understand this generates test content before continuing.';
        } else {
            try {
                $generator->generate([
                    'volume' => $volume,
                    'users' => isset($_POST['include_users']),
                    'posts' => isset($_POST['include_posts']),
                    'pages' => isset($_POST['include_pages']),
                    'media' => isset($_POST['include_media']),
                    'comments' => isset($_POST['include_comments']),
                ]);

                header('Location: ' . admin_url('maintenance/tools') . '?generated=1');
                exit;
            } catch (\RuntimeException $exception) {
                $dummyContentError = $exception->getMessage();
            }
        }
    }

    if ($form === 'remove_dummy_content' && Csrf::verify('remove_dummy_content', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $generator->removeAll();

        header('Location: ' . admin_url('maintenance/tools') . '?removed=1');
        exit;
    }

    $dummyContentGenerated = isset($_GET['generated']);
    $dummyContentRemoved = isset($_GET['removed']);
    $dummyContentSummary = $generator->lastGeneratedSummary();
}

// Repairs plain-text fields double-encoded by a pre-fix WordPress
// Importer run, bypassing the normal content services entirely.
$entityDecodeResults = null;
$entityDecodeError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'repair_double_encoded_text') {
    if (!Csrf::verify('repair_double_encoded_text', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $entityDecodeError = 'Your session expired. Reload the page and try again.';
    } else {
        $entityDecodeResults = $kernel->entityDecodeRepair->repair();
    }
}

// Backfills downloads.category_id for every Download still categorized
// the old way (via folder_id) from before Downloads gained its own
// category taxonomy. Only shown while the plugin is active.
$downloadCategoryMigrationResults = null;
$downloadCategoryMigrationError = null;

if ($downloadsActive && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'migrate_download_categories') {
    if (!Csrf::verify('migrate_download_categories', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $downloadCategoryMigrationError = 'Your session expired. Reload the page and try again.';
    } else {
        $downloadCategoryMigrationResults = $kernel->downloadCategoryMigration->migrate();
    }
}
?>
<h1 class="lp-admin__title">Tools</h1>

<?php if ($entityDecodeError !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($entityDecodeError) ?></div>
<?php endif; ?>

<?php if ($entityDecodeResults !== null): ?>
    <?php $entityDecodeFixedTotal = array_sum($entityDecodeResults); ?>
    <div class="lp-alert lp-alert--success">
        <?php if ($entityDecodeFixedTotal === 0): ?>
            No double-encoded text found — nothing needed fixing.
        <?php else: ?>
            Fixed <?= (int) $entityDecodeFixedTotal ?> row<?= $entityDecodeFixedTotal === 1 ? '' : 's' ?>:
            <?= esc_html(implode(', ', array_filter(array_map(
                static fn (string $table, int $count): string => $count > 0 ? "{$count} {$table}" : '',
                array_keys($entityDecodeResults),
                array_values($entityDecodeResults),
            )))) ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Fix Double-Encoded Text</h2>

    <p class="lp-field__hint">
        Content imported from WordPress before this version could end up
        with its plain-text fields (category/tag names, post/page titles
        and excerpts, comment author names and content, user display
        names, media alt text/captions) encoded twice — showing up on the
        site as literal text like "TV &amp;amp; Movies" instead of
        "TV &amp; Movies". This scans every one of those fields and fixes
        any that are affected; it's safe to run more than once, and does
        nothing if nothing is affected.
    </p>

    <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>">
        <?= Csrf::field('repair_double_encoded_text') ?>
        <input type="hidden" name="form" value="repair_double_encoded_text">
        <button type="submit" class="lp-button lp-button--primary">Scan &amp; Fix</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Export Settings</h2>

    <p class="lp-field__hint">
        Downloads a file with this site's Permalinks, Reading, Discussion,
        Media (including Thumbnails and the Media Viewer/Lightbox), and
        the portable part of General settings — timezone, date/time
        format, SEO/feed/search/revision/REST API/editor defaults. Site
        identity (site URL, tagline, admin email) and anything
        install-specific (Security, Privacy, Redirects, Maintenance Mode,
        Cache, Embeds, and any setting that references a specific media
        file or page on this site) are never included — this file is
        meant to be imported into a <em>different</em> Lumora Press
        install, to copy configuration across, not as a full-site backup.
    </p>

    <a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('maintenance/tools') . '?export_settings=1') ?>">Download Settings File</a>
</section>

<section class="lp-admin__panel">
    <h2>Import Settings</h2>

    <?php if ($settingsImportError !== null): ?>
        <div class="lp-alert lp-alert--error"><?= esc_html($settingsImportError) ?></div>
    <?php endif; ?>

    <?php if ($settingsImportedCount !== null): ?>
        <div class="lp-alert lp-alert--success">
            <?php if ($settingsImportedCount === 0): ?>
                Nothing to change — every portable setting in that file
                already matched this site.
            <?php else: ?>
                Applied <?= (int) $settingsImportedCount ?> setting<?= $settingsImportedCount === 1 ? '' : 's' ?> from the imported file.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($settingsImportPending !== null): ?>
        <?php if ($settingsImportPending['changes'] === []): ?>
            <p class="lp-field__hint">
                Nothing to change — every portable setting in that file
                already matches this site.
                <?php if ($settingsImportPending['unknownKeys'] !== []): ?>
                    (<?= count($settingsImportPending['unknownKeys']) ?> unrecognized
                    setting<?= count($settingsImportPending['unknownKeys']) === 1 ? '' : 's' ?> in the file
                    <?= count($settingsImportPending['unknownKeys']) === 1 ? 'was' : 'were' ?> ignored.)
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="lp-field__hint">
                This file was exported
                <?php if ($settingsImportPending['exportedAt'] !== null): ?>
                    on <?= esc_html($settingsImportPending['exportedAt']) ?>
                <?php endif; ?>
                <?php if ($settingsImportPending['exportedFromVersion'] !== null): ?>
                    from Lumora Press <?= esc_html($settingsImportPending['exportedFromVersion']) ?>
                <?php endif; ?>. Review the changes below before applying them —
                this cannot be undone automatically (though every value here
                can always be edited again on its own Settings screen
                afterward).
            </p>

            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Setting</th>
                        <th scope="col">Current Value</th>
                        <th scope="col">Imported Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settingsImportPending['changes'] as $changedKey => $change): ?>
                        <tr>
                            <td><code><?= esc_html($changedKey) ?></code></td>
                            <td><?= $change['from'] === null || $change['from'] === '' ? '<span class="lp-field__hint">(empty)</span>' : esc_html($change['from']) ?></td>
                            <td><?= $change['to'] === '' ? '<span class="lp-field__hint">(empty)</span>' : esc_html($change['to']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($settingsImportPending['unknownKeys'] !== []): ?>
                <p class="lp-field__hint">
                    <?= count($settingsImportPending['unknownKeys']) ?> unrecognized
                    setting<?= count($settingsImportPending['unknownKeys']) === 1 ? '' : 's' ?> in the file
                    <?= count($settingsImportPending['unknownKeys']) === 1 ? 'was' : 'were' ?> ignored
                    (likely exported from a newer version) — nothing was
                    changed for those.
                </p>
            <?php endif; ?>

            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>">
                <?= Csrf::field('confirm_settings_import') ?>
                <input type="hidden" name="form" value="confirm_settings_import">
                <input type="hidden" name="token" value="<?= esc_attr($settingsImportPending['token']) ?>">
                <button type="submit" class="lp-button lp-button--primary">Apply These Changes</button>
            </form>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>">
            <?= Csrf::field('cancel_settings_import') ?>
            <input type="hidden" name="form" value="cancel_settings_import">
            <input type="hidden" name="token" value="<?= esc_attr($settingsImportPending['token']) ?>">
            <button type="submit" class="lp-button lp-button--secondary">Cancel</button>
        </form>
    <?php else: ?>
        <p class="lp-field__hint">
            Upload a settings file exported from another Lumora Press
            install (see Export Settings above). You'll see exactly what
            will change before anything is applied.
        </p>

        <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>" enctype="multipart/form-data">
            <?= Csrf::field('stage_settings_import') ?>
            <input type="hidden" name="form" value="stage_settings_import">

            <p class="lp-field">
                <label for="settings-import-file">Settings file</label>
                <input type="file" id="settings-import-file" name="settings_file" accept=".json,application/json" required>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Upload &amp; Review</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($downloadsActive): ?>
    <?php if ($downloadCategoryMigrationError !== null): ?>
        <div class="lp-alert lp-alert--error"><?= esc_html($downloadCategoryMigrationError) ?></div>
    <?php endif; ?>

    <?php if ($downloadCategoryMigrationResults !== null): ?>
        <div class="lp-alert lp-alert--success">
            <?php if ($downloadCategoryMigrationResults['categoriesCreated'] === 0 && $downloadCategoryMigrationResults['downloadsBackfilled'] === 0): ?>
                No downloads needed migrating — everything is already using the new category taxonomy.
            <?php else: ?>
                Created <?= (int) $downloadCategoryMigrationResults['categoriesCreated'] ?> categor<?= $downloadCategoryMigrationResults['categoriesCreated'] === 1 ? 'y' : 'ies' ?>
                and backfilled <?= (int) $downloadCategoryMigrationResults['downloadsBackfilled'] ?> download<?= $downloadCategoryMigrationResults['downloadsBackfilled'] === 1 ? '' : 's' ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="lp-admin__panel">
        <h2>Migrate Download Categories</h2>

        <p class="lp-field__hint">
            Downloads used to be categorized via the shared Media Manager
            Folder tree. Downloads now has its own dedicated category
            taxonomy (see <a href="<?= esc_url(admin_url('downloads/categories')) ?>">Downloads &rsaquo; Categories</a>) —
            this turns every Folder a Download is still categorized by
            into a matching download category (preserving its name and
            parent hierarchy) and moves the download over to it. Safe to
            run more than once; does nothing once every download has
            already been migrated.
        </p>

        <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>">
            <?= Csrf::field('migrate_download_categories') ?>
            <input type="hidden" name="form" value="migrate_download_categories">
            <button type="submit" class="lp-button lp-button--primary">Migrate</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($dummyContentActive): ?>
    <?php if ($dummyContentGenerated): ?>
        <div class="lp-alert lp-alert--success">Dummy content generated.</div>
    <?php endif; ?>

    <?php if ($dummyContentRemoved): ?>
        <div class="lp-alert lp-alert--success">All generated dummy content was removed.</div>
    <?php endif; ?>

    <?php if ($dummyContentError !== null): ?>
        <div class="lp-alert lp-alert--error"><?= esc_html($dummyContentError) ?></div>
    <?php endif; ?>

    <section class="lp-admin__panel">
        <h2>Dummy Content</h2>

        <div class="lp-alert lp-alert--warning">
            Development/testing tool only. This generates real users, posts, pages,
            media, and comments in this site's database so a theme or plugin can be
            exercised against realistic content — do not use this on a live,
            public-facing site.
        </div>

        <p class="lp-field__hint">
            One user per role, a small category tree, a varied tag set,
            placeholder images, posts mixing every status and content format,
            a few nested pages, and comments mixing status/threading/guest and
            registered authors. Every generated record is tagged so it can be
            removed in one click — see the plugin's README for exactly what's
            covered in this first pass.
        </p>

        <?php
        // Plain "+ 's'" mangles "category" and treats "media" (already
        // plural/uncountable) as needing one too — a small explicit map reads
        // better than a pluralization library for this short, fixed content-type list.
        $pluralLabels = [
            'post' => 'posts', 'page' => 'pages', 'user' => 'users',
            'category' => 'categories', 'tag' => 'tags', 'comment' => 'comments', 'media' => 'media',
        ];
        ?>
        <?php if ($dummyContentSummary !== null): ?>
            <p class="lp-field__hint">
                <strong>Last generated:</strong>
                <?= esc_html(implode(', ', array_map(
                    static fn (string $type, int $count): string => "{$count} " . ($count === 1 ? $type : ($pluralLabels[$type] ?? $type . 's')),
                    array_keys($dummyContentSummary['counts']),
                    array_values($dummyContentSummary['counts']),
                ))) ?>
                <?php if ($dummyContentSummary['createdAt'] !== null): ?>
                    at <?= esc_html($dummyContentSummary['createdAt']->format('Y-m-d H:i')) ?>
                <?php endif; ?>
            </p>

            <p class="lp-field__hint">
                Remove the existing dummy content before generating again.
            </p>

            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>" data-lp-confirm="Remove all generated dummy content? This cannot be undone.">
                <?= Csrf::field('remove_dummy_content') ?>
                <input type="hidden" name="form" value="remove_dummy_content">
                <button type="submit" class="lp-button lp-button--danger">Remove All Generated Content</button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>">
                <?= Csrf::field('generate_dummy_content') ?>
                <input type="hidden" name="form" value="generate_dummy_content">

                <p class="lp-field">
                    <label for="dummy-content-volume">Volume</label>
                    <select id="dummy-content-volume" name="volume">
                        <option value="small">Small (5 posts, 2 pages, 10 comments)</option>
                        <option value="medium">Medium (20 posts, 5 pages, 40 comments)</option>
                        <option value="large">Large (50 posts, 10 pages, 100 comments)</option>
                    </select>
                </p>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_users" value="1" checked>
                        Users (one per role)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_posts" value="1" checked>
                        Posts
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_pages" value="1" checked>
                        Pages
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_media" value="1" checked>
                        Media (placeholder images)
                    </label>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="include_comments" value="1" checked>
                        Comments
                    </label>
                </p>

                <p class="lp-field">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="confirm_dummy_content" value="1" required>
                        I understand this generates test content on this site.
                    </label>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Generate Dummy Content</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
