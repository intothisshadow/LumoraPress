<?php

/**
 * The admin Maintenance > Tools screen — a home for small admin utilities, currently just the Dummy Content plugin's generator (LPP-005).
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

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\DummyContent\DummyContentGenerator;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * Unlike the old dedicated "Dummy Content" menu entry, this page is
 * always reachable (Maintenance > Tools is a fixed core menu slot), so
 * every plugin-specific section below must gate on the plugin actually
 * being active — $dummyContentActive comes from admin/index.php, computed
 * before this view is required, the same way it previously gated the
 * standalone menu entry itself.
 */
$dummyContentGenerated = false;
$dummyContentRemoved = false;
$dummyContentError = null;
$dummyContentSummary = null;

if ($dummyContentActive) {
    /*
     * LPP-005. DummyContentGenerator's class is guaranteed to already be
     * loaded — PluginManager::loadActive() required
     * content/plugins/dummy-content/dummy-content.php earlier this same
     * request, in include/bootstrap.php. Constructed here directly from
     * $kernel's own services (not itself a Kernel property) — see
     * dummy-content.php's own docblock for why this plugin has nothing to
     * register at load time.
     */
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
?>
<h1 class="lp-admin__title">Tools</h1>

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
<?php else: ?>
    <section class="lp-admin__panel">
        <p class="lp-field__hint">No developer tools are currently active.</p>
    </section>
<?php endif; ?>
