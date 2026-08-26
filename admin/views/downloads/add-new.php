<?php

/**
 * The admin Downloads > Add New screen: add a new download, or edit an existing one via ?id= (LPP-009).
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

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\Downloads\DownloadType;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * DownloadService's class is guaranteed to already be loaded —
 * PluginManager::loadActive() required content/plugins/downloads/downloads.php
 * earlier this same request, in include/bootstrap.php (this view is
 * only ever reachable while the plugin is active — see admin/index.php's
 * $downloadsActive-gated 'downloads' $menu entry).
 */
$downloads = new DownloadService(
    $kernel->database,
    (string) $kernel->config->get('table_prefix', 'lp_'),
    $kernel->media,
    $kernel->redirects,
    $kernel->folders,
    $kernel->thumbnails,
);

/*
 * Shared shape for one Media row in the "Insert Image" picker (LP-115)
 * — see PostsController::buildEditorPickerItem()'s identical docblock.
 * Downloads has no controller of its own (same as pages/new.php), so
 * this stays inline here, matching editor_upload/convert_content's
 * existing pattern below.
 *
 * @param array<string, mixed> $item
 * @param array<int, array<string, mixed>> $thumbnailsForItem
 * @return array<string, mixed>
 */
$buildEditorPickerItem = static function (array $item, array $thumbnailsForItem) use ($kernel): array {
    $sizes = [
        'full' => [
            'url' => $kernel->media->url($item),
            'width' => (int) ($item['width'] ?? 0),
            'height' => (int) ($item['height'] ?? 0),
        ],
    ];

    foreach ($thumbnailsForItem as $thumbnail) {
        $sizes[(string) $thumbnail['size_name']] = [
            'url' => $kernel->thumbnails->url($item, (string) $thumbnail['size_name']),
            'width' => (int) $thumbnail['width'],
            'height' => (int) $thumbnail['height'],
        ];
    }

    return [
        'id' => (int) $item['id'],
        'url' => $kernel->media->url($item),
        'name' => (string) $item['file_name'],
        'alt' => (string) ($item['alt_text'] ?? ''),
        'folderId' => $item['folder_id'] !== null ? (int) $item['folder_id'] : null,
        'sizes' => $sizes,
    ];
};

