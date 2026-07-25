<?php
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
$importService = $kernel->mediaImport;
$allowedImportDirectories = (array) (json_decode((string) $kernel->config->option('media_import_allowed_directories', '[]'), true) ?: []);

$error = null;
$warnUsages = null;
$scanResults = null;

/**
 * A batch import's progress/tallies/pending path list live in a small
 * JSON file under storage/cache (never web-accessible, and already a
 * required-writable directory per RequirementsCheck) rather than a new
 * database table or a huge hidden-field path list threaded through every
 * "Continue" form — the same "no queue infra, batch-per-request" shape
 * LP-001's bulk thumbnail regeneration uses, just needing somewhere to
 * park state between one batch's redirect and the next.
 */
$importCachePath = static fn (string $importToken): string => rtrim(LUMORA_ROOT, '/') . '/storage/cache/media-import-' . $importToken . '.json';

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
    $backToList = admin_url('media') . ($currentFolderParam !== '' ? '?folder=' . urlencode($currentFolderParam) : '');

    if ($form === 'create_folder' && Csrf::verify('create_folder', $token)) {
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
    } elseif ($form === 'upload' && Csrf::verify('upload', $token)) {
        $folderId = (int) ($_POST['folder_id'] ?? 0);

        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a file to upload.';
        } else {
            try {
                $uploaded = $mediaService->upload($_FILES['file'], $currentUser->id, $folderId > 0 ? $folderId : null);
                $thumbnailService->generate($uploaded);
                header('Location: ' . admin_url('media') . '?action=edit&id=' . $uploaded['id'] . '&saved=1');
                exit;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
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

        header('Location: ' . admin_url('media') . '?action=edit&id=' . $id . '&saved=1');
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
            header('Location: ' . admin_url('media'));
            exit;
        }
    } elseif ($form === 'regenerate_thumbnails' && Csrf::verify('regenerate_thumbnails', $token)) {
        $id = (int) ($_POST['id'] ?? 0);
        $thumbnailService->regenerate($id);
        header('Location: ' . admin_url('media') . '?action=edit&id=' . $id . '&saved=1');
        exit;
    } elseif ($form === 'cleanup_orphaned_thumbnails' && Csrf::verify('cleanup_orphaned_thumbnails', $token)) {
        $removed = $thumbnailService->deleteOrphaned();
        header('Location: ' . admin_url('media') . '?orphans_removed=' . $removed);
        exit;
    } elseif ($form === 'bulk_regenerate_thumbnails' && Csrf::verify('bulk_regenerate_thumbnails', $token)) {
        $missingOnly = ($_POST['missing_only'] ?? '') === '1';
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $batch = $thumbnailService->queueForBulkRegeneration($missingOnly, $offset, 10);

        $query = http_build_query([
            'thumb_progress' => $offset + $batch['processed'],
            'thumb_total' => $batch['total'],
            'thumb_done' => $batch['done'] ? '1' : '0',
            'missing_only' => $missingOnly ? '1' : '0',
            'next_offset' => $offset + 10,
        ]);
        header('Location: ' . admin_url('media') . '?' . $query);
        exit;
    } elseif ($form === 'scan_import' && Csrf::verify('scan_import', $token)) {
        $importDirectory = trim((string) ($_POST['directory'] ?? ''));

        if (!in_array($importDirectory, $allowedImportDirectories, true)) {
            $error = 'Choose one of the configured allowed import directories.';
        } else {
            try {
                $scanResults = [
                    'directory' => $importDirectory,
                    'recursive' => ($_POST['recursive'] ?? '') === '1',
                    'folderId' => (int) ($_POST['folder_id'] ?? 0),
                    'mirrorStructure' => ($_POST['mirror_structure'] ?? '') === '1',
                    'useFileModifiedDate' => ($_POST['use_file_modified_date'] ?? '') === '1',
                    'files' => $importService->scan($importDirectory, ($_POST['recursive'] ?? '') === '1', $allowedImportDirectories),
                ];
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'start_import' && Csrf::verify('start_import', $token)) {
        $paths = array_filter(is_array($_POST['paths'] ?? null) ? array_map('strval', $_POST['paths']) : []);
        $importDirectory = trim((string) ($_POST['directory'] ?? ''));

        if ($paths === [] || !in_array($importDirectory, $allowedImportDirectories, true)) {
            $error = 'Select at least one file to import.';
        } else {
            $kernel->config->setOption('media_import_last_folder_id', (string) ($_POST['folder_id'] ?? 0));
            $importToken = bin2hex(random_bytes(16));
            $state = [
                'paths' => array_values($paths),
                'directory' => $importDirectory,
                'folderId' => (int) ($_POST['folder_id'] ?? 0) ?: null,
                'mirrorStructure' => ($_POST['mirror_structure'] ?? '') === '1',
                'useFileModifiedDate' => ($_POST['use_file_modified_date'] ?? '') === '1',
                'offset' => 0,
                'total' => count($paths),
                'done' => false,
                'imported' => 0,
                'duplicate' => 0,
                'failed' => 0,
                'failures' => [],
            ];

            file_put_contents($importCachePath($importToken), json_encode($state));
            header('Location: ' . admin_url('media') . '?action=import&import_token=' . $importToken);
            exit;
        }
    } elseif ($form === 'continue_import' && Csrf::verify('continue_import', $token)) {
        $importToken = (string) ($_POST['import_token'] ?? '');

        if (preg_match('/^[a-f0-9]{32}$/', $importToken) === 1 && is_file($importCachePath($importToken))) {
            $state = json_decode((string) file_get_contents($importCachePath($importToken)), true);

            if (is_array($state)) {
                $batch = $importService->importBatch(
                    $state['paths'],
                    $currentUser->id,
                    $state['folderId'],
                    $state['mirrorStructure'],
                    $state['useFileModifiedDate'],
                    $state['directory'],
                    $allowedImportDirectories,
                    $state['offset'],
                    10,
                );

                foreach ($batch['results'] as $result) {
                    if ($result['status'] === 'imported') {
                        $state['imported']++;
                    } elseif ($result['status'] === 'duplicate') {
                        $state['duplicate']++;
                    } else {
                        $state['failed']++;
                        $state['failures'][] = basename((string) $result['path']) . ': ' . (string) $result['reason'];
                    }
                }

                $state['offset'] += 10;
                $state['done'] = $batch['done'];
                $state['total'] = $batch['total'];

                file_put_contents($importCachePath($importToken), json_encode($state));
            }
        }

        header('Location: ' . admin_url('media') . '?action=import&import_token=' . $importToken);
        exit;
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
        }
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$allFolders = $folderService->listAll();
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

<?php if (isset($_GET['orphans_removed'])): ?>
    <div class="lp-alert lp-alert--success">Removed <?= (int) $_GET['orphans_removed'] ?> orphaned thumbnail(s).</div>
<?php endif; ?>

<?php if (isset($_GET['thumb_progress'])): ?>
    <?php
    $thumbProgress = (int) $_GET['thumb_progress'];
    $thumbTotal = (int) ($_GET['thumb_total'] ?? 0);
    $thumbDone = ($_GET['thumb_done'] ?? '0') === '1';
    $thumbMissingOnly = ($_GET['missing_only'] ?? '0') === '1';
    $thumbNextOffset = (int) ($_GET['next_offset'] ?? 0);
    $thumbPercent = $thumbTotal > 0 ? (int) round(min(100, $thumbProgress / $thumbTotal * 100)) : 100;
    ?>
    <div class="lp-alert lp-alert--success">
        <p>Regenerating thumbnails: <?= $thumbProgress ?> of <?= $thumbTotal ?> processed.</p>
        <div class="lp-thumbnails__progress" role="progressbar" aria-valuenow="<?= $thumbPercent ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="lp-thumbnails__progress-bar" style="width: <?= $thumbPercent ?>%;"></div>
        </div>
        <?php if (!$thumbDone): ?>
            <form method="post" action="<?= esc_url(admin_url('media')) ?>" id="thumb-bulk-continue">
                <?= Csrf::field('bulk_regenerate_thumbnails') ?>
                <input type="hidden" name="form" value="bulk_regenerate_thumbnails">
                <input type="hidden" name="missing_only" value="<?= $thumbMissingOnly ? '1' : '0' ?>">
                <input type="hidden" name="offset" value="<?= $thumbNextOffset ?>">
                <button type="submit" class="lp-button">Continue</button>
            </form>
        <?php else: ?>
            <p>Done.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($action === 'edit'): ?>
    <?php
    $editingId = (int) ($_GET['id'] ?? 0);
    $editingMedia = $editingId > 0 ? $mediaService->find($editingId) : null;

    if ($editingMedia === null) {
        header('Location: ' . admin_url('media'));
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
        </p>

        <?php if (str_starts_with((string) $editingMedia['mime_type'], 'image/')): ?>
            <?php MediaViewer::markUsed(); ?>
            <div class="lp-gallery">
                <a
                    href="<?= esc_url($mediaService->url($editingMedia)) ?>"
                    <?php if ($editingMedia['width'] !== null): ?>
                        data-pswp-width="<?= (int) $editingMedia['width'] ?>"
                        data-pswp-height="<?= (int) $editingMedia['height'] ?>"
                    <?php endif; ?>
                    <?php if (($editingMedia['caption'] ?? '') !== '' || ($editingMedia['alt_text'] ?? '') !== ''): ?>
                        data-pswp-caption="<?= esc_attr((string) ($editingMedia['caption'] ?: $editingMedia['alt_text'])) ?>"
                    <?php endif; ?>
                >
                    <img class="lp-media-edit__preview" src="<?= esc_url($mediaService->url($editingMedia)) ?>" alt="">
                </a>
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
                <form method="post" action="<?= esc_url(admin_url('media')) ?>" class="lp-admin__inline-form">
                    <?= Csrf::field('regenerate_thumbnails') ?>
                    <input type="hidden" name="form" value="regenerate_thumbnails">
                    <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                    <button type="submit" class="lp-button">Regenerate thumbnails</button>
                </form>
            </div>
        <?php elseif (str_starts_with((string) $editingMedia['mime_type'], 'video/')): ?>
            <video class="lp-media-edit__video" src="<?= esc_url($mediaService->url($editingMedia)) ?>" controls preload="metadata"></video>
        <?php elseif (str_starts_with((string) $editingMedia['mime_type'], 'audio/')): ?>
            <audio class="lp-media-edit__audio" src="<?= esc_url($mediaService->url($editingMedia)) ?>" controls preload="metadata"></audio>
        <?php elseif ((string) $editingMedia['mime_type'] === 'application/pdf'): ?>
            <iframe class="lp-media-edit__pdf" src="<?= esc_url($mediaService->url($editingMedia)) ?>" title="<?= esc_attr((string) $editingMedia['file_name']) ?>"></iframe>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('media')) ?>">
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
            <a class="lp-button" href="<?= esc_url(admin_url('media')) ?>">Back to Media Manager</a>
        </form>

        <?php if ($warnUsages !== null): ?>
            <div class="lp-alert lp-alert--error">
                <p>This file is currently in use and deleting it will break:</p>
                <ul>
                    <?php foreach ($warnUsages as $usage): ?>
                        <li><?= esc_html($usage) ?></li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?= esc_url(admin_url('media') . '?action=edit&id=' . (int) $editingMedia['id']) ?>" onsubmit="return confirm('Delete this file anyway? This cannot be undone.');">
                    <?= Csrf::field('delete_file') ?>
                    <input type="hidden" name="form" value="delete_file">
                    <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                    <input type="hidden" name="confirm_delete" value="1">
                    <button type="submit" class="lp-button lp-button--danger">Delete Anyway</button>
                </form>
            </div>
        <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('media') . '?action=edit&id=' . (int) $editingMedia['id']) ?>" class="lp-admin__inline-form">
                <?= Csrf::field('delete_file') ?>
                <input type="hidden" name="form" value="delete_file">
                <input type="hidden" name="id" value="<?= (int) $editingMedia['id'] ?>">
                <button type="submit" class="lp-button lp-button--link">Delete</button>
            </form>
        <?php endif; ?>
    </section>
<?php elseif ($action === 'import'): ?>
    <?php
    $importFolderOptions = $buildFolderOptions($allFolders, []);
    $importTokenParam = is_string($_GET['import_token'] ?? null) ? $_GET['import_token'] : '';
    $importState = null;

    if (preg_match('/^[a-f0-9]{32}$/', $importTokenParam) === 1 && is_file($importCachePath($importTokenParam))) {
        $importState = json_decode((string) file_get_contents($importCachePath($importTokenParam)), true);

        if (is_array($importState) && ($importState['done'] ?? false) === true) {
            unlink($importCachePath($importTokenParam));
        }
    }
    ?>
    <section class="lp-admin__panel">
        <h2>Import from Server</h2>

        <?php if ($allowedImportDirectories === []): ?>
            <p class="lp-admin__widget-placeholder">
                No import directories are configured. An administrator can add one or more absolute server paths on the
                <a href="<?= esc_url(admin_url('settings')) ?>">Settings</a> page under "Media Import".
            </p>
        <?php elseif (is_array($importState)): ?>
            <?php
            $importPercent = ((int) $importState['total']) > 0
                ? (int) round(min(100, ($importState['offset'] / $importState['total']) * 100))
                : 100;
            ?>
            <p>
                Imported <?= (int) $importState['imported'] ?>, skipped <?= (int) $importState['duplicate'] ?> duplicate(s),
                failed <?= (int) $importState['failed'] ?> &mdash; <?= min((int) $importState['offset'], (int) $importState['total']) ?> of <?= (int) $importState['total'] ?> processed.
            </p>
            <div class="lp-thumbnails__progress" role="progressbar" aria-valuenow="<?= $importPercent ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="lp-thumbnails__progress-bar" style="width: <?= $importPercent ?>%;"></div>
            </div>
            <?php if (($importState['done'] ?? false) !== true): ?>
                <form method="post" action="<?= esc_url(admin_url('media')) ?>" id="import-bulk-continue">
                    <?= Csrf::field('continue_import') ?>
                    <input type="hidden" name="form" value="continue_import">
                    <input type="hidden" name="import_token" value="<?= esc_attr($importTokenParam) ?>">
                    <button type="submit" class="lp-button">Continue</button>
                </form>
            <?php else: ?>
                <p>Done.</p>
                <?php if (($importState['failures'] ?? []) !== []): ?>
                    <ul>
                        <?php foreach ($importState['failures'] as $failure): ?>
                            <li><?= esc_html((string) $failure) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a class="lp-button" href="<?= esc_url(admin_url('media')) ?>?action=import">Import more files</a>
            <?php endif; ?>
        <?php elseif ($scanResults !== null): ?>
            <?php if ($scanResults['files'] === []): ?>
                <p class="lp-admin__widget-placeholder">No importable files were found in that directory.</p>
                <a class="lp-button" href="<?= esc_url(admin_url('media')) ?>?action=import">Back</a>
            <?php else: ?>
                <form method="post" action="<?= esc_url(admin_url('media')) ?>">
                    <?= Csrf::field('start_import') ?>
                    <input type="hidden" name="form" value="start_import">
                    <input type="hidden" name="directory" value="<?= esc_attr($scanResults['directory']) ?>">
                    <input type="hidden" name="folder_id" value="<?= (int) $scanResults['folderId'] ?>">
                    <input type="hidden" name="mirror_structure" value="<?= $scanResults['mirrorStructure'] ? '1' : '0' ?>">
                    <input type="hidden" name="use_file_modified_date" value="<?= $scanResults['useFileModifiedDate'] ? '1' : '0' ?>">

                    <p><?= count($scanResults['files']) ?> file(s) found.</p>

                    <ul class="lp-import-scan__list">
                        <?php foreach ($scanResults['files'] as $file): ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="paths[]" value="<?= esc_attr($file['path']) ?>" <?= $file['isDuplicate'] ? '' : 'checked' ?>>
                                    <?= esc_html($file['relativePath']) ?>
                                    (<?= esc_html(number_format($file['size'] / 1024, 1)) ?> KB, <?= esc_html($file['modifiedAt']) ?>)
                                    <?php if ($file['isDuplicate']): ?>
                                        <span class="lp-status-badge">Already in Media Manager</span>
                                    <?php endif; ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <button type="submit" class="lp-button lp-button--primary">Import selected</button>
                    <a class="lp-button" href="<?= esc_url(admin_url('media')) ?>?action=import">Cancel</a>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('media')) ?>?action=import">
                <?= Csrf::field('scan_import') ?>
                <input type="hidden" name="form" value="scan_import">

                <p class="lp-field">
                    <label for="import-directory">Directory to scan</label>
                    <select id="import-directory" name="directory">
                        <?php foreach ($allowedImportDirectories as $allowedDirectory): ?>
                            <option value="<?= esc_attr($allowedDirectory) ?>"><?= esc_html($allowedDirectory) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <label class="lp-field--checkbox">
                    <input type="checkbox" name="recursive" value="1" checked>
                    Scan subdirectories recursively
                </label>

                <p class="lp-field">
                    <label for="import-folder">Import into folder</label>
                    <?php $lastFolderId = (int) $kernel->config->option('media_import_last_folder_id', '0'); ?>
                    <select id="import-folder" name="folder_id">
                        <option value="0" <?= $lastFolderId === 0 ? 'selected' : '' ?>>(General Uploads)</option>
                        <?php foreach ($importFolderOptions as $option): ?>
                            <option value="<?= (int) $option['id'] ?>" <?= $lastFolderId === $option['id'] ? 'selected' : '' ?>><?= esc_html($option['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <label class="lp-field--checkbox">
                    <input type="checkbox" name="mirror_structure" value="1">
                    Mirror the scanned directory structure as subfolders
                </label>

                <label class="lp-field--checkbox">
                    <input type="checkbox" name="use_file_modified_date" value="1">
                    Use each file's modification time as its upload date
                </label>

                <button type="submit" class="lp-button lp-button--primary">Scan</button>
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

    $filters = [
        'term' => $term,
        'type' => $type,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
    ];

    if ($currentFolderRaw === '0') {
        $filters['unassignedOnly'] = true;
    } elseif ($currentFolderId !== null) {
        $filters['folderIds'] = [$currentFolderId, ...$folderService->descendantIds($currentFolderId)];
    }

    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $perPage = 40;
    $result = $mediaService->query($filters, $perPage, ($page - 1) * $perPage);
    $items = $result['items'];
    $totalPages = (int) max(1, ceil($result['total'] / $perPage));

    /**
     * @param array<int, Folder> $allFolders
     */
    $renderFolderTree = function (array $allFolders, ?int $parentId = null) use (&$renderFolderTree, $currentFolderRaw): void {
        $children = array_values(array_filter($allFolders, static fn (Folder $f): bool => $f->parentId === $parentId));

        if ($children === []) {
            return;
        }

        echo '<ul class="lp-folder-tree' . ($parentId === null ? '' : '__children') . '">';

        foreach ($children as $folder) {
            $isActive = $currentFolderRaw === (string) $folder->id;
            echo '<li class="lp-folder-tree__item' . ($isActive ? ' is-active' : '') . '">';
            echo '<a href="' . esc_url(admin_url('media') . '?folder=' . $folder->id) . '">' . esc_html($folder->name) . '</a> ';

            echo '<details class="lp-folder-tree__manage"><summary>Manage</summary>';
            echo '<form method="post" action="' . esc_url(admin_url('media')) . '">';
            echo Csrf::field('rename_folder_' . $folder->id);
            echo '<input type="hidden" name="form" value="rename_folder">';
            echo '<input type="hidden" name="id" value="' . (int) $folder->id . '">';
            echo '<input type="hidden" name="parent_id" value="' . (int) ($folder->parentId ?? 0) . '">';
            echo '<input type="hidden" name="current_folder" value="' . esc_attr($currentFolderRaw) . '">';
            echo '<input type="text" name="name" value="' . esc_attr($folder->name) . '">';
            echo '<button type="submit" class="lp-button">Rename</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('media')) . '" onsubmit="return confirm(\'Delete this folder? It must be empty.\');">';
            echo Csrf::field('delete_folder_' . $folder->id);
            echo '<input type="hidden" name="form" value="delete_folder">';
            echo '<input type="hidden" name="id" value="' . (int) $folder->id . '">';
            echo '<input type="hidden" name="current_folder" value="' . esc_attr($currentFolderRaw) . '">';
            echo '<button type="submit" class="lp-button lp-button--link">Delete</button>';
            echo '</form>';
            echo '</details>';

            $renderFolderTree($allFolders, $folder->id);
            echo '</li>';
        }

        echo '</ul>';
    };
    ?>

    <div class="lp-media-manager">
        <aside class="lp-media-manager__sidebar">
            <h2>Folders</h2>
            <ul class="lp-folder-tree">
                <li class="lp-folder-tree__item<?= $currentFolderRaw === '' ? ' is-active' : '' ?>">
                    <a href="<?= esc_url(admin_url('media')) ?>">All Files</a>
                </li>
                <li class="lp-folder-tree__item<?= $currentFolderRaw === '0' ? ' is-active' : '' ?>">
                    <a href="<?= esc_url(admin_url('media')) ?>?folder=0">General Uploads</a>
                </li>
            </ul>
            <?php $renderFolderTree($allFolders); ?>

            <details class="lp-folder-tree__manage">
                <summary>New Folder</summary>
                <form method="post" action="<?= esc_url(admin_url('media')) ?>">
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
                    <button type="submit" class="lp-button">Create</button>
                </form>
            </details>
        </aside>

        <div class="lp-media-manager__main">
            <section class="lp-admin__panel">
                <h2>Upload</h2>
                <form method="post" action="<?= esc_url(admin_url('media')) ?>" enctype="multipart/form-data">
                    <?= Csrf::field('upload') ?>
                    <input type="hidden" name="form" value="upload">
                    <input type="hidden" name="folder_id" value="<?= $currentFolderId !== null ? (int) $currentFolderId : 0 ?>">
                    <p class="lp-field">
                        <label for="media-file">File</label>
                        <input type="file" id="media-file" name="file" required>
                        <span class="lp-field__hint">Uploads into <?= $currentFolderId !== null ? esc_html($folderService->findById($currentFolderId)?->name ?? 'the current folder') : 'General Uploads' ?>.</span>
                    </p>
                    <button type="submit" class="lp-button lp-button--primary">Upload</button>
                </form>
                <p><a class="lp-button" href="<?= esc_url(admin_url('media')) ?>?action=import">Import from Server&hellip;</a></p>
            </section>

            <section class="lp-admin__panel">
                <h2>Thumbnails</h2>
                <form method="post" action="<?= esc_url(admin_url('media')) ?>" class="lp-admin__inline-form">
                    <?= Csrf::field('bulk_regenerate_thumbnails') ?>
                    <input type="hidden" name="form" value="bulk_regenerate_thumbnails">
                    <input type="hidden" name="offset" value="0">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="missing_only" value="1" checked>
                        Only generate missing thumbnails
                    </label>
                    <button type="submit" class="lp-button">Bulk regenerate thumbnails</button>
                </form>
                <form method="post" action="<?= esc_url(admin_url('media')) ?>" class="lp-admin__inline-form">
                    <?= Csrf::field('cleanup_orphaned_thumbnails') ?>
                    <input type="hidden" name="form" value="cleanup_orphaned_thumbnails">
                    <button type="submit" class="lp-button">Clean up orphaned thumbnails</button>
                </form>
            </section>

            <section class="lp-admin__panel">
                <h2>Search &amp; Filter</h2>
                <form method="get" action="<?= esc_url(admin_url('media')) ?>">
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
                    <button type="submit" class="lp-button">Filter</button>
                </form>
            </section>

            <section class="lp-admin__panel">
                <?php if ($items === []): ?>
                    <p class="lp-admin__widget-placeholder">No files found.</p>
                <?php else: ?>
                    <form method="post" action="<?= esc_url(admin_url('media')) ?>">
                        <?= Csrf::field('bulk_action') ?>
                        <input type="hidden" name="form" value="bulk_action">
                        <input type="hidden" name="current_folder" value="<?= esc_attr($currentFolderRaw) ?>">

                        <div class="lp-media-grid">
                            <?php foreach ($items as $item): ?>
                                <div class="lp-media-grid__item">
                                    <label class="lp-media-grid__select">
                                        <input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>">
                                    </label>
                                    <a href="<?= esc_url(admin_url('media')) ?>?action=edit&id=<?= (int) $item['id'] ?>">
                                        <?php if (str_starts_with((string) $item['mime_type'], 'image/')): ?>
                                            <img class="lp-media-grid__thumb" src="<?= esc_url((string) ($thumbnailService->url($item, 'small') ?? $mediaService->url($item))) ?>" alt="<?= esc_attr((string) ($item['alt_text'] ?? '')) ?>">
                                        <?php else: ?>
                                            <span class="lp-media-grid__thumb lp-media-grid__thumb--file" aria-hidden="true"><?= esc_html(strtoupper($mediaService->typeCategory((string) $item['mime_type']))) ?></span>
                                        <?php endif; ?>
                                        <span class="lp-media-grid__name"><?= esc_html((string) $item['file_name']) ?></span>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="lp-media-manager__bulk-bar">
                            <select name="bulk_action_type">
                                <option value="move">Move selected to&hellip;</option>
                                <option value="delete">Delete selected</option>
                            </select>
                            <select name="target_folder">
                                <option value="0">(General Uploads)</option>
                                <?php foreach ($buildFolderOptions($allFolders, []) as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"><?= esc_html($option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="lp-button">Apply</button>
                        </div>
                    </form>

                    <?php render_pagination(['page' => $page, 'totalPages' => $totalPages], 'Media pagination'); ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
<?php endif; ?>
