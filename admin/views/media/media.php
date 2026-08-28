<?php

/**
 * Shared helpers for the admin media views, including the folder-picker option builder.
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
use LumoraPress\Core\Theme\MediaViewer;
use LumoraPress\Models\Folder;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$mediaService = $kernel->media;
$folderService = $kernel->folders;
$usageChecker = $kernel->mediaUsage;
$thumbnailService = $kernel->thumbnails;
$mediaStats = $kernel->mediaStats;

$error = null;
$warnUsages = null;

/**
 * Builds an indented, depth-first flat list of {id, label} for a
 * "move to folder" <select>, excluding $excludeIds (e.g. a folder being
 * moved, plus its own descendants — FolderService::update() enforces the
 * same rule server-side; this just keeps the UI from offering an option
 * the server would reject).
 *
 * @param array<int, Folder> $allFolders
 * @param array<int, int> $excludeIds
 * @return array<int, array{id: int, label: string}>
 */
$buildFolderOptions = function (array $allFolders, array $excludeIds, ?int $parentId = null, int $depth = 0) use (&$buildFolderOptions): array {
    $options = [];

    foreach ($allFolders as $folder) {
        if ($folder->parentId !== $parentId) {
            continue;
        }

        if (!in_array($folder->id, $excludeIds, true)) {
            $options[] = ['id' => $folder->id, 'label' => str_repeat('— ', $depth) . $folder->name];
        }

        $options = [...$options, ...$buildFolderOptions($allFolders, $excludeIds, $folder->id, $depth + 1)];
    }

    return $options;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $currentFolderParam = is_string($_POST['current_folder'] ?? null) ? $_POST['current_folder'] : '';
    $backToList = admin_url('media/media') . ($currentFolderParam !== '' ? '?folder=' . urlencode($currentFolderParam) : '');

    if ($form === 'save_folder_tree_state' && Csrf::verify('save_folder_tree_state', $token)) {
        // admin/index.php's ob_start() buffer already holds
        // layout-header.php's HTML shell by the time this runs — discard
        // it before sending a JSON response, matching the identical
        // pattern in admin/views/media/upload.php's AJAX upload handler.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');

        $collapsedIds = array_map('intval', array_filter((array) ($_POST['collapsed'] ?? []), 'is_numeric'));
        $kernel->users->setCollapsedMediaFolders($currentUser->id, $collapsedIds);

        echo json_encode(['success' => true, 'csrfToken' => Csrf::token('save_folder_tree_state')]);
        exit;
    } elseif ($form === 'create_folder' && Csrf::verify('create_folder', $token)) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);

        if ($name === '') {
            $error = 'A folder name is required.';
        } else {
            $folderService->create($name, $parentId > 0 ? $parentId : null);
            header('Location: ' . $backToList);
            exit;
        }
    } elseif ($form === 'rename_folder') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('rename_folder_' . $id, $token)) {
            $name = trim((string) ($_POST['name'] ?? ''));
            $parentId = (int) ($_POST['parent_id'] ?? 0);

            if ($name === '') {
                $error = 'A folder name is required.';
            } else {
                try {
                    $folderService->update($id, $name, $parentId > 0 ? $parentId : null);
                    header('Location: ' . $backToList);
                    exit;
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }
    } elseif ($form === 'delete_folder') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('delete_folder_' . $id, $token)) {
            if (!$folderService->delete($id)) {
                $error = 'That folder is not empty — move or delete its contents first.';
            } else {
                header('Location: ' . $backToList);
                exit;
            }
        }
    } elseif ($form === 'update_metadata' && Csrf::verify('update_metadata', $token)) {
        $id = (int) ($_POST['id'] ?? 0);
        $folderId = (int) ($_POST['folder_id'] ?? 0);

        $mediaService->updateMetadata(
            $id,
            trim((string) ($_POST['alt_text'] ?? '')) ?: null,
            trim((string) ($_POST['caption'] ?? '')) ?: null,
            trim((string) ($_POST['description'] ?? '')) ?: null,
            trim((string) ($_POST['notes'] ?? '')) ?: null,
        );
        $mediaService->move($id, $folderId > 0 ? $folderId : null);

        header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $id . '&saved=1');
        exit;
    } elseif ($form === 'update_video_assets' && Csrf::verify('update_video_assets', $token)) {
        $id = (int) ($_POST['id'] ?? 0);
        $posterMediaId = (int) ($_POST['poster_media_id'] ?? 0);
        $captionTrackMediaId = (int) ($_POST['caption_track_media_id'] ?? 0);

        $mediaService->setVideoAssets($id, $posterMediaId > 0 ? $posterMediaId : null, $captionTrackMediaId > 0 ? $captionTrackMediaId : null);

        header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $id . '&saved=1');
        exit;
    } elseif ($form === 'delete_file' && Csrf::verify('delete_file', $token)) {
        $id = (int) ($_POST['id'] ?? 0);
        $confirmed = ($_POST['confirm_delete'] ?? '') === '1';
        $usages = $usageChecker->describeUsage($id);

        if ($usages !== [] && !$confirmed) {
            $warnUsages = $usages;
        } else {
            $thumbnailService->deleteForMedia($id);
            $mediaService->delete($id);
            header('Location: ' . admin_url('media/media'));
            exit;
        }
    } elseif ($form === 'regenerate_thumbnails' && Csrf::verify('regenerate_thumbnails', $token)) {
        $id = (int) ($_POST['id'] ?? 0);
        $thumbnailService->regenerate($id);
        header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $id . '&saved=1');
        exit;
    } elseif ($form === 'create_cropped_featured_image' && Csrf::verify('create_cropped_featured_image', $token)) {
        $id = (int) ($_POST['id'] ?? 0);
        $sourceMedia = $mediaService->find($id);
        $cropX = $_POST['crop_x'] ?? '';
        $cropY = $_POST['crop_y'] ?? '';
        $cropWidth = $_POST['crop_width'] ?? '';
        $cropHeight = $_POST['crop_height'] ?? '';

        if ($sourceMedia === null) {
            $error = 'Media item not found.';
        } elseif (
            !is_numeric($cropX) || !is_numeric($cropY) || !is_numeric($cropWidth) || !is_numeric($cropHeight)
            || (int) $cropWidth <= 0 || (int) $cropHeight <= 0
        ) {
            $error = 'Please select a crop area first.';
        } else {
            $crop = [
                'x' => max(0, (int) $cropX),
                'y' => max(0, (int) $cropY),
                'width' => (int) $cropWidth,
                'height' => (int) $cropHeight,
            ];
            $sourceFolderId = $sourceMedia['folder_id'] !== null ? (int) $sourceMedia['folder_id'] : null;
            $newMedia = $thumbnailService->createCroppedFeaturedMedia($sourceMedia, $crop, $currentUser->id, $sourceFolderId);

            if ($newMedia === null) {
                $error = 'Could not create the cropped featured image.';
            } else {
                header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $newMedia['id'] . '&saved=1');
                exit;
            }
        }
    } elseif ($form === 'set_default_featured_image' && $currentUser->can('manage_options') && Csrf::verify('set_default_featured_image', $token)) {
        // LP-099: the same site-wide default-featured-image option
        // Media Manager > Thumbnails' own dropdown writes
        // (admin/views/media/thumbnails.php) — kept behind the identical
        // manage_options gate that page already applies to this same
        // option, even though this page itself only requires
        // upload_files (see that file's own LP-061 note for why).
        $id = (int) ($_POST['id'] ?? 0);
        $kernel->config->setOption('default_featured_image_media_id', (string) $id);
        header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $id . '&saved=1');
        exit;
    } elseif ($form === 'unset_default_featured_image' && $currentUser->can('manage_options') && Csrf::verify('unset_default_featured_image', $token)) {
        $id = (int) ($_POST['id'] ?? 0);
        $kernel->config->setOption('default_featured_image_media_id', '0');
        header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $id . '&saved=1');
        exit;
    } elseif ($form === 'replace_file' && Csrf::verify('replace_file', $token)) {
        $id = (int) ($_POST['id'] ?? 0);

        if (!isset($_FILES['replacement']) || $_FILES['replacement']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a replacement file.';
        } else {
            try {
                $mediaService->replace($id, $_FILES['replacement']);
                $thumbnailService->regenerate($id);
                header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $id . '&saved=1');
                exit;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'bulk_action' && Csrf::verify('bulk_action', $token)) {
        $ids = array_filter(array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []), static fn (int $id): bool => $id > 0);
        $bulkActionType = (string) ($_POST['bulk_action_type'] ?? '');

        if ($ids === []) {
            $error = 'Select at least one file first.';
        } elseif ($bulkActionType === 'move') {
            $targetFolder = (int) ($_POST['target_folder'] ?? 0);

            foreach ($ids as $id) {
                $mediaService->move($id, $targetFolder > 0 ? $targetFolder : null);
            }

            header('Location: ' . $backToList . (str_contains($backToList, '?') ? '&' : '?') . 'moved=' . count($ids));
            exit;
        } elseif ($bulkActionType === 'delete') {
            $deleted = 0;
            $skipped = [];

            foreach ($ids as $id) {
                $usages = $usageChecker->describeUsage($id);

                if ($usages === []) {
                    $thumbnailService->deleteForMedia($id);
                    $mediaService->delete($id);
                    $deleted++;
                } else {
                    $item = $mediaService->find($id);
                    $skipped[] = $item !== null ? (string) $item['file_name'] : "#{$id}";
                }
            }

            $query = http_build_query(['deleted' => $deleted, 'skipped' => implode(',', $skipped)]);
            header('Location: ' . $backToList . (str_contains($backToList, '?') ? '&' : '?') . $query);
            exit;
        } elseif ($bulkActionType === 'download') {
            $zipPath = tempnam(sys_get_temp_dir(), 'lumora-media-download-');
            $zip = new ZipArchive();

            if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                $error = 'Unable to create the download archive.';
            } else {
                $usedNames = [];

                foreach ($ids as $id) {
                    $item = $mediaService->find($id);

                    if ($item === null) {
                        continue;
                    }

                    $absolutePath = $mediaService->absolutePath($item);

                    if (!is_file($absolutePath)) {
                        continue;
                    }

                    $entryName = (string) $item['file_name'];
                    $suffix = 1;

                    while (isset($usedNames[$entryName])) {
                        $entryName = pathinfo((string) $item['file_name'], PATHINFO_FILENAME) . '-' . (++$suffix) . '.' . pathinfo((string) $item['file_name'], PATHINFO_EXTENSION);
                    }

                    $usedNames[$entryName] = true;
                    $zip->addFile($absolutePath, $entryName);
                }

                $zip->close();

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="media-' . date('Y-m-d-His') . '.zip"');
                header('Content-Length: ' . (string) filesize($zipPath));
                readfile($zipPath);
                unlink($zipPath);
                exit;
            }
        } elseif ($bulkActionType === 'rename') {
            $find = (string) ($_POST['rename_find'] ?? '');
            $replace = (string) ($_POST['rename_replace'] ?? '');

            if ($find === '') {
                $error = 'Enter text to find in order to bulk rename.';
            } else {
                $renamed = $mediaService->bulkRenameByReplacing($ids, $find, $replace);
                header('Location: ' . $backToList . (str_contains($backToList, '?') ? '&' : '?') . 'renamed=' . $renamed);
                exit;
            }
        } elseif ($bulkActionType === 'change_metadata') {
            $metaAltText = trim((string) ($_POST['bulk_alt_text'] ?? ''));
            $metaCaption = trim((string) ($_POST['bulk_caption'] ?? ''));
            $metaDescription = trim((string) ($_POST['bulk_description'] ?? ''));
            $metaNotes = trim((string) ($_POST['bulk_notes'] ?? ''));

            if ($metaAltText === '' && $metaCaption === '' && $metaDescription === '' && $metaNotes === '') {
                $error = 'Fill in at least one metadata field to apply in bulk.';
            } else {
                $updated = $mediaService->bulkUpdateMetadata(
                    $ids,
                    $metaAltText !== '' ? $metaAltText : null,
                    $metaCaption !== '' ? $metaCaption : null,
                    $metaDescription !== '' ? $metaDescription : null,
                    $metaNotes !== '' ? $metaNotes : null,
                );
                header('Location: ' . $backToList . (str_contains($backToList, '?') ? '&' : '?') . 'metaUpdated=' . $updated);
                exit;
            }
        }
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$allFolders = $folderService->listAll();