/*
 * The Description field's editor image upload and format-switch
 * conversion (LPP-010) — the same shared editor component Posts/Pages
 * use (see admin/assets/js/content-editor.js), wired the same way
 * pages/new.php wires it: JSON sub-actions on this same page rather
 * than their own admin route, gated on 'upload_files' since that's the
 * capability this plugin's whole admin area is already gated behind
 * (see admin/index.php's $downloadsActive-gated 'downloads' $menu
 * entry) rather than the Posts-specific 'edit_posts'/'upload_files'
 * split PostsController/pages/new.php use.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'editor_upload') {
    // admin/index.php's ob_start() buffer already holds layout-header.php's
    // HTML shell by the time this runs — discard it before sending a
    // JSON response, or that buffered HTML would still flush to the
    // client ahead of/around this JSON on exit.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('upload_files') || !Csrf::verify('editor_upload', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['error' => 'Upload failed.', 'csrfToken' => Csrf::token('editor_upload')]);
        exit;
    }

    try {
        $uploaded = $kernel->media->upload($_FILES['file'], $currentUser->id);
        // Matches admin/views/media/upload.php's own multi-upload flow —
        // without this, an image uploaded straight from the editor
        // (LP-115's "Upload New" picker step) would report no size
        // options at all in $buildEditorPickerItem() above.
        $kernel->thumbnails->generate($uploaded);

        echo json_encode([
            'data' => ['filePath' => $kernel->media->url($uploaded)],
            'url' => $kernel->media->url($uploaded),
            'item' => $buildEditorPickerItem($uploaded, $kernel->thumbnails->thumbnailsFor((int) $uploaded['id'])),
            // Csrf::verify() is single-use — a second image upload without
            // a full page reload would otherwise fail CSRF verification
            // against the already-consumed token from the initial page
            // load. content-editor.js writes this fresh token back into
            // data-upload-csrf for the next call.
            'csrfToken' => Csrf::token('editor_upload'),
        ]);
    } catch (\Throwable $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage(), 'csrfToken' => Csrf::token('editor_upload')]);
    }

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'media_picker_query') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('upload_files') || !Csrf::verify('media_picker_query', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    $term = trim((string) ($_POST['term'] ?? ''));
    $folderId = (int) ($_POST['folder_id'] ?? 0);
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $perPage = 40;

    $filters = ['type' => 'image'];

    if ($term !== '') {
        $filters['term'] = $term;
    }

    if ($folderId > 0) {
        $filters['folderIds'] = [$folderId];
    }

    $result = $kernel->media->query($filters, $perPage, ($page - 1) * $perPage);
    // One batched query for every item's thumbnails rather than one per
    // item (LP-075's thumbnailsForMany() precedent).
    $thumbnailsByMediaId = $kernel->thumbnails->thumbnailsForMany(
        array_map(static fn (array $item): int => (int) $item['id'], $result['items']),
    );

    echo json_encode([
        'items' => array_map(
            static fn (array $item): array => $buildEditorPickerItem($item, $thumbnailsByMediaId[(int) $item['id']] ?? []),
            $result['items'],
        ),
        'total' => $result['total'],
        'csrfToken' => Csrf::token('media_picker_query'),
    ]);
    exit;
}

/*
 * LPP-012: "Add from server" / "Replace file" — a separate sub-action
 * from media_picker_query above rather than a shared one with a type
 * toggle, deliberately: that one is the Description field's own
 * "Insert Image" picker (content-editor.js, hardcoded to images, with
 * an Attachment Display Settings step none of this makes sense for), a
 * different feature this file also happens to host. A download's file
 * can be anything (a .zip, a .pdf, an image), so this queries every
 * Media item with no type filter at all, and returns a plain
 * {id, name, url, mimeType, typeCategory} per item — no size variants,
 * no display-settings step, just enough for downloads-picker.js to
 * render a filename + type badge and hand back the id it selected.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'download_file_picker_query') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('upload_files') || !Csrf::verify('download_file_picker_query', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    $term = trim((string) ($_POST['term'] ?? ''));
    $folderId = (int) ($_POST['folder_id'] ?? 0);
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $perPage = 40;

    $filters = [];

    if ($term !== '') {
        $filters['term'] = $term;
    }

    if ($folderId > 0) {
        $filters['folderIds'] = [$folderId];
    }

    $result = $kernel->media->query($filters, $perPage, ($page - 1) * $perPage);

    echo json_encode([
        'items' => array_map(
            static fn (array $item): array => [
                'id' => (int) $item['id'],
                'name' => (string) $item['file_name'],
                'url' => $kernel->media->url($item),
                'mimeType' => (string) $item['mime_type'],
                'typeCategory' => $kernel->media->typeCategory((string) $item['mime_type']),
            ],
            $result['items'],
        ),
        'total' => $result['total'],
        'csrfToken' => Csrf::token('download_file_picker_query'),
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'convert_content') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!Csrf::verify('convert_content', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);
        exit;
    }

    $from = ContentFormat::tryFrom((string) ($_POST['from'] ?? ''));
    $to = ContentFormat::tryFrom((string) ($_POST['to'] ?? ''));

    if ($from === null || $to === null) {
        http_response_code(422);
        echo json_encode(['error' => 'Unknown format.']);
        exit;
    }

    echo json_encode(['content' => $kernel->content->convertFormat((string) ($_POST['content'] ?? ''), $from, $to)]);
    exit;
}

$error = null;

// Mirrors admin/views/posts/new.php's convention: one file serves both
// "Add New" and "Edit", switched on ?id= — there is no separate edit.php
// for Downloads either.
$editingDownload = ($_GET['id'] ?? null) !== null ? $downloads->findById((int) $_GET['id']) : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'create_folder' && Csrf::verify('create_folder', $token)) {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            $error = 'A category name is required.';
        } else {
            $kernel->folders->create($name);
            $redirectTarget = admin_url('downloads/add-new') . ($editingDownload !== null ? '?id=' . $editingDownload->id : '');
            header('Location: ' . $redirectTarget);
            exit;
        }
    } elseif ($form === 'create_download' && Csrf::verify('create_download', $token)) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = (string) ($_POST['description'] ?? '');
        $descriptionFormat = ContentFormat::tryFrom((string) ($_POST['description_format'] ?? '')) ?? get_active_editor($currentUser->id);
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        // LPP-012: a third "existing" choice alongside file/url — an
        // already-uploaded Media item, picked via downloads-picker.js
        // rather than uploaded again.
        $typeInput = (string) ($_POST['type'] ?? 'file');
        $type = $typeInput === 'url' ? DownloadType::Url : DownloadType::File;
        $externalUrl = trim((string) ($_POST['external_url'] ?? ''));
        $existingMediaId = (int) ($_POST['existing_media_id'] ?? 0);

        if ($title === '') {
            $error = 'Please enter a title.';
        } elseif ($typeInput === 'existing' && $existingMediaId <= 0) {
            $error = 'Please choose a file from the Media Library.';
        } elseif ($typeInput !== 'existing' && $type === DownloadType::File && (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE)) {
            $error = 'Please choose a file to upload.';
        } elseif ($type === DownloadType::Url && $externalUrl === '') {
            $error = 'Please enter a URL.';
        } else {
            try {
                $createdDownload = $typeInput === 'existing'
                    ? $downloads->createFromExistingMedia(
                        title: $title,
                        description: $description,
                        folderId: $folderId > 0 ? $folderId : null,
                        mediaId: $existingMediaId,
                        descriptionFormat: $descriptionFormat,
                    )
                    : $downloads->create(
                        title: $title,
                        description: $description,
                        folderId: $folderId > 0 ? $folderId : null,
                        type: $type,
                        file: $type === DownloadType::File ? $_FILES['file'] : null,
                        externalUrl: $type === DownloadType::Url ? $externalUrl : null,
                        uploadedByUserId: $currentUser->id,
                        descriptionFormat: $descriptionFormat,
                    );

                // LPP-010: lands back on this same screen's Edit view
                // (rather than the All Downloads list, as before) so a
                // File-typed download's freshly uploaded file preview
                // image is visible immediately after upload — mirrors
                // PostsController::save()'s identical
                // "redirect to the edit screen for the id just saved"
                // convention.
                header('Location: ' . admin_url('downloads/add-new') . '?id=' . $createdDownload->id . '&saved=1');
                exit;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'update_download') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('update_download_' . $id, $token)) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = (string) ($_POST['description'] ?? '');
            $descriptionFormat = ContentFormat::tryFrom((string) ($_POST['description_format'] ?? '')) ?? get_active_editor($currentUser->id);
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $externalUrl = trim((string) ($_POST['external_url'] ?? ''));

            if ($title === '') {
                $error = 'Please enter a title.';
            } else {
                /*
                 * LPP-012: replacing a File-typed download's file — either
                 * a brand-new upload (a fresh Media row, created here the
                 * same way create()'s own File branch does) or an
                 * already-uploaded one picked via downloads-picker.js.
                 * An actual uploaded $_FILES entry always wins over a
                 * picked existing_media_id if a form somehow submitted
                 * both (shouldn't happen — the view only ever shows one
                 * at a time being filled in — but this is the more
                 * conservative "the file the admin most recently
                 * interacted with" choice).
                 */
                if ($editingDownload !== null && $editingDownload->type === DownloadType::File) {
                    if (isset($_FILES['replace_file']) && $_FILES['replace_file']['error'] === UPLOAD_ERR_OK) {
                        $uploadedReplacement = $kernel->media->upload($_FILES['replace_file'], $currentUser->id, $folderId > 0 ? $folderId : null);
                        $kernel->thumbnails->generate($uploadedReplacement);
                        // Freshly uploaded specifically for this download
                        // — owned (the default), so a later replace/delete
                        // is free to clean it up.
                        $downloads->replaceFile($id, (int) $uploadedReplacement['id']);
                    } else {
                        $replaceMediaId = (int) ($_POST['replace_media_id'] ?? 0);

                        if ($replaceMediaId > 0) {
                            // Picked from the Media Library — not owned,
                            // see replaceFile()'s own docblock for why
                            // that matters.
                            $downloads->replaceFile($id, $replaceMediaId, newMediaOwned: false);
                        }
                    }
                }

                $downloads->update($id, $title, $description, $folderId > 0 ? $folderId : null, $externalUrl !== '' ? $externalUrl : null, $descriptionFormat);

                header('Location: ' . admin_url('downloads/all-downloads') . '?saved=1');
                exit;
            }
        }
    }
}

