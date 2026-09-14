<?php

/**
 * The admin Maintenance > Tools screen — a home for small admin utilities: Export/Import Settings (LP-140), and, when active, the Dummy Content plugin's generator (LPP-005) and the Lumora Sweep plugin's own Sweep tab (LPP-022).
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
/** @var bool $sweepActive */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\DummyContent\DummyContentGenerator;
use LumoraPress\Plugins\LumoraSweep\SweepService;

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

// $form is redefined identically inside the $dummyContentActive/$sweepActive
// blocks below — reading the same POST value twice into the same-named
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
// plugin-specific section below must gate on $dummyContentActive/$sweepActive.
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

// -- Sweep (LPP-022) -----------------------------------------------------
//
// Manual trigger only for v1 — this app has no cron/queue infrastructure,
// so scheduled/automatic sweeping isn't attempted. Every category below
// has its own count (preview) and clean (delete) method, kept separate so
// the preview step never risks running a destructive query by accident.
$sweepResult = null;
$sweepError = null;
$sweepUnusedTermsSaved = false;

if ($sweepActive) {
    $sweep = new SweepService(
        database: $kernel->database,
        tablePrefix: (string) $kernel->config->get('table_prefix', 'lp_'),
        config: $kernel->config,
        posts: $kernel->posts,
        pages: $kernel->pages,
        comments: $kernel->comments,
        revisions: $kernel->revisions,
        categories: $kernel->categories,
        tags: $kernel->tags,
    );

    // Days-old thresholds are simple GET filters (like the rest of this
    // app's list-screen filters) rather than a persisted setting — the
    // clean forms below carry the currently-displayed value forward as a
    // hidden field, so what gets deleted always matches what was just
    // previewed.
    $trashDays = max(0, (int) ($_GET['trash_days'] ?? 30));
    $commentsDays = max(0, (int) ($_GET['comments_days'] ?? 30));
    $unusedTermsEnabled = ((string) $kernel->config->option('sweep_unused_terms_enabled', '0')) === '1';

    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'sweep_clean_revisions' && Csrf::verify('sweep_clean_revisions', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $removed = $sweep->cleanExcessRevisions();
        $sweep->optimizeTables(['revisions']);

        header('Location: ' . admin_url('maintenance/tools') . "?tab=sweep&trash_days={$trashDays}&comments_days={$commentsDays}&sweep_cleaned=revisions&sweep_removed={$removed}");
        exit;
    }

    if ($form === 'sweep_clean_trash' && Csrf::verify('sweep_clean_trash', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $postedDays = max(0, (int) ($_POST['days'] ?? $trashDays));
        $removed = $sweep->cleanOldTrashedContent($postedDays);
        $sweep->optimizeTables(['posts', 'pages']);

        header('Location: ' . admin_url('maintenance/tools') . "?tab=sweep&trash_days={$postedDays}&comments_days={$commentsDays}&sweep_cleaned=trash&sweep_removed={$removed}");
        exit;
    }

    if ($form === 'sweep_clean_comments' && Csrf::verify('sweep_clean_comments', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $postedDays = max(0, (int) ($_POST['days'] ?? $commentsDays));
        $removed = $sweep->cleanOldSpamOrTrashComments($postedDays);
        $sweep->optimizeTables(['comments']);

        header('Location: ' . admin_url('maintenance/tools') . "?tab=sweep&trash_days={$trashDays}&comments_days={$postedDays}&sweep_cleaned=comments&sweep_removed={$removed}");
        exit;
    }

    if ($form === 'sweep_clean_orphaned_meta' && Csrf::verify('sweep_clean_orphaned_meta', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $removed = $sweep->cleanOrphanedPostMeta();
        $sweep->optimizeTables(['post_meta']);

        header('Location: ' . admin_url('maintenance/tools') . "?tab=sweep&trash_days={$trashDays}&comments_days={$commentsDays}&sweep_cleaned=orphaned_meta&sweep_removed={$removed}");
        exit;
    }

    if ($form === 'sweep_clean_duplicate_meta' && Csrf::verify('sweep_clean_duplicate_meta', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $removed = $sweep->cleanDuplicatePostMeta();
        $sweep->optimizeTables(['post_meta']);

        header('Location: ' . admin_url('maintenance/tools') . "?tab=sweep&trash_days={$trashDays}&comments_days={$commentsDays}&sweep_cleaned=duplicate_meta&sweep_removed={$removed}");
        exit;
    }

    if ($form === 'sweep_save_unused_terms_setting' && Csrf::verify('sweep_save_unused_terms_setting', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $kernel->config->setOption('sweep_unused_terms_enabled', isset($_POST['sweep_unused_terms_enabled']) ? '1' : '0');

        header('Location: ' . admin_url('maintenance/tools') . "?tab=sweep&trash_days={$trashDays}&comments_days={$commentsDays}&sweep_unused_terms_saved=1");
        exit;
    }

    if ($form === 'sweep_clean_unused_terms' && $unusedTermsEnabled && Csrf::verify('sweep_clean_unused_terms', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $removed = $sweep->cleanUnusedCategories() + $sweep->cleanUnusedTags();
        $sweep->optimizeTables(['categories', 'tags']);

        header('Location: ' . admin_url('maintenance/tools') . "?tab=sweep&trash_days={$trashDays}&comments_days={$commentsDays}&sweep_cleaned=unused_terms&sweep_removed={$removed}");
        exit;
    }

    $sweepCleanedCategory = is_string($_GET['sweep_cleaned'] ?? null) ? $_GET['sweep_cleaned'] : null;
    $sweepRemovedCount = isset($_GET['sweep_removed']) ? (int) $_GET['sweep_removed'] : null;
    $sweepUnusedTermsSaved = isset($_GET['sweep_unused_terms_saved']);

    $sweepCategoryLabels = [
        'revisions' => 'excess revision',
        'trash' => 'old trashed post/page',
        'comments' => 'old spam/trashed comment',
        'orphaned_meta' => 'orphaned post_meta row',
        'duplicate_meta' => 'duplicate post_meta row',
        'unused_terms' => 'unused category/tag',
    ];

    $sweepCounts = [
        'revisions' => $sweep->countExcessRevisions(),
        'trash' => $sweep->countOldTrashedContent($trashDays),
        'comments' => $sweep->countOldSpamOrTrashComments($commentsDays),
        'orphaned_meta' => $sweep->countOrphanedPostMeta(),
        'duplicate_meta' => $sweep->countDuplicatePostMeta(),
        'unused_terms' => $unusedTermsEnabled ? ($sweep->countUnusedCategories() + $sweep->countUnusedTags()) : null,
    ];
}

$tabs = ['general' => 'General'];

if ($sweepActive) {
    $tabs['sweep'] = 'Sweep';
}

$activeTab = in_array($_GET['tab'] ?? '', array_keys($tabs), true) ? $_GET['tab'] : 'general';

?>
<h1 class="lp-admin__title">Tools</h1>

<div class="lp-tabs">
    <div class="lp-tabs__list" role="tablist" aria-label="Tools section">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <button type="button" class="lp-tabs__tab" id="lp-tab-<?= esc_attr($tabKey) ?>" role="tab" aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-<?= esc_attr($tabKey) ?>" tabindex="<?= $activeTab === $tabKey ? '0' : '-1' ?>"><?= esc_html($tabLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-general" role="tabpanel" aria-labelledby="lp-tab-general"<?= $activeTab === 'general' ? '' : ' hidden' ?>>
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
    </div>

    <?php if ($sweepActive): ?>
    <div class="lp-tabs__panel" id="lp-tabpanel-sweep" role="tabpanel" aria-labelledby="lp-tab-sweep"<?= $activeTab === 'sweep' ? '' : ' hidden' ?>>
        <p class="lp-field__hint">
            Cleans up unused, orphaned, and duplicated database rows.
            Each category below shows a live count first — nothing is
            deleted until you click that category's own Clean Up button.
        </p>

        <?php if ($sweepRemovedCount !== null && $sweepCleanedCategory !== null): ?>
            <div class="lp-alert lp-alert--success">
                <?php if ($sweepRemovedCount === 0): ?>
                    Nothing to clean — no <?= esc_html($sweepCategoryLabels[$sweepCleanedCategory] ?? 'row') ?>s were found.
                <?php else: ?>
                    Removed <?= (int) $sweepRemovedCount ?> <?= esc_html($sweepCategoryLabels[$sweepCleanedCategory] ?? 'row') ?><?= $sweepRemovedCount === 1 ? '' : 's' ?>.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($sweepUnusedTermsSaved): ?>
            <div class="lp-alert lp-alert--success">Saved.</div>
        <?php endif; ?>

        <section class="lp-admin__panel">
            <h2>Excess Revisions</h2>
            <p class="lp-field__hint">
                Revisions are already capped at <?= (int) $kernel->config->option('revision_retention', 25) ?> per post/page going
                forward (Settings &rsaquo; Writing). This is a one-time retroactive
                prune for content that already had more revisions than that
                before the cap was set or lowered.
            </p>
            <p><strong><?= (int) $sweepCounts['revisions'] ?></strong> excess revision<?= $sweepCounts['revisions'] === 1 ? '' : 's' ?> found.</p>
            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>?tab=sweep" data-lp-confirm="Permanently delete <?= (int) $sweepCounts['revisions'] ?> excess revision(s)? This cannot be undone.">
                <?= Csrf::field('sweep_clean_revisions') ?>
                <input type="hidden" name="form" value="sweep_clean_revisions">
                <button type="submit" class="lp-button lp-button--danger" <?= $sweepCounts['revisions'] === 0 ? 'disabled' : '' ?>>Clean Up</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Old Trashed Posts &amp; Pages</h2>
            <p class="lp-field__hint">
                Posts and Pages each already have their own Empty Trash
                action; this instead only removes trashed items older than
                a chosen number of days, combined across both content
                types in one place.
            </p>
            <form method="get" action="<?= esc_url(admin_url('maintenance/tools')) ?>" class="lp-admin__inline-form">
                <input type="hidden" name="tab" value="sweep">
                <input type="hidden" name="comments_days" value="<?= (int) $commentsDays ?>">
                <label for="sweep-trash-days">Older than</label>
                <input type="number" id="sweep-trash-days" name="trash_days" min="0" value="<?= (int) $trashDays ?>" style="width: 5em;">
                <span class="lp-field__hint">days</span>
                <button type="submit" class="lp-button">Update Count</button>
            </form>
            <p><strong><?= (int) $sweepCounts['trash'] ?></strong> trashed post<?= $sweepCounts['trash'] === 1 ? '' : 's' ?>/page(s) found older than <?= (int) $trashDays ?> day<?= $trashDays === 1 ? '' : 's' ?>.</p>
            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>?tab=sweep" data-lp-confirm="Permanently delete <?= (int) $sweepCounts['trash'] ?> trashed post(s)/page(s)? This cannot be undone.">
                <?= Csrf::field('sweep_clean_trash') ?>
                <input type="hidden" name="form" value="sweep_clean_trash">
                <input type="hidden" name="days" value="<?= (int) $trashDays ?>">
                <button type="submit" class="lp-button lp-button--danger" <?= $sweepCounts['trash'] === 0 ? 'disabled' : '' ?>>Clean Up</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Old Spam &amp; Trashed Comments</h2>
            <p class="lp-field__hint">
                Removes comments currently in Spam or Trash whose status
                hasn't changed in at least this many days.
            </p>
            <form method="get" action="<?= esc_url(admin_url('maintenance/tools')) ?>" class="lp-admin__inline-form">
                <input type="hidden" name="tab" value="sweep">
                <input type="hidden" name="trash_days" value="<?= (int) $trashDays ?>">
                <label for="sweep-comments-days">Older than</label>
                <input type="number" id="sweep-comments-days" name="comments_days" min="0" value="<?= (int) $commentsDays ?>" style="width: 5em;">
                <span class="lp-field__hint">days</span>
                <button type="submit" class="lp-button">Update Count</button>
            </form>
            <p><strong><?= (int) $sweepCounts['comments'] ?></strong> spam/trashed comment<?= $sweepCounts['comments'] === 1 ? '' : 's' ?> found older than <?= (int) $commentsDays ?> day<?= $commentsDays === 1 ? '' : 's' ?>.</p>
            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>?tab=sweep" data-lp-confirm="Permanently delete <?= (int) $sweepCounts['comments'] ?> comment(s)? This cannot be undone.">
                <?= Csrf::field('sweep_clean_comments') ?>
                <input type="hidden" name="form" value="sweep_clean_comments">
                <input type="hidden" name="days" value="<?= (int) $commentsDays ?>">
                <button type="submit" class="lp-button lp-button--danger" <?= $sweepCounts['comments'] === 0 ? 'disabled' : '' ?>>Clean Up</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Orphaned Custom Field Rows</h2>
            <p class="lp-field__hint">
                A custom field (<code>post_meta</code>) row whose post no
                longer exists — this can happen from a raw database
                operation, or from data left over before a cascade-delete
                fix.
            </p>
            <p><strong><?= (int) $sweepCounts['orphaned_meta'] ?></strong> orphaned row<?= $sweepCounts['orphaned_meta'] === 1 ? '' : 's' ?> found.</p>
            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>?tab=sweep" data-lp-confirm="Permanently delete <?= (int) $sweepCounts['orphaned_meta'] ?> orphaned row(s)? This cannot be undone.">
                <?= Csrf::field('sweep_clean_orphaned_meta') ?>
                <input type="hidden" name="form" value="sweep_clean_orphaned_meta">
                <button type="submit" class="lp-button lp-button--danger" <?= $sweepCounts['orphaned_meta'] === 0 ? 'disabled' : '' ?>>Clean Up</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Duplicate Custom Field Rows</h2>
            <p class="lp-field__hint">
                Two or more <code>post_meta</code> rows on the same post
                with the identical key and value — a plausible bug
                artifact, safe to de-duplicate. The oldest copy of each is
                always kept.
            </p>
            <p><strong><?= (int) $sweepCounts['duplicate_meta'] ?></strong> duplicate row<?= $sweepCounts['duplicate_meta'] === 1 ? '' : 's' ?> found.</p>
            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>?tab=sweep" data-lp-confirm="Permanently delete <?= (int) $sweepCounts['duplicate_meta'] ?> duplicate row(s)? This cannot be undone.">
                <?= Csrf::field('sweep_clean_duplicate_meta') ?>
                <input type="hidden" name="form" value="sweep_clean_duplicate_meta">
                <button type="submit" class="lp-button lp-button--danger" <?= $sweepCounts['duplicate_meta'] === 0 ? 'disabled' : '' ?>>Clean Up</button>
            </form>
        </section>

        <section class="lp-admin__panel">
            <h2>Unused Categories &amp; Tags</h2>
            <p class="lp-field__hint">
                Off by default: an empty category or tag can be
                intentional — reserved for future content — unlike the
                categories above, which are never intentional. Turn this
                on only if you're sure you want zero-post categories/tags
                removed.
            </p>
            <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>?tab=sweep">
                <?= Csrf::field('sweep_save_unused_terms_setting') ?>
                <input type="hidden" name="form" value="sweep_save_unused_terms_setting">
                <label class="lp-field--checkbox">
                    <input type="checkbox" name="sweep_unused_terms_enabled" value="1" <?= $unusedTermsEnabled ? 'checked' : '' ?>>
                    Enable unused category/tag cleanup
                </label>
                <button type="submit" class="lp-button">Save</button>
            </form>

            <?php if ($unusedTermsEnabled): ?>
                <p><strong><?= (int) $sweepCounts['unused_terms'] ?></strong> unused category/tag<?= $sweepCounts['unused_terms'] === 1 ? '' : 's' ?> found.</p>
                <form method="post" action="<?= esc_url(admin_url('maintenance/tools')) ?>?tab=sweep" data-lp-confirm="Permanently delete <?= (int) $sweepCounts['unused_terms'] ?> unused category/tag(s)? This cannot be undone.">
                    <?= Csrf::field('sweep_clean_unused_terms') ?>
                    <input type="hidden" name="form" value="sweep_clean_unused_terms">
                    <button type="submit" class="lp-button lp-button--danger" <?= $sweepCounts['unused_terms'] === 0 ? 'disabled' : '' ?>>Clean Up</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>