/*
 * LP-097: Thumbnails/List view-mode toggle, persisted per-user (not
 * just per-session) via UserService::setListViewMode() — the same
 * one-JSON-blob-column pattern editorLayoutPreferences already uses.
 * A "layout" query param on any Search & Filter/pagination link would
 * otherwise be dropped, so the choice is saved as soon as an admin
 * clicks the toggle (a plain GET link, matching this screen's existing
 * fully-reload-based filtering — no AJAX needed) and every other link
 * on this screen keeps working unchanged without needing to know about it.
 */
$requestedListView = is_string($_GET['layout'] ?? null) ? $_GET['layout'] : null;

if ($requestedListView === 'grid' || $requestedListView === 'list') {
    $kernel->users->setListViewMode($currentUser->id, 'media', $requestedListView);
    $listView = $requestedListView;
} else {
    $listView = $kernel->users->getListViewMode($currentUser->id, 'media');
}
?>
<h1 class="lp-admin__title">Media Manager</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['moved'])): ?>
    <div class="lp-alert lp-alert--success">Moved <?= (int) $_GET['moved'] ?> file(s).</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">
        Deleted <?= (int) $_GET['deleted'] ?> file(s).
        <?php if (($_GET['skipped'] ?? '') !== ''): ?>
            Skipped (currently in use): <?= esc_html((string) $_GET['skipped']) ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['renamed'])): ?>
    <div class="lp-alert lp-alert--success">Renamed <?= (int) $_GET['renamed'] ?> file(s).</div>