$allFolders = $kernel->folders->listAll();
// The "Insert Image" media picker's own Folder filter <select> (LP-115)
// — only the (small) folder tree is preloaded; the picker's actual
// image grid is queried on demand via the media_picker_query sub-action
// above. Matches posts/new.php's/pages/new.php's identical variable.
$editorFolderTree = array_map(
    static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
    $kernel->folders->listAllForTree(),
);

/*
 * Preview image (LPP-010, extended for thumbnailMediaId per LPP-004's
 * WordPress import of a download's own featured image) — reuses
 * whatever MediaService/ThumbnailService already resolve for the
 * underlying Media row rather than any new image-processing code, the
 * same way the Media Manager's own list/grid views already show a
 * thumbnail (or, for a non-image file, the same typeCategory() text
 * badge those views fall back to — see admin/views/media/media.php's
 * identical lp-media-list__thumb--file/lp-media-grid__thumb--file
 * convention). thumbnailMediaId — an explicitly chosen representative
 * image, distinct from the download's own file — wins when set, since
 * it's meaningful for either download type (a Url-typed download has
 * no local file to preview from at all, and a File-typed download's
 * own file may not be an image, e.g. a .zip). Falls back to a
 * File-typed download's own file when no thumbnail was set. Null when
 * neither applies, or the referenced Media row has since been deleted
 * out from under it.
 *
 * @var array<string, mixed>|null $previewMedia
 */
