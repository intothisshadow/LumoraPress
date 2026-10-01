<?php

/**
 * The admin Thumbnails screen: regenerate or inspect generated image sizes.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$thumbnailService = $kernel->thumbnails;
$enabledSizeNames = array_keys(array_filter($thumbnailService->sizes(), static fn (array $size): bool => $size['enabled']));

/**
 * Size names ticked in the regenerate form (or carried through the
 * "Continue" form), limited to sizes that exist and are enabled.
 *
 * @param mixed $raw
 * @return array<int, string>
 */
$selectedSizeNames = static function (mixed $raw) use ($enabledSizeNames): array {
    $names = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? explode(',', $raw) : []);

    return array_values(array_intersect($enabledSizeNames, array_map('strval', $names)));
};

// thumbnail-bulk.js drives the "Continue" batches via fetch() so the page
// doesn't reload for every batch; it marks its requests with this header
// and gets JSON back. A plain form submit (no JavaScript) still redirects.
$isAjaxContinueRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'cleanup_orphaned_thumbnails' && Csrf::verify('cleanup_orphaned_thumbnails', $token)) {
        $removed = $thumbnailService->deleteOrphaned();
        header('Location: ' . admin_url('media/thumbnails') . '?orphans_removed=' . $removed);
        exit;
    } elseif ($form === 'bulk_regenerate_thumbnails' && Csrf::verify('bulk_regenerate_thumbnails', $token)) {
        $missingOnly = ($_POST['missing_only'] ?? '') === '1';
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $chosenSizes = $selectedSizeNames($_POST['sizes'] ?? []);

        if ($chosenSizes === []) {
            header('Location: ' . admin_url('media/thumbnails') . '?thumb_error=no_sizes');
            exit;
        }

        // Every enabled size chosen is the same as no restriction.
        $onlySizes = count($chosenSizes) === count($enabledSizeNames) ? null : $chosenSizes;
        $batch = $thumbnailService->queueForBulkRegeneration($missingOnly, $offset, 10, $onlySizes);

        if ($isAjaxContinueRequest) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $processedSoFar = min($offset + $batch['processed'], $batch['total']);

            header('Content-Type: application/json');
            echo json_encode([
                'done' => $batch['done'],
                'processed' => $processedSoFar,
                'total' => $batch['total'],
                'percent' => $batch['total'] > 0 ? (int) round(min(100, $processedSoFar / $batch['total'] * 100)) : 100,
                'next_offset' => $offset + 10,
                // Single-use token for the script's next request.
                'csrf_token' => Csrf::token('bulk_regenerate_thumbnails'),
            ]);
            exit;
        }

        $query = http_build_query([
            'thumb_progress' => $offset + $batch['processed'],
            'thumb_total' => $batch['total'],
            'thumb_done' => $batch['done'] ? '1' : '0',
            'missing_only' => $missingOnly ? '1' : '0',
            'next_offset' => $offset + 10,
            'sizes' => implode(',', $chosenSizes),
        ]);
        header('Location: ' . admin_url('media/thumbnails') . '?' . $query);
        exit;
    } elseif ($form === 'thumbnail_settings' && $currentUser->can('manage_options') && Csrf::verify('thumbnail_settings', $token)) {
        // This page only requires upload_files, but changing thumbnail
        // generation parameters is site-wide config gated by
        // manage_options — the section below doesn't render for others.
        foreach (['small', 'medium', 'large'] as $sizeName) {
            $kernel->config->setOption("thumbnail_size_{$sizeName}_width", (string) max(1, (int) ($_POST["thumbnail_size_{$sizeName}_width"] ?? 150)));
            $kernel->config->setOption("thumbnail_size_{$sizeName}_height", (string) max(1, (int) ($_POST["thumbnail_size_{$sizeName}_height"] ?? 150)));
            $kernel->config->setOption("thumbnail_size_{$sizeName}_mode", ($_POST["thumbnail_size_{$sizeName}_mode"] ?? 'fit') === 'crop' ? 'crop' : 'fit');
            $kernel->config->setOption("thumbnail_size_{$sizeName}_enabled", ($_POST["thumbnail_size_{$sizeName}_enabled"] ?? '') === '1' ? '1' : '0');
        }
        $kernel->config->setOption('thumbnail_jpeg_quality', (string) max(0, min(100, (int) ($_POST['thumbnail_jpeg_quality'] ?? 82))));
        $kernel->config->setOption('thumbnail_webp_quality', (string) max(0, min(100, (int) ($_POST['thumbnail_webp_quality'] ?? 80))));
        $kernel->config->setOption('thumbnail_sharpen', ($_POST['thumbnail_sharpen'] ?? '') === '1' ? '1' : '0');
        $kernel->config->setOption('thumbnail_max_pixels', (string) max(1, (int) ($_POST['thumbnail_max_pixels'] ?? 25_000_000)));
        header('Location: ' . admin_url('media/thumbnails') . '?saved=1');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Media Manager</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['orphans_removed'])): ?>
    <div class="lp-alert lp-alert--success">Removed <?= (int) $_GET['orphans_removed'] ?> orphaned thumbnail(s).</div>