<?php endif; ?>

<?php if (isset($_GET['metaUpdated'])): ?>
    <div class="lp-alert lp-alert--success">Updated metadata on <?= (int) $_GET['metaUpdated'] ?> file(s).</div>
<?php endif; ?>

<?php if ($action === 'edit'): ?>
    <?php
    $editingId = (int) ($_GET['id'] ?? 0);
    $editingMedia = $editingId > 0 ? $mediaService->find($editingId) : null;

    if ($editingMedia === null) {
        header('Location: ' . admin_url('media/media'));
        exit;
    }

    $folderOptions = $buildFolderOptions($allFolders, []);
    ?>
    <section class="lp-admin__panel">
        <p>
            <a href="<?= esc_url($mediaService->url($editingMedia)) ?>" target="_blank" rel="noopener"><?= esc_html((string) $editingMedia['file_name']) ?></a>
            &mdash; <?= esc_html($mediaService->typeCategory((string) $editingMedia['mime_type'])) ?>,
            <?= esc_html(number_format(((int) $editingMedia['file_size']) / 1024, 1)) ?> KB
            <?php if ($editingMedia['width'] !== null): ?>
                , <?= (int) $editingMedia['width'] ?>&times;<?= (int) $editingMedia['height'] ?>
            <?php endif; ?>
            &mdash; Uploaded <?= esc_html((new DateTimeImmutable((string) $editingMedia['uploaded_at']))->format('M j, Y')) ?>
        </p>

        <?php if (str_starts_with((string) $editingMedia['mime_type'], 'image/')): ?>
            <?php MediaViewer::markUsed(); ?>
            <div class="lp-gallery">
                <a
                    href="<?= esc_url($mediaService->url($editingMedia)) ?>"
                    data-pswp-id="<?= (int) $editingMedia['id'] ?>"
                    <?php if ($editingMedia['width'] !== null): ?>
                        data-pswp-width="<?= (int) $editingMedia['width'] ?>"
                        data-pswp-height="<?= (int) $editingMedia['height'] ?>"
                    <?php endif; ?>
                    <?php if (($editingMedia['caption'] ?? '') !== '' || ($editingMedia['alt_text'] ?? '') !== ''): ?>
                        data-pswp-caption="<?= esc_attr((string) ($editingMedia['caption'] ?: $editingMedia['alt_text'])) ?>"
                    <?php endif; ?>
                    data-pswp-filename="<?= esc_attr((string) $editingMedia['file_name']) ?>"
                >
                    <img class="lp-media-edit__preview" src="<?= esc_url($mediaService->url($editingMedia)) ?>" alt="">
                </a>
            </div>

            <?php
            $directImageUrl = home_url(ltrim($mediaService->url($editingMedia), '/'));
            $embedAltText = (string) ($editingMedia['alt_text'] ?? '');
            $embedHtml = '<a href="' . $directImageUrl . '"><img class="alignnone size-full" src="' . $directImageUrl . '"'
                . ($editingMedia['width'] !== null ? ' width="' . (int) $editingMedia['width'] . '" height="' . (int) $editingMedia['height'] . '"' : '')
                . ' alt="' . htmlspecialchars($embedAltText, ENT_QUOTES) . '" /></a>';
            ?>
            <div class="lp-media-edit__links">
                <h3>Direct Link &amp; Embed Code</h3>
                <p class="lp-field">
                    <label for="media-direct-url">Direct image URL</label>
                    <input type="text" id="media-direct-url" class="lp-media-edit__link-field" value="<?= esc_attr($directImageUrl) ?>" readonly onclick="this.select()">
                </p>
                <p class="lp-field">
                    <label for="media-embed-html">HTML embed code</label>
                    <textarea id="media-embed-html" class="lp-media-edit__link-field" rows="2" readonly onclick="this.select()"><?= esc_html($embedHtml) ?></textarea>
                </p>
            </div>

            <?php $existingThumbnails = $thumbnailService->thumbnailsFor((int) $editingMedia['id']); ?>
            <div class="lp-thumbnails">
                <h3>Thumbnails</h3>
                <?php if ($existingThumbnails === []): ?>
                    <p class="lp-admin__widget-placeholder">No thumbnails generated (source may be smaller than every configured size).</p>
                <?php else: ?>
                    <ul class="lp-thumbnails__list">
                        <?php foreach ($existingThumbnails as $thumbnail): ?>
                            <li>
                                <a href="<?= esc_url((string) $thumbnailService->url($editingMedia, (string) $thumbnail['size_name'])) ?>" target="_blank" rel="noopener">
                                    <?= esc_html((string) $thumbnail['size_name']) ?>
                                </a>
                                (<?= (int) $thumbnail['width'] ?>&times;<?= (int) $thumbnail['height'] ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form method="post" action="<?= esc_url(admin_url('media/media')) ?>" class="lp-admin__inline-form">
                    <?= Csrf::field('regenerate_thumbnails') ?>
                    <input type="hidden" name="form" value="regenerate_thumbnails">
                    <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                    <button type="submit" class="lp-button lp-button--primary">Regenerate thumbnails</button>
                </form>
            </div>

            <div class="lp-featured-crop-create">
                <h3>Create Cropped Featured Image</h3>
                <p class="lp-field__hint">
                    Crops this image and adds the result as a new, separate item in the Media Library — the
                    original is never modified. Select it as any post's or page's featured image afterward.
                </p>

                <div class="lp-featured-crop" data-lp-featured-crop>
                    <button type="button" class="lp-button lp-button--secondary" data-lp-featured-crop-toggle>Choose Crop Area</button>

                    <form method="post" action="<?= esc_url(admin_url('media/media')) ?>" data-lp-featured-crop-editor hidden>
                        <?= Csrf::field('create_cropped_featured_image') ?>
                        <input type="hidden" name="form" value="create_cropped_featured_image">
                        <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">

                        <div class="lp-featured-crop__stage" data-lp-featured-crop-stage>
                            <img src="<?= esc_url($mediaService->url($editingMedia)) ?>" alt="" data-lp-featured-crop-image>
                            <div class="lp-featured-crop__rect" data-lp-featured-crop-rect hidden>
                                <div class="lp-featured-crop__handle" data-lp-featured-crop-handle></div>
                            </div>
                        </div>
                        <p class="lp-field__hint">Drag to select the area to use. Drag inside the selection to move it, or its bottom-right corner to resize it.</p>
                        <button type="button" class="lp-button lp-button--link" data-lp-featured-crop-clear>Clear</button>

                        <input type="hidden" name="crop_x" data-lp-featured-crop-x value="">
                        <input type="hidden" name="crop_y" data-lp-featured-crop-y value="">
                        <input type="hidden" name="crop_width" data-lp-featured-crop-width value="">
                        <input type="hidden" name="crop_height" data-lp-featured-crop-height value="">

                        <button type="submit" class="lp-button lp-button--primary">Create Cropped Featured Image</button>
                    </form>
                </div>
            </div>

            <?php if ($currentUser->can('manage_options')): ?>
                <?php
                /*
                 * LP-099: a direct way to set/unset the site-wide default
                 * featured image (LP-040 — used as the featured image, and
                 * Open Graph/Twitter Card image, for any post/page that
                 * doesn't have its own) from the image itself, instead of
                 * only via a long filename <select> on Media Manager >
                 * Thumbnails. Both write the same
                 * default_featured_image_media_id option that page's own
                 * dropdown does, so either place keeps working
                 * interchangeably.
                 */
                $isDefaultFeaturedImage = (int) $kernel->config->option('default_featured_image_media_id', '0') === (int) $editingMedia['id'];
                ?>
                <div class="lp-default-featured-image">
                    <h3>Default Featured Image</h3>
                    <?php if ($isDefaultFeaturedImage): ?>
                        <p class="lp-field__hint">This image is the site's current default featured image.</p>
                        <form method="post" action="<?= esc_url(admin_url('media/media')) ?>" class="lp-admin__inline-form">
                            <?= Csrf::field('unset_default_featured_image') ?>
                            <input type="hidden" name="form" value="unset_default_featured_image">
                            <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                            <button type="submit" class="lp-button lp-button--secondary">Remove as Default</button>
                        </form>
                    <?php else: ?>
                        <p class="lp-field__hint">
                            Used as the featured image, and Open Graph/Twitter Card image, for any post or page
                            that doesn't have its own.
                        </p>
                        <form method="post" action="<?= esc_url(admin_url('media/media')) ?>" class="lp-admin__inline-form">
                            <?= Csrf::field('set_default_featured_image') ?>
                            <input type="hidden" name="form" value="set_default_featured_image">
                            <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                            <button type="submit" class="lp-button lp-button--secondary">Set as Default Featured Image</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php elseif (str_starts_with((string) $editingMedia['mime_type'], 'video/')): ?>
            <?php
            $posterMedia = $editingMedia['poster_media_id'] !== null ? $mediaService->find((int) $editingMedia['poster_media_id']) : null;
            $captionTrackMedia = $editingMedia['caption_track_media_id'] !== null ? $mediaService->find((int) $editingMedia['caption_track_media_id']) : null;
            ?>
            <video class="lp-media-edit__video" src="<?= esc_url($mediaService->url($editingMedia)) ?>" controls preload="metadata" <?= $posterMedia !== null ? 'poster="' . esc_url($mediaService->url($posterMedia)) . '"' : '' ?>>
                <?php if ($captionTrackMedia !== null): ?>
                    <track kind="subtitles" src="<?= esc_url($mediaService->url($captionTrackMedia)) ?>">
                <?php endif; ?>
            </video>

            <form method="post" action="<?= esc_url(admin_url('media/media')) ?>" class="lp-admin__inline-form">
                <?= Csrf::field('update_video_assets') ?>
                <input type="hidden" name="form" value="update_video_assets">
                <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">

                <p class="lp-field">
                    <label for="media-poster">Poster image</label>
                    <select id="media-poster" name="poster_media_id">
                        <option value="0">(None)</option>
                        <?php foreach ($mediaService->query(['type' => 'image'], 500, 0)['items'] as $posterOption): ?>
                            <option value="<?= (int) $posterOption['id'] ?>" <?= (int) ($editingMedia['poster_media_id'] ?? 0) === (int) $posterOption['id'] ? 'selected' : '' ?>>
                                <?= esc_html((string) $posterOption['file_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="lp-field__hint">Shown before the video plays.</span>
                </p>

                <p class="lp-field">
                    <label for="media-caption-track">Caption/subtitle track</label>
                    <select id="media-caption-track" name="caption_track_media_id">
                        <option value="0">(None)</option>
                        <?php foreach ($mediaService->query(['term' => '.vtt'], 500, 0)['items'] as $trackOption): ?>
                            <?php if (!str_ends_with((string) $trackOption['file_name'], '.vtt')) { continue; } ?>
                            <option value="<?= (int) $trackOption['id'] ?>" <?= (int) ($editingMedia['caption_track_media_id'] ?? 0) === (int) $trackOption['id'] ? 'selected' : '' ?>>
                                <?= esc_html((string) $trackOption['file_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="lp-field__hint">A WebVTT (.vtt) file uploaded to the Media Manager.</span>
                </p>

                <button type="submit" class="lp-button lp-button--primary">Save</button>
            </form>
        <?php elseif (str_starts_with((string) $editingMedia['mime_type'], 'audio/')): ?>
            <audio class="lp-media-edit__audio" src="<?= esc_url($mediaService->url($editingMedia)) ?>" controls preload="metadata"></audio>
        <?php elseif ((string) $editingMedia['mime_type'] === 'application/pdf'): ?>
            <iframe class="lp-media-edit__pdf" src="<?= esc_url($mediaService->url($editingMedia)) ?>" title="<?= esc_attr((string) $editingMedia['file_name']) ?>"></iframe>
        <?php endif; ?>

        <?php if (!str_starts_with((string) $editingMedia['mime_type'], 'image/')): ?>
            <?php $editingStats = $mediaStats->get((int) $editingMedia['id']); ?>
            <ul class="lp-admin__meta-list">
                <li><span>Downloads</span> <strong><?= (int) $editingStats['downloads'] ?></strong></li>
                <li>
                    <span>Last downloaded</span>
                    <strong><?= $editingStats['lastDownloadedAt'] !== null ? esc_html($editingStats['lastDownloadedAt']->format('M j, Y g:i A')) : 'Never' ?></strong>
                </li>
            </ul>
            <p class="lp-field__hint">
                Download link (counts a download, then serves the file):
                <code><?= esc_html(home_url('media/' . (int) $editingMedia['id'] . '/download')) ?></code>
            </p>
        <?php endif; ?>

        <?php $existingExtension = strtolower((string) pathinfo((string) $editingMedia['file_path'], PATHINFO_EXTENSION)); ?>
        <details class="lp-folder-tree__manage">
            <summary>Replace file</summary>
            <p class="lp-field__hint">
                Uploads new content under this same item's existing URL — every
                post, page, and setting already linking to it keeps working.
                The replacement must be a .<?= esc_html($existingExtension) ?> file.
            </p>
            <form method="post" action="<?= esc_url(admin_url('media/media')) ?>" enctype="multipart/form-data">
                <?= Csrf::field('replace_file') ?>
                <input type="hidden" name="form" value="replace_file">
                <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                <p class="lp-field">
                    <label for="media-replacement">Replacement file</label>
                    <input type="file" id="media-replacement" name="replacement" accept=".<?= esc_attr($existingExtension) ?>" required>
                </p>
                <button type="submit" class="lp-button lp-button--primary">Replace</button>
            </form>
        </details>

        <form method="post" action="<?= esc_url(admin_url('media/media')) ?>">
            <?= Csrf::field('update_metadata') ?>
            <input type="hidden" name="form" value="update_metadata">
            <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">

            <p class="lp-field">
                <label for="media-folder">Folder</label>
                <select id="media-folder" name="folder_id">
                    <option value="0">(General Uploads)</option>
                    <?php foreach ($folderOptions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= ((int) ($editingMedia['folder_id'] ?? 0)) === $option['id'] ? 'selected' : '' ?>>
                            <?= esc_html($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="lp-field">
                <label for="media-alt-text">Alt text</label>
                <input type="text" id="media-alt-text" name="alt_text" value="<?= esc_attr((string) ($editingMedia['alt_text'] ?? '')) ?>">
            </p>

            <p class="lp-field">
                <label for="media-caption">Caption</label>
                <textarea id="media-caption" name="caption" rows="2"><?= esc_html((string) ($editingMedia['caption'] ?? '')) ?></textarea>
            </p>

            <p class="lp-field">
                <label for="media-description">Description</label>
                <textarea id="media-description" name="description" rows="3"><?= esc_html((string) ($editingMedia['description'] ?? '')) ?></textarea>
            </p>

            <p class="lp-field">
                <label for="media-notes">Notes</label>
                <textarea id="media-notes" name="notes" rows="3"><?= esc_html((string) ($editingMedia['notes'] ?? '')) ?></textarea>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save</button>
            <a class="lp-button" href="<?= esc_url(admin_url('media/media')) ?>">Back to Media Manager</a>
        </form>

        <?php if ($warnUsages !== null): ?>
            <div class="lp-alert lp-alert--error">
                <p>This file is currently in use and deleting it will break:</p>
                <ul>
                    <?php foreach ($warnUsages as $usage): ?>
                        <li><?= esc_html($usage) ?></li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?= esc_url(admin_url('media/media') . '?action=edit&id=' . (int) $editingMedia['id']) ?>" data-lp-confirm="Delete this file anyway? This cannot be undone.">
                    <?= Csrf::field('delete_file') ?>
                    <input type="hidden" name="form" value="delete_file">
                    <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                    <input type="hidden" name="confirm_delete" value="1">
                    <button type="submit" class="lp-button lp-button--danger">Delete Anyway</button>
                </form>
            </div>
        <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('media/media') . '?action=edit&id=' . (int) $editingMedia['id']) ?>" class="lp-admin__inline-form">
                <?= Csrf::field('delete_file') ?>
                <input type="hidden" name="form" value="delete_file">
                <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <?php
    $currentFolderRaw = is_string($_GET['folder'] ?? null) ? $_GET['folder'] : '';
    $currentFolderId = $currentFolderRaw !== '' ? (int) $currentFolderRaw : null;
    $term = trim((string) ($_GET['q'] ?? ''));
    $type = (string) ($_GET['type'] ?? '');
    $dateFrom = (string) ($_GET['date_from'] ?? '');
    $dateTo = (string) ($_GET['date_to'] ?? '');
    $widthMin = trim((string) ($_GET['width_min'] ?? ''));
    $widthMax = trim((string) ($_GET['width_max'] ?? ''));
    $heightMin = trim((string) ($_GET['height_min'] ?? ''));
    $heightMax = trim((string) ($_GET['height_max'] ?? ''));
    $sizeMinKb = trim((string) ($_GET['size_min'] ?? ''));
    $sizeMaxKb = trim((string) ($_GET['size_max'] ?? ''));
    $view = (string) ($_GET['view'] ?? '');
    $folderSearchTerm = trim((string) ($_GET['folder_q'] ?? ''));
    $folderTreeFolders = $folderSearchTerm !== '' ? $folderService->search($folderSearchTerm) : $allFolders;

    /*
     * LP-121: sidebar file-count badges. directCountsByFolderId() is one
     * grouped query regardless of how many folders/files exist; the
     * cumulative roll-up (a folder's badge includes its subfolders'
     * items, matching how a file explorer reports folder size) then costs
     * zero further queries since it's built from that same result plus
     * the folder list already loaded above.
     */
    $mediaDirectCounts = $mediaService->directCountsByFolderId();
    $mediaCumulativeCounts = $folderService->cumulativeCounts($mediaDirectCounts);
    $mediaTotalCount = $mediaService->countAll();
    $unassignedMediaCount = $mediaDirectCounts[0] ?? 0;

    // LP-120: which folders this user has collapsed — everything else
    // defaults to expanded (see UserService::getCollapsedMediaFolders()).
    // Ancestors of the currently active folder are always forced open
    // (without touching the saved state), so navigating into a folder
    // never leaves it hidden inside a collapsed ancestor.
    $collapsedFolderIds = $kernel->users->getCollapsedMediaFolders($currentUser->id);
    $activeFolderAncestorIds = $currentFolderId !== null ? $folderService->ancestorIds($currentFolderId) : [];

    /*
     * LP-006's built-in views (Unused / Most Downloaded / Recently
     * Downloaded / Never Downloaded) and LP-005's Smart Collections
     * (Recently Uploaded / Missing Alt Text / Large Files / ZIP Downloads /
     * Featured Images) — unpaginated, capped lists rather than folded into
     * the regular filter+pagination query() above, the same "quick, capped
     * listing" precedent already used elsewhere for picker dropdowns (e.g.
     * settings/general.php's OG image select,
     * `$kernel->media->query(['type' => 'image'], 500, 0)`). "Unused" and
     * "Featured Images" only know about the structured references
     * MediaUsageChecker/PostService/PageService check (featured images,
     * site logo/favicon/default OG image) — see MediaUsageChecker's
     * class docblock — not media embedded in post/page body content.
     */
    if ($view === 'unused') {
        $usedIds = $usageChecker->usedMediaIds();
        $items = array_values(array_filter(
            $mediaService->query([], 500, 0)['items'],
            static fn (array $item): bool => !in_array((int) $item['id'], $usedIds, true),
        ));
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'most_downloaded') {
        $items = $mediaStats->mostDownloaded(100);
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'recently_downloaded') {
        $items = $mediaStats->recentlyDownloaded(100);
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'never_downloaded') {
        $items = $mediaStats->neverDownloaded(100);
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'recently_uploaded') {
        $items = $mediaService->query([], 40, 0)['items'];
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'missing_alt') {
        $items = $mediaService->missingAltText(100);
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'large_files') {
        $items = $mediaService->largestFiles(100);
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'zip_downloads') {
        $items = $mediaService->query(['type' => 'archive'], 100, 0)['items'];
        $totalPages = 1;
        $page = 1;
    } elseif ($view === 'featured_images') {
        $featuredIds = [...$kernel->posts->featuredImageIdsInUse(), ...$kernel->pages->featuredImageIdsInUse()];
        $items = $mediaService->findMany($featuredIds);
        $totalPages = 1;
        $page = 1;
    } else {
        $filters = [
            'term' => $term,
            'type' => $type,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];

        if ($widthMin !== '') {
            $filters['widthMin'] = (int) $widthMin;
        }

        if ($widthMax !== '') {
            $filters['widthMax'] = (int) $widthMax;
        }

        if ($heightMin !== '') {
            $filters['heightMin'] = (int) $heightMin;
        }

        if ($heightMax !== '') {
            $filters['heightMax'] = (int) $heightMax;
        }

        if ($sizeMinKb !== '') {
            $filters['sizeMin'] = (int) $sizeMinKb * 1024;
        }

        if ($sizeMaxKb !== '') {
            $filters['sizeMax'] = (int) $sizeMaxKb * 1024;
        }

        if ($currentFolderRaw === '0') {
            $filters['unassignedOnly'] = true;
        } elseif ($currentFolderId !== null) {
            // Strictly this folder's own files — not descendant
            // subfolders' — so a parent folder's view never shows what's
            // actually filed under a child folder. Only "All Files"
            // shows every file regardless of folder.
            $filters['folderIds'] = [$currentFolderId];
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage = 40;
        $result = $mediaService->query($filters, $perPage, ($page - 1) * $perPage);
        $items = $result['items'];
        $totalPages = (int) max(1, ceil($result['total'] / $perPage));
    }

    /**
     * @param array<int, Folder> $allFolders
     */
    $renderFolderTree = function (array $allFolders, ?int $parentId = null) use (&$renderFolderTree, $currentFolderRaw, $mediaCumulativeCounts, $collapsedFolderIds, $activeFolderAncestorIds): void {
        $children = array_values(array_filter($allFolders, static fn (Folder $f): bool => $f->parentId === $parentId));

        if ($children === []) {
            return;
        }

        echo '<ul class="lp-folder-tree' . ($parentId === null ? '' : '__children') . '">';

        foreach ($children as $folder) {
            $isActive = $currentFolderRaw === (string) $folder->id;
            $folderCount = $mediaCumulativeCounts[$folder->id] ?? 0;
            $hasChildren = array_filter($allFolders, static fn (Folder $f): bool => $f->parentId === $folder->id) !== [];
            // Both the quick delete button (LP-120) and the Manage panel's
            // own delete form submit the exact same delete_folder action,
            // so they share one issued token rather than each calling
            // Csrf::field() separately — a second token() call under the
            // same action key would silently invalidate the first before
            // either form could be submitted (see Csrf::token()'s
            // single-token-per-action storage).
            $deleteToken = Csrf::token('delete_folder_' . $folder->id);

            echo '<li class="lp-folder-tree__item' . ($isActive ? ' is-active' : '') . '">';
            echo '<span class="lp-folder-tree__row">';
            echo '<a href="' . esc_url(admin_url('media/media') . '?folder=' . $folder->id) . '" draggable="true" data-lp-folder-drag data-folder-id="' . (int) $folder->id . '">' . esc_html($folder->name) . '</a> ';
            echo '<span class="lp-folder-tree__count">' . (int) $folderCount . '</span> ';

            echo '<form method="post" action="' . esc_url(admin_url('media/media')) . '" class="lp-folder-tree__delete-form" data-lp-confirm="Delete this folder? It must be empty.">';
            echo '<input type="hidden" name="csrf_token" value="' . esc_attr($deleteToken) . '">';
            echo '<input type="hidden" name="form" value="delete_folder">';
            echo '<input type="hidden" name="id" value="' . (int) $folder->id . '">';
            echo '<input type="hidden" name="current_folder" value="' . esc_attr($currentFolderRaw) . '">';
            echo '<button type="submit" class="lp-folder-tree__delete-button" aria-label="Delete folder &ldquo;' . esc_attr($folder->name) . '&rdquo;">&times;</button>';
            echo '</form>';

            echo '<details class="lp-folder-tree__manage lp-folder-actions"><summary>Manage</summary>';
            echo '<form method="post" action="' . esc_url(admin_url('media/media')) . '" data-lp-folder-move-form data-folder-id="' . (int) $folder->id . '">';
            echo Csrf::field('rename_folder_' . $folder->id);
            echo '<input type="hidden" name="form" value="rename_folder">';
            echo '<input type="hidden" name="id" value="' . (int) $folder->id . '">';
            echo '<input type="hidden" name="parent_id" value="' . (int) ($folder->parentId ?? 0) . '">';
            echo '<input type="hidden" name="current_folder" value="' . esc_attr($currentFolderRaw) . '">';
            echo '<input type="text" name="name" value="' . esc_attr($folder->name) . '">';
            echo '<button type="submit" class="lp-button lp-button--primary">Rename</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('media/media')) . '" data-lp-confirm="Delete this folder? It must be empty.">';
            echo '<input type="hidden" name="csrf_token" value="' . esc_attr($deleteToken) . '">';
            echo '<input type="hidden" name="form" value="delete_folder">';
            echo '<input type="hidden" name="id" value="' . (int) $folder->id . '">';
            echo '<input type="hidden" name="current_folder" value="' . esc_attr($currentFolderRaw) . '">';
            echo '<button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>';
            echo '</form>';
            echo '</details>';
            echo '</span>';

            if ($hasChildren) {
                $isCollapsed = in_array($folder->id, $collapsedFolderIds, true)
                    && !in_array($folder->id, $activeFolderAncestorIds, true);
                echo '<details class="lp-folder-tree__toggle" data-lp-folder-toggle data-folder-id="' . (int) $folder->id . '"' . ($isCollapsed ? '' : ' open') . '>';
                echo '<summary class="lp-folder-tree__toggle-summary" aria-label="Expand or collapse subfolders of &ldquo;' . esc_attr($folder->name) . '&rdquo;"></summary>';
                $renderFolderTree($allFolders, $folder->id);
                echo '</details>';
            }

            echo '</li>';
        }

        echo '</ul>';
    };
    ?>

    <div class="lp-media-manager">
        <aside class="lp-media-manager__sidebar">
            <h2>Views</h2>
            <ul class="lp-folder-tree">
                <?php foreach ([
                    '' => 'All Files',
                    'recently_uploaded' => 'Recently Uploaded',
                    'unused' => 'Unused Media',
                    'missing_alt' => 'Missing Alt Text',
                    'large_files' => 'Large Files',
                    'zip_downloads' => 'ZIP Downloads',
                    'featured_images' => 'Featured Images',
                    'most_downloaded' => 'Most Downloaded',
                    'recently_downloaded' => 'Recently Downloaded',
                    'never_downloaded' => 'Never Downloaded',
                ] as $viewValue => $viewLabel): ?>
                    <li class="lp-folder-tree__item<?= $view === $viewValue ? ' is-active' : '' ?>">
                        <a href="<?= esc_url(admin_url('media/media') . ($viewValue !== '' ? '?view=' . $viewValue : '')) ?>"><?= esc_html($viewLabel) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h2
                data-lp-folder-root-drop
                data-lp-folder-tree-state-url="<?= esc_url(admin_url('media/media')) ?>"
                data-lp-folder-tree-state-csrf="<?= esc_attr(Csrf::token('save_folder_tree_state')) ?>"
            >Folders</h2>
            <form method="get" action="<?= esc_url(admin_url('media/media')) ?>" class="lp-admin__inline-form">
                <p class="lp-field">
                    <label for="folder-q">Search folders</label>
                    <input type="text" id="folder-q" name="folder_q" value="<?= esc_attr($folderSearchTerm) ?>" placeholder="Folder name&hellip;">
                </p>
                <button type="submit" class="lp-button">Search</button>
                <?php if ($folderSearchTerm !== ''): ?>
                    <a class="lp-button" href="<?= esc_url(admin_url('media/media') . ($currentFolderRaw !== '' ? '?folder=' . urlencode($currentFolderRaw) : '')) ?>">Clear</a>
                <?php endif; ?>
            </form>
            <?php if ($folderSearchTerm !== '' && $folderTreeFolders === []): ?>
                <p class="lp-admin__widget-placeholder">No folders match &ldquo;<?= esc_html($folderSearchTerm) ?>&rdquo;.</p>
            <?php endif; ?>
            <ul class="lp-folder-tree">
                <li class="lp-folder-tree__item<?= $currentFolderRaw === '' ? ' is-active' : '' ?>">
                    <a href="<?= esc_url(admin_url('media/media')) ?>">All Files</a>
                    <span class="lp-folder-tree__count"><?= (int) $mediaTotalCount ?></span>
                </li>
                <li class="lp-folder-tree__item<?= $currentFolderRaw === '0' ? ' is-active' : '' ?>">
                    <a href="<?= esc_url(admin_url('media/media')) ?>?folder=0">General Uploads</a>
                    <span class="lp-folder-tree__count"><?= (int) $unassignedMediaCount ?></span>
                </li>
            </ul>
            <?php $renderFolderTree($folderTreeFolders); ?>

            <details class="lp-folder-tree__manage lp-folder-actions lp-folder-actions--primary">
                <summary>New Folder</summary>
                <form method="post" action="<?= esc_url(admin_url('media/media')) ?>">
                    <?= Csrf::field('create_folder') ?>
                    <input type="hidden" name="form" value="create_folder">
                    <input type="hidden" name="current_folder" value="<?= esc_attr($currentFolderRaw) ?>">
                    <p class="lp-field">
                        <label for="new-folder-name">Name</label>
                        <input type="text" id="new-folder-name" name="name" required>
                    </p>
                    <p class="lp-field">
                        <label for="new-folder-parent">Parent</label>
                        <select id="new-folder-parent" name="parent_id">
                            <option value="0">(Top level)</option>
                            <?php foreach ($buildFolderOptions($allFolders, []) as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= $currentFolderId === $option['id'] ? 'selected' : '' ?>><?= esc_html($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <button type="submit" class="lp-button lp-button--primary">Create</button>
                </form>
            </details>
        </aside>

        <div class="lp-media-manager__main">
            <?php
            // Every other link on this screen (folder/view/pagination/
            // Search & Filter) must keep working unchanged regardless of
            // which layout is active — swapping just the "layout" param
            // preserves the rest of the current query string rather than
            // resetting it back to "All Files"/no filter.
            $layoutLinkQuery = static fn (string $mode): string => http_build_query(array_merge($_GET, ['layout' => $mode]));
            ?>
            <p class="lp-media-manager__view-toggle" role="group" aria-label="View">
                <a
                    class="lp-button<?= $listView === 'grid' ? ' lp-button--primary' : ' lp-button--secondary' ?>"
                    href="<?= esc_url(admin_url('media/media') . '?' . $layoutLinkQuery('grid')) ?>"
                    aria-pressed="<?= $listView === 'grid' ? 'true' : 'false' ?>"
                >Thumbnails</a>
                <a
                    class="lp-button<?= $listView === 'list' ? ' lp-button--primary' : ' lp-button--secondary' ?>"
                    href="<?= esc_url(admin_url('media/media') . '?' . $layoutLinkQuery('list')) ?>"
                    aria-pressed="<?= $listView === 'list' ? 'true' : 'false' ?>"
                >List</a>
            </p>

            <section class="lp-admin__panel">
                <details class="lp-admin__collapsible">
                    <summary>Search &amp; Filter</summary>
                    <div class="lp-admin__collapsible__body">
                        <form method="get" action="<?= esc_url(admin_url('media/media')) ?>" class="lp-admin__filter-form">
                            <?php if ($currentFolderRaw !== ''): ?>
                                <input type="hidden" name="folder" value="<?= esc_attr($currentFolderRaw) ?>">
                            <?php endif; ?>
                            <p class="lp-field">
                                <label for="media-q">Search filename</label>
                                <input type="text" id="media-q" name="q" value="<?= esc_attr($term) ?>">
                            </p>
                            <p class="lp-field">
                                <label for="media-type">Type</label>
                                <select id="media-type" name="type">
                                    <option value="">All types</option>
                                    <?php foreach (['image' => 'Images', 'document' => 'Documents', 'archive' => 'Archives', 'audio' => 'Audio', 'video' => 'Video'] as $value => $label): ?>
                                        <option value="<?= esc_attr($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= esc_html($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p class="lp-field">
                                <label for="media-date-from">Uploaded from</label>
                                <input type="date" id="media-date-from" name="date_from" value="<?= esc_attr($dateFrom) ?>">
                            </p>
                            <p class="lp-field">
                                <label for="media-date-to">Uploaded to</label>
                                <input type="date" id="media-date-to" name="date_to" value="<?= esc_attr($dateTo) ?>">
                            </p>
                            <p class="lp-field">
                                <label for="media-width-min">Width (px)</label>
                                <input type="number" id="media-width-min" name="width_min" min="0" placeholder="Min" value="<?= esc_attr($widthMin) ?>">
                                <input type="number" name="width_max" min="0" placeholder="Max" value="<?= esc_attr($widthMax) ?>">
                            </p>
                            <p class="lp-field">
                                <label for="media-height-min">Height (px)</label>
                                <input type="number" id="media-height-min" name="height_min" min="0" placeholder="Min" value="<?= esc_attr($heightMin) ?>">
                                <input type="number" name="height_max" min="0" placeholder="Max" value="<?= esc_attr($heightMax) ?>">
                            </p>
                            <p class="lp-field">
                                <label for="media-size-min">File size (KB)</label>
                                <input type="number" id="media-size-min" name="size_min" min="0" placeholder="Min" value="<?= esc_attr($sizeMinKb) ?>">
                                <input type="number" name="size_max" min="0" placeholder="Max" value="<?= esc_attr($sizeMaxKb) ?>">
                            </p>
                            <p class="lp-field__hint">Width/height filters only match images (other file types have no dimensions).</p>
                            <button type="submit" class="lp-button">Filter</button>
                        </form>
                    </div>
                </details>
            </section>

            <section class="lp-admin__panel">
                <?php if ($items === []): ?>
                    <p class="lp-admin__widget-placeholder">No files found.</p>
                <?php else: ?>
                    <form method="post" action="<?= esc_url(admin_url('media/media')) ?>">
                        <?= Csrf::field('bulk_action') ?>
                        <input type="hidden" name="form" value="bulk_action">
                        <input type="hidden" name="current_folder" value="<?= esc_attr($currentFolderRaw) ?>">

                        <div class="lp-media-grid__toolbar">
                            <label class="lp-field--checkbox lp-media-grid__select-all">
                                <input type="checkbox" id="media-select-all" data-lp-select-all="ids[]">
                                Select all
                            </label>
                        </div>

                        <?php if ($listView === 'list'): ?>
                            <table class="lp-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><span class="lp-visually-hidden">Select</span></th>
                                        <th scope="col">File</th>
                                        <th scope="col">Type</th>
                                        <th scope="col">Size</th>
                                        <th scope="col">Dimensions</th>
                                        <th scope="col">Uploaded</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td>
                                                <label class="lp-visually-hidden" for="media-select-<?= (int) $item['id'] ?>">Select "<?= esc_html((string) $item['file_name']) ?>"</label>
                                                <input type="checkbox" id="media-select-<?= (int) $item['id'] ?>" name="ids[]" value="<?= (int) $item['id'] ?>">
                                            </td>
                                            <td>
                                                <a class="lp-media-list__file" href="<?= esc_url(admin_url('media/media')) ?>?action=edit&id=<?= (int) $item['id'] ?>">
                                                    <?php if (str_starts_with((string) $item['mime_type'], 'image/')): ?>
                                                        <img class="lp-media-list__thumb" src="<?= esc_url((string) ($thumbnailService->url($item, 'small') ?? $mediaService->url($item))) ?>" alt="">
                                                    <?php else: ?>
                                                        <span class="lp-media-list__thumb lp-media-list__thumb--file" aria-hidden="true"><?= esc_html(strtoupper($mediaService->typeCategory((string) $item['mime_type']))) ?></span>
                                                    <?php endif; ?>
                                                    <?= esc_html((string) $item['file_name']) ?>
                                                </a>
                                                <?php if (array_key_exists('downloads', $item)): ?>
                                                    <span class="lp-status-badge"><?= (int) $item['downloads'] ?> download<?= (int) $item['downloads'] === 1 ? '' : 's' ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= esc_html($mediaService->typeCategory((string) $item['mime_type'])) ?></td>
                                            <td><?= esc_html(number_format(((int) $item['file_size']) / 1024, 1)) ?> KB</td>
                                            <td><?= $item['width'] !== null ? (int) $item['width'] . '&times;' . (int) $item['height'] : '&mdash;' ?></td>
                                            <td><?= esc_html((new DateTimeImmutable((string) $item['uploaded_at']))->format('M j, Y')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="lp-media-grid">
                                <?php foreach ($items as $item): ?>
                                    <div class="lp-media-grid__item">
                                        <label class="lp-media-grid__select">
                                            <input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>">
                                        </label>
                                        <a href="<?= esc_url(admin_url('media/media')) ?>?action=edit&id=<?= (int) $item['id'] ?>">
                                            <?php if (str_starts_with((string) $item['mime_type'], 'image/')): ?>
                                                <img class="lp-media-grid__thumb" src="<?= esc_url((string) ($thumbnailService->url($item, 'small') ?? $mediaService->url($item))) ?>" alt="<?= esc_attr((string) ($item['alt_text'] ?? '')) ?>">
                                            <?php else: ?>
                                                <span class="lp-media-grid__thumb lp-media-grid__thumb--file" aria-hidden="true"><?= esc_html(strtoupper($mediaService->typeCategory((string) $item['mime_type']))) ?></span>
                                            <?php endif; ?>
                                            <span class="lp-media-grid__name"><?= esc_html((string) $item['file_name']) ?></span>
                                            <span class="lp-media-grid__date"><?= esc_html((new DateTimeImmutable((string) $item['uploaded_at']))->format('M j, Y')) ?></span>
                                            <?php if (array_key_exists('downloads', $item)): ?>
                                                <span class="lp-status-badge"><?= (int) $item['downloads'] ?> download<?= (int) $item['downloads'] === 1 ? '' : 's' ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="lp-media-manager__bulk-bar">
                            <select name="bulk_action_type">
                                <option value="move">Move selected to&hellip;</option>
                                <option value="delete">Delete selected</option>
                                <option value="download">Download selected (ZIP)</option>
                                <option value="rename">Rename selected (find &amp; replace)</option>
                                <option value="change_metadata">Change metadata on selected</option>
                            </select>
                            <select name="target_folder">
                                <option value="0">(General Uploads)</option>
                                <?php foreach ($buildFolderOptions($allFolders, []) as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"><?= esc_html($option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="lp-button">Apply</button>
                        </div>

                        <details class="lp-folder-tree__manage">
                            <summary>Rename fields (used only when &ldquo;Rename selected&rdquo; is applied)</summary>
                            <p class="lp-field">
                                <label for="bulk-rename-find">Find in filename</label>
                                <input type="text" id="bulk-rename-find" name="rename_find">
                            </p>
                            <p class="lp-field">
                                <label for="bulk-rename-replace">Replace with</label>
                                <input type="text" id="bulk-rename-replace" name="rename_replace">
                            </p>
                        </details>

                        <details class="lp-folder-tree__manage">
                            <summary>Metadata fields (used only when &ldquo;Change metadata&rdquo; is applied)</summary>
                            <p class="lp-field__hint">Leave a field blank to leave that field unchanged on every selected file.</p>
                            <p class="lp-field">
                                <label for="bulk-alt-text">Alt text</label>
                                <input type="text" id="bulk-alt-text" name="bulk_alt_text">
                            </p>
                            <p class="lp-field">
                                <label for="bulk-caption">Caption</label>
                                <textarea id="bulk-caption" name="bulk_caption" rows="2"></textarea>
                            </p>
                            <p class="lp-field">
                                <label for="bulk-description">Description</label>
                                <textarea id="bulk-description" name="bulk_description" rows="3"></textarea>
                            </p>
                            <p class="lp-field">
                                <label for="bulk-notes">Notes</label>
                                <textarea id="bulk-notes" name="bulk_notes" rows="3"></textarea>
                            </p>
                        </details>
                    </form>

                    <?php render_pagination(['page' => $page, 'totalPages' => $totalPages], 'Media pagination'); ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
<?php endif; ?>