$previewMediaId = $editingDownload?->thumbnailMediaId
    ?? ($editingDownload !== null && $editingDownload->type === DownloadType::File ? $editingDownload->mediaId : null);
$previewMedia = $previewMediaId !== null ? $kernel->media->find($previewMediaId) : null;

/*
 * LPP-012: the current file's own filename, for the "Replace file"
 * panel and the "Download source" hint above it — deliberately looked
 * up separately from $previewMedia above, which prefers thumbnailMediaId
 * (a distinct, explicitly-chosen representative image) over the
 * download's own file when both are set, so it isn't reliably "what
 * file this download actually serves."
 */
$currentFileMedia = $editingDownload !== null && $editingDownload->type === DownloadType::File && $editingDownload->mediaId !== null
    ? $kernel->media->find($editingDownload->mediaId)
    : null;
?>
<h1 class="lp-admin__title"><?= $editingDownload !== null ? 'Edit Download' : 'Add New Download' ?></h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Download saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <?php if ($editingDownload !== null): ?>
        <form method="post" action="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $editingDownload->id ?>" enctype="multipart/form-data">
            <?= Csrf::field('update_download_' . $editingDownload->id) ?>
            <input type="hidden" name="form" value="update_download">
            <input type="hidden" name="id" value="<?= (int) $editingDownload->id ?>">

            <p class="lp-field">
                <label for="download-title">Title</label>
                <input type="text" id="download-title" name="title" value="<?= esc_attr($editingDownload->title) ?>" required>
            </p>

            <?php if ($previewMedia !== null): ?>
                <div class="lp-field lp-download-preview">
                    <?php if (str_starts_with((string) $previewMedia['mime_type'], 'image/')): ?>
                        <img
                            class="lp-download-preview__image"
                            src="<?= esc_url((string) ($kernel->thumbnails->url($previewMedia, 'medium') ?? $kernel->media->url($previewMedia))) ?>"
                            alt="<?= esc_attr((string) ($previewMedia['alt_text'] ?? '')) ?>"
                        >
                    <?php else: ?>
                        <span class="lp-download-preview__file" aria-hidden="true"><?= esc_html(strtoupper($kernel->media->typeCategory((string) $previewMedia['mime_type']))) ?></span>
                        <span class="lp-visually-hidden"><?= esc_html($kernel->media->typeCategory((string) $previewMedia['mime_type'])) ?> file</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php $activeDescriptionFormat = $editingDownload->descriptionFormat; ?>
            <p class="lp-field">
                <label for="download-description-format">Editor</label>
                <select id="download-description-format" name="description_format" data-lp-content-format-select>
                    <?php foreach (ContentFormat::cases() as $formatOption): ?>
                        <option value="<?= esc_attr($formatOption->value) ?>" <?= $activeDescriptionFormat === $formatOption ? 'selected' : '' ?>>
                            <?= esc_html($formatOption->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <div
                class="lp-field lp-content-editor"
                data-lp-content-editor
                data-format="<?= esc_attr($activeDescriptionFormat->value) ?>"
                data-upload-url="<?= esc_url(admin_url('downloads/add-new')) ?>"
                data-upload-csrf="<?= esc_attr(Csrf::token('editor_upload')) ?>"
                data-convert-csrf="<?= esc_attr(Csrf::token('convert_content')) ?>"
                data-media-picker-csrf="<?= esc_attr(Csrf::token('media_picker_query')) ?>"
                data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
                data-theme-stylesheet="<?= esc_url(theme_url('style.css')) ?>"
                data-autosave-id="<?= esc_attr('download-' . $editingDownload->id) ?>"
            >
                <label for="download-description">Description</label>
                <textarea id="download-description" name="description" rows="4"><?= esc_html($editingDownload->description) ?></textarea>
            </div>

            <p class="lp-field">
                <label for="download-folder">Category</label>
                <select id="download-folder" name="folder_id">
                    <option value="0">(Uncategorized)</option>
                    <?php foreach ($allFolders as $folder): ?>
                        <option value="<?= (int) $folder->id ?>" <?= $editingDownload->folderId === $folder->id ? 'selected' : '' ?>><?= esc_html($folder->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php /* Changing a download's type (File <-> URL) isn't supported — see DownloadService::update()'s docblock; delete and re-add covers that case. A File download's underlying file itself is replaceable below (LPP-012). */ ?>
            <p class="lp-field">
                <strong>Download source:</strong>
                <span class="lp-field__hint">
                    <?php if ($editingDownload->type === DownloadType::File): ?>
                        Uploaded file<?= $currentFileMedia !== null ? ' — ' . esc_html((string) $currentFileMedia['file_name']) : '' ?>
                    <?php else: ?>
                        External URL
                    <?php endif; ?>
                </span>
            </p>

            <?php if ($editingDownload->type === DownloadType::Url): ?>
                <p class="lp-field">
                    <label for="download-external-url">URL</label>
                    <input type="text" id="download-external-url" name="external_url" value="<?= esc_attr($editingDownload->targetUrl ?? '') ?>">
                </p>
            <?php endif; ?>

            <?php if ($editingDownload->type === DownloadType::File): ?>
                <fieldset class="lp-field">
                    <legend>Replace file</legend>
                    <p class="lp-field__hint">Upload a new file, or choose one already in the Media Library, to replace this download's current file. Leave both blank to keep the current file.</p>
                    <p class="lp-field">
                        <label for="download-replace-file">Upload new file</label>
                        <input type="file" id="download-replace-file" name="replace_file">
                    </p>
                    <div
                        class="lp-download-file-picker"
                        data-lp-download-picker
                        data-picker-url="<?= esc_url(admin_url('downloads/add-new')) ?>"
                        data-picker-csrf="<?= esc_attr(Csrf::token('download_file_picker_query')) ?>"
                        data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
                    >
                        <input type="hidden" name="replace_media_id" data-picker-value>
                        <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Server&hellip;</button>
                        <span class="lp-download-file-picker__chosen" data-picker-chosen><?= $currentFileMedia !== null ? 'Current: ' . esc_html((string) $currentFileMedia['file_name']) : '' ?></span>
                    </div>
                </fieldset>
            <?php endif; ?>

            <button type="submit" class="lp-button lp-button--primary">Save Changes</button>
        </form>
    <?php else: ?>
        <?php $activeDescriptionFormat = get_active_editor($currentUser->id); ?>
        <form method="post" action="<?= esc_url(admin_url('downloads/add-new')) ?>" enctype="multipart/form-data">
            <?= Csrf::field('create_download') ?>
            <input type="hidden" name="form" value="create_download">

            <p class="lp-field">
                <label for="download-title">Title</label>
                <input type="text" id="download-title" name="title" required>
            </p>

            <p class="lp-field">
                <label for="download-description-format">Editor</label>
                <select id="download-description-format" name="description_format" data-lp-content-format-select>
                    <?php foreach (ContentFormat::cases() as $formatOption): ?>
                        <option value="<?= esc_attr($formatOption->value) ?>" <?= $activeDescriptionFormat === $formatOption ? 'selected' : '' ?>>
                            <?= esc_html($formatOption->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <div
                class="lp-field lp-content-editor"
                data-lp-content-editor
                data-format="<?= esc_attr($activeDescriptionFormat->value) ?>"
                data-upload-url="<?= esc_url(admin_url('downloads/add-new')) ?>"
                data-upload-csrf="<?= esc_attr(Csrf::token('editor_upload')) ?>"
                data-convert-csrf="<?= esc_attr(Csrf::token('convert_content')) ?>"
                data-media-picker-csrf="<?= esc_attr(Csrf::token('media_picker_query')) ?>"
                data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
                data-theme-stylesheet="<?= esc_url(theme_url('style.css')) ?>"
                data-autosave-id=""
            >
                <label for="download-description">Description</label>
                <textarea id="download-description" name="description" rows="4"></textarea>
            </div>

            <p class="lp-field">
                <label for="download-folder">Category</label>
                <select id="download-folder" name="folder_id">
                    <option value="0">(Uncategorized)</option>
                    <?php foreach ($allFolders as $folder): ?>
                        <option value="<?= (int) $folder->id ?>"><?= esc_html($folder->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <fieldset class="lp-field">
                <legend>Download source</legend>

                <label class="lp-field--checkbox">
                    <input type="radio" name="type" value="file" checked>
                    Upload a file
                </label>
                <p class="lp-field">
                    <label for="download-file">File</label>
                    <input type="file" id="download-file" name="file">
                </p>

                <label class="lp-field--checkbox">
                    <input type="radio" name="type" value="url">
                    Link to an external URL
                </label>
                <p class="lp-field">
                    <label for="download-external-url">URL</label>
                    <input type="text" id="download-external-url" name="external_url" placeholder="https://example.com/file.zip">
                </p>

                <?php /* LPP-012: "Add from server" — an already-uploaded Media item, instead of uploading the same file again. */ ?>
                <label class="lp-field--checkbox">
                    <input type="radio" name="type" value="existing" id="download-source-existing">
                    Use an existing file from the Media Library
                </label>
                <div
                    class="lp-field lp-download-file-picker"
                    data-lp-download-picker
                    data-picker-url="<?= esc_url(admin_url('downloads/add-new')) ?>"
                    data-picker-csrf="<?= esc_attr(Csrf::token('download_file_picker_query')) ?>"
                    data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
                    data-picker-radio="download-source-existing"
                >
                    <input type="hidden" name="existing_media_id" data-picker-value>
                    <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Server&hellip;</button>
                    <span class="lp-download-file-picker__chosen" data-picker-chosen></span>
                </div>
            </fieldset>

            <button type="submit" class="lp-button lp-button--primary">Add Download</button>
        </form>
    <?php endif; ?>
</section>

<details class="lp-admin__panel">
    <summary>New Category</summary>
    <form method="post" action="<?= esc_url(admin_url('downloads/add-new')) ?><?= $editingDownload !== null ? '?id=' . (int) $editingDownload->id : '' ?>">
        <?= Csrf::field('create_folder') ?>
        <input type="hidden" name="form" value="create_folder">
        <p class="lp-field">
            <label for="new-download-folder-name">Name</label>
            <input type="text" id="new-download-folder-name" name="name" required>
        </p>
        <button type="submit" class="lp-button lp-button--primary">Create</button>
    </form>
</details>