<?php endif; ?>

<?php if (($_GET['thumb_error'] ?? '') === 'no_sizes'): ?>
    <div class="lp-alert lp-alert--error">Choose at least one thumbnail size to regenerate.</div>
<?php endif; ?>

<?php if (isset($_GET['thumb_progress'])): ?>
    <?php
    $thumbProgress = (int) $_GET['thumb_progress'];
    $thumbTotal = (int) ($_GET['thumb_total'] ?? 0);
    $thumbDone = ($_GET['thumb_done'] ?? '0') === '1';
    $thumbMissingOnly = ($_GET['missing_only'] ?? '0') === '1';
    $thumbNextOffset = (int) ($_GET['next_offset'] ?? 0);
    $thumbSizes = $selectedSizeNames($_GET['sizes'] ?? '');
    $thumbPercent = $thumbTotal > 0 ? (int) round(min(100, $thumbProgress / $thumbTotal * 100)) : 100;
    ?>
    <div class="lp-alert lp-alert--success">
        <p>Regenerating thumbnails: <span data-lp-thumb-progress><?= $thumbProgress ?></span> of <span data-lp-thumb-total><?= $thumbTotal ?></span> processed.</p>
        <div class="lp-thumbnails__progress" role="progressbar" aria-valuenow="<?= $thumbPercent ?>" aria-valuemin="0" aria-valuemax="100" data-lp-thumb-bar-wrap>
            <div class="lp-thumbnails__progress-bar" data-style-width="<?= $thumbPercent ?>%" data-lp-thumb-bar></div>
        </div>
        <?php if (!$thumbDone): ?>
            <form method="post" action="<?= esc_url(admin_url('media/thumbnails')) ?>" id="thumb-bulk-continue">
                <?= Csrf::field('bulk_regenerate_thumbnails') ?>
                <input type="hidden" name="form" value="bulk_regenerate_thumbnails">
                <input type="hidden" name="missing_only" value="<?= $thumbMissingOnly ? '1' : '0' ?>">
                <input type="hidden" name="offset" value="<?= $thumbNextOffset ?>">
                <?php foreach ($thumbSizes as $thumbSizeName): ?>
                    <input type="hidden" name="sizes[]" value="<?= esc_attr($thumbSizeName) ?>">
                <?php endforeach; ?>
                <button type="submit" class="lp-button lp-button--primary">Continue</button>
            </form>
        <?php endif; ?>
        <p data-lp-thumb-done <?= $thumbDone ? '' : 'hidden' ?>>Done.</p>
    </div>
<?php endif; ?>

