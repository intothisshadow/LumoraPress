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
                <form method="post" action="<?= esc_url(admin_url('media/media')) ?>" class="lp-admin__inline-form">
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
                <form method="post" action="<?= esc_url(admin_url('media/media') . '?action=edit&id=' . (int) $editingMedia['id']) ?>" onsubmit="return confirm('Delete this file anyway? This cannot be undone.');">
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
    $view = (string) ($_GET['view'] ?? '');

    /*
     * LP-006's built-in views (Unused / Most Downloaded / Recently
     * Downloaded / Never Downloaded) — unpaginated, capped lists rather
     * than folded into the regular filter+pagination query() above, the
     * same "quick, capped listing" precedent already used elsewhere for
     * picker dropdowns (e.g. settings/general.php's OG image select,
     * `$kernel->media->query(['type' => 'image'], 500, 0)`). "Unused"
     * specifically only knows about the structured references
     * MediaUsageChecker checks (featured images, site logo/favicon/
     * default OG image) — see that class's docblock — not media embedded
     * in post/page body content.
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
    } else {
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
    }

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
            echo '<a href="' . esc_url(admin_url('media/media') . '?folder=' . $folder->id) . '">' . esc_html($folder->name) . '</a> ';

            echo '<details class="lp-folder-tree__manage"><summary>Manage</summary>';
            echo '<form method="post" action="' . esc_url(admin_url('media/media')) . '">';
            echo Csrf::field('rename_folder_' . $folder->id);
            echo '<input type="hidden" name="form" value="rename_folder">';
            echo '<input type="hidden" name="id" value="' . (int) $folder->id . '">';
            echo '<input type="hidden" name="parent_id" value="' . (int) ($folder->parentId ?? 0) . '">';
            echo '<input type="hidden" name="current_folder" value="' . esc_attr($currentFolderRaw) . '">';
            echo '<input type="text" name="name" value="' . esc_attr($folder->name) . '">';
            echo '<button type="submit" class="lp-button">Rename</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('media/media')) . '" onsubmit="return confirm(\'Delete this folder? It must be empty.\');">';
            echo Csrf::field('delete_folder_' . $folder->id);
            echo '<input type="hidden" name="form" value="delete_folder">';
            echo '<input type="hidden" name="id" value="' . (int) $folder->id . '">';
            echo '<input type="hidden" name="current_folder" value="' . esc_attr($currentFolderRaw) . '">';
            echo '<button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>';
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
            <h2>Views</h2>
            <ul class="lp-folder-tree">
                <?php foreach ([
                    '' => 'All Files',
                    'unused' => 'Unused Media',
                    'most_downloaded' => 'Most Downloaded',
                    'recently_downloaded' => 'Recently Downloaded',
                    'never_downloaded' => 'Never Downloaded',
                ] as $viewValue => $viewLabel): ?>
                    <li class="lp-folder-tree__item<?= $view === $viewValue ? ' is-active' : '' ?>">
                        <a href="<?= esc_url(admin_url('media/media') . ($viewValue !== '' ? '?view=' . $viewValue : '')) ?>"><?= esc_html($viewLabel) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h2>Folders</h2>
            <ul class="lp-folder-tree">
                <li class="lp-folder-tree__item<?= $currentFolderRaw === '' ? ' is-active' : '' ?>">
                    <a href="<?= esc_url(admin_url('media/media')) ?>">All Files</a>
                </li>
                <li class="lp-folder-tree__item<?= $currentFolderRaw === '0' ? ' is-active' : '' ?>">
                    <a href="<?= esc_url(admin_url('media/media')) ?>?folder=0">General Uploads</a>
                </li>
            </ul>
            <?php $renderFolderTree($allFolders); ?>

            <details class="lp-folder-tree__manage">
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
                    <button type="submit" class="lp-button">Create</button>
                </form>
            </details>
        </aside>

        <div class="lp-media-manager__main">
            <section class="lp-admin__panel">
                <h2>Search &amp; Filter</h2>
                <form method="get" action="<?= esc_url(admin_url('media/media')) ?>">
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
                    <form method="post" action="<?= esc_url(admin_url('media/media')) ?>">
                        <?= Csrf::field('bulk_action') ?>
                        <input type="hidden" name="form" value="bulk_action">
                        <input type="hidden" name="current_folder" value="<?= esc_attr($currentFolderRaw) ?>">

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
                                        <?php if (array_key_exists('downloads', $item)): ?>
                                            <span class="lp-status-badge"><?= (int) $item['downloads'] ?> download<?= (int) $item['downloads'] === 1 ? '' : 's' ?></span>
                                        <?php endif; ?>
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