<?php if ($currentUser->can('manage_options')): ?>
    <section class="lp-admin__panel">
        <h2>Thumbnail Settings</h2>
        <form method="post" action="<?= esc_url(admin_url('media/thumbnails')) ?>">
            <?= Csrf::field('thumbnail_settings') ?>
            <input type="hidden" name="form" value="thumbnail_settings">

            <?php foreach (['small' => ['150', '150', 'crop'], 'medium' => ['300', '300', 'fit'], 'large' => ['1024', '1024', 'fit']] as $sizeName => $defaults): ?>
                <fieldset class="lp-field">
                    <legend><?= esc_html(ucfirst($sizeName)) ?></legend>

                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="thumbnail_size_<?= $sizeName ?>_enabled" value="1" <?= $kernel->config->option("thumbnail_size_{$sizeName}_enabled", '1') !== '0' ? 'checked' : '' ?>>
                        Enabled
                    </label>

                    <label for="thumbnail-<?= $sizeName ?>-width">Width</label>
                    <input type="number" id="thumbnail-<?= $sizeName ?>-width" name="thumbnail_size_<?= $sizeName ?>_width" min="1" value="<?= esc_attr((string) $kernel->config->option("thumbnail_size_{$sizeName}_width", $defaults[0])) ?>">

                    <label for="thumbnail-<?= $sizeName ?>-height">Height</label>
                    <input type="number" id="thumbnail-<?= $sizeName ?>-height" name="thumbnail_size_<?= $sizeName ?>_height" min="1" value="<?= esc_attr((string) $kernel->config->option("thumbnail_size_{$sizeName}_height", $defaults[1])) ?>">

                    <label for="thumbnail-<?= $sizeName ?>-mode">Mode</label>
                    <select id="thumbnail-<?= $sizeName ?>-mode" name="thumbnail_size_<?= $sizeName ?>_mode">
                        <option value="fit" <?= $kernel->config->option("thumbnail_size_{$sizeName}_mode", $defaults[2]) === 'fit' ? 'selected' : '' ?>>Fit (preserve aspect ratio)</option>
                        <option value="crop" <?= $kernel->config->option("thumbnail_size_{$sizeName}_mode", $defaults[2]) === 'crop' ? 'selected' : '' ?>>Crop (exact dimensions)</option>
                    </select>
                </fieldset>
            <?php endforeach; ?>

            <p class="lp-field">
                <label for="thumbnail-jpeg-quality">JPEG quality (0&ndash;100)</label>
                <input type="number" id="thumbnail-jpeg-quality" name="thumbnail_jpeg_quality" min="0" max="100" value="<?= esc_attr((string) $kernel->config->option('thumbnail_jpeg_quality', '82')) ?>">
            </p>

            <p class="lp-field">
                <label for="thumbnail-webp-quality">WebP quality (0&ndash;100)</label>
                <input type="number" id="thumbnail-webp-quality" name="thumbnail_webp_quality" min="0" max="100" value="<?= esc_attr((string) $kernel->config->option('thumbnail_webp_quality', '80')) ?>">
            </p>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="thumbnail_sharpen" value="1" <?= $kernel->config->option('thumbnail_sharpen', '0') === '1' ? 'checked' : '' ?>>
                Sharpen thumbnails after resizing
            </label>

            <p class="lp-field">
                <label for="thumbnail-max-pixels">Maximum source image pixels (memory safeguard)</label>
                <input type="number" id="thumbnail-max-pixels" name="thumbnail_max_pixels" min="1" value="<?= esc_attr((string) $kernel->config->option('thumbnail_max_pixels', '25000000')) ?>">
                <span class="lp-field__hint">Images larger than this are skipped (logged) rather than generating thumbnails for them.</span>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save</button>
        </form>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Thumbnails</h2>
    <form method="post" action="<?= esc_url(admin_url('media/thumbnails')) ?>" class="lp-admin__inline-form">
        <?= Csrf::field('bulk_regenerate_thumbnails') ?>
        <input type="hidden" name="form" value="bulk_regenerate_thumbnails">
        <input type="hidden" name="offset" value="0">
        <fieldset class="lp-field">
            <legend>Sizes to regenerate</legend>
            <?php foreach ($thumbnailService->sizes() as $sizeName => $size): ?>
                <?php if ($size['enabled']): ?>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="sizes[]" value="<?= esc_attr($sizeName) ?>" checked>
                        <?= esc_html(ucfirst($sizeName)) ?> (<?= (int) $size['width'] ?>&times;<?= (int) $size['height'] ?>, <?= $size['mode'] === 'crop' ? 'crop' : 'fit' ?>)
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
        </fieldset>
        <label class="lp-field--checkbox">
            <input type="checkbox" name="missing_only" value="1" checked>
            Only generate missing thumbnails
        </label>
        <button type="submit" class="lp-button lp-button--primary">Bulk regenerate thumbnails</button>
    </form>
    <form method="post" action="<?= esc_url(admin_url('media/thumbnails')) ?>" class="lp-admin__inline-form">
        <?= Csrf::field('cleanup_orphaned_thumbnails') ?>
        <input type="hidden" name="form" value="cleanup_orphaned_thumbnails">
        <button type="submit" class="lp-button">Clean up orphaned thumbnails</button>
    </form>
</section>
