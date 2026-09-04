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
use LumoraPress\Plugins\Downloads\DownloadCategoryService;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\Downloads\DownloadType;
use LumoraPress\Plugins\FontAwesome\FontAwesomeService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the Downloads plugin is active, so DownloadService's
// class is guaranteed to already be loaded.
$downloadCategories = new DownloadCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$downloads = new DownloadService(
    $kernel->database,
    (string) $kernel->config->get('table_prefix', 'lp_'),
    $kernel->media,
    $kernel->redirects,
    $downloadCategories,
    $kernel->thumbnails,
    $kernel->mediaStats,
);

/**
 * Shared shape for one Media row in the "Insert Image" picker. Downloads
 * has no controller of its own, so this stays inline here.
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

// The Description field's editor image upload/format-switch conversion:
// JSON sub-actions on this same page, gated on 'upload_files' since
// that's what this plugin's whole admin area is already gated behind.
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
        // Without this, an image uploaded from the editor's "Upload New"
        // step would report no size options in $buildEditorPickerItem().
        $kernel->thumbnails->generate($uploaded);

        echo json_encode([
            'data' => ['filePath' => $kernel->media->url($uploaded)],
            'url' => $kernel->media->url($uploaded),
            'item' => $buildEditorPickerItem($uploaded, $kernel->thumbnails->thumbnailsFor((int) $uploaded['id'])),
            // Csrf::verify() is single-use; content-editor.js writes this
            // fresh token back for the next call.
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
    // One batched query for every item's thumbnails rather than one per item.
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

// "Insert/Edit Link" dialog's "Or link to existing content" search, gated
// on 'upload_files' to match every other sub-action on this page.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'link_picker_query') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('upload_files') || !Csrf::verify('link_picker_query', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    $term = trim((string) ($_POST['term'] ?? ''));
    $linkPickerFilters = $term !== '' ? ['term' => $term] : [];

    $linkPickerItems = [];

    foreach ($kernel->posts->paginateForAdmin(1, 15, null, $linkPickerFilters)['posts'] as $resultPost) {
        $linkPickerDate = $resultPost->publishedAt ?? $resultPost->updatedAt;
        $linkPickerItems[] = [
            'title' => $resultPost->title,
            'url' => post_permalink($resultPost),
            'type' => 'Post',
            'date' => $linkPickerDate->format('Y/m/d'),
            'sortKey' => $linkPickerDate->format('Y-m-d H:i:s'),
        ];
    }

    foreach ($kernel->pages->paginateForAdmin(1, 15, null, $linkPickerFilters)['pages'] as $resultPage) {
        $linkPickerDate = $resultPage->publishedAt ?? $resultPage->updatedAt;
        $linkPickerItems[] = [
            'title' => $resultPage->title,
            'url' => page_permalink($resultPage),
            'type' => 'Page',
            'date' => $linkPickerDate->format('Y/m/d'),
            'sortKey' => $linkPickerDate->format('Y-m-d H:i:s'),
        ];
    }

    usort($linkPickerItems, static fn (array $a, array $b): int => $b['sortKey'] <=> $a['sortKey']);

    echo json_encode([
        'items' => array_map(
            static fn (array $item): array => ['title' => $item['title'], 'url' => $item['url'], 'type' => $item['type'], 'date' => $item['date']],
            array_slice($linkPickerItems, 0, 20),
        ),
        'csrfToken' => Csrf::token('link_picker_query'),
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'font_awesome_icon_query') {
    // FontAwesomeService's class must be guarded, not assumed loaded.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if (class_exists(FontAwesomeService::class, false)) {
        FontAwesomeService::instance()->queryIconsForPicker($_POST, $csrfToken);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Font Awesome is not active.']);
    }

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'emoji_picker_record_recent') {
    // Core UserService work, not delegated to the optional Emoji Picker
    // plugin's own service.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!Csrf::verify('emoji_picker_record_recent', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    $insertedEmoji = trim((string) ($_POST['emoji'] ?? ''));

    if ($insertedEmoji === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing emoji.']);
        exit;
    }

    $recent = $kernel->users->addRecentEmoji($currentUser->id, $insertedEmoji, lp_emoji_picker_data()['recentLimit']);
    do_action('lp_emoji_inserted', $insertedEmoji, $currentUser->id);

    echo json_encode(['recent' => $recent, 'csrfToken' => Csrf::token('emoji_picker_record_recent')]);
    exit;
}

// "Add from server" / "Replace file" — deliberately separate from
// media_picker_query above, which is the image-only "Insert Image"
// picker. A download's file can be anything, so this queries every
// Media item with no type filter and returns a plain
// {id, name, url, mimeType, typeCategory} for downloads-picker.js.
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

    if ($form === 'create_download_category' && Csrf::verify('create_download_category', $token)) {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            $error = 'A category name is required.';
        } else {
            $downloadCategories->create($name);
            $redirectTarget = admin_url('downloads/add-new') . ($editingDownload !== null ? '?id=' . $editingDownload->id : '');
            header('Location: ' . $redirectTarget);
            exit;
        }
    } elseif ($form === 'create_download' && Csrf::verify('create_download', $token)) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = (string) ($_POST['description'] ?? '');
        $descriptionFormat = ContentFormat::tryFrom((string) ($_POST['description_format'] ?? '')) ?? get_active_editor($currentUser->id);
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        // A third "existing" choice alongside file/url — an
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
                        folderId: null,
                        mediaId: $existingMediaId,
                        descriptionFormat: $descriptionFormat,
                        categoryId: $categoryId > 0 ? $categoryId : null,
                    )
                    : $downloads->create(
                        title: $title,
                        description: $description,
                        folderId: null,
                        type: $type,
                        file: $type === DownloadType::File ? $_FILES['file'] : null,
                        externalUrl: $type === DownloadType::Url ? $externalUrl : null,
                        uploadedByUserId: $currentUser->id,
                        descriptionFormat: $descriptionFormat,
                        categoryId: $categoryId > 0 ? $categoryId : null,
                    );

                // Lands on this screen's Edit view (not All Downloads) so
                // the freshly uploaded file's preview is visible immediately.
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
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $externalUrl = trim((string) ($_POST['external_url'] ?? ''));

            if ($title === '') {
                $error = 'Please enter a title.';
            } else {
                // Attaching/replacing a file works regardless of the
                // download's current type — a Url-typed download can
                // become File-typed this way too. An uploaded $_FILES
                // entry wins over a picked existing_media_id if both are
                // submitted, and either wins over a same-request URL edit
                // below, since an explicit file action reads as more
                // deliberate than a possibly-untouched text field.
                $newMediaId = null;
                $newMediaOwned = true;

                if (isset($_FILES['replace_file']) && $_FILES['replace_file']['error'] === UPLOAD_ERR_OK) {
                    $uploadedReplacement = $kernel->media->upload($_FILES['replace_file'], $currentUser->id, null);
                    $kernel->thumbnails->generate($uploadedReplacement);
                    $newMediaId = (int) $uploadedReplacement['id'];
                    // Freshly uploaded specifically for this download —
                    // owned (the default), so a later replace/delete is
                    // free to clean it up.
                } else {
                    $replaceMediaId = (int) ($_POST['replace_media_id'] ?? 0);

                    if ($replaceMediaId > 0) {
                        $newMediaId = $replaceMediaId;
                        // Picked from the Media Library — not owned, see
                        // replaceFile()'s/convertToFile()'s own docblocks
                        // for why that matters.
                        $newMediaOwned = false;
                    }
                }

                if ($newMediaId !== null && $editingDownload !== null) {
                    if ($editingDownload->type === DownloadType::File) {
                        $downloads->replaceFile($id, $newMediaId, newMediaOwned: $newMediaOwned);
                    } else {
                        $downloads->convertToFile($id, $newMediaId, newMediaOwned: $newMediaOwned);
                    }
                } elseif ($editingDownload !== null && $editingDownload->type === DownloadType::File && $externalUrl !== '') {
                    // Mirror direction: a File-typed download whose URL
                    // field was filled in converts to Url-typed.
                    $downloads->convertToUrl($id, $externalUrl);
                }

                // folder_id isn't editable from this form; preserve
                // whatever value the download already had.
                $downloads->update($id, $title, $description, $editingDownload?->folderId, $externalUrl !== '' ? $externalUrl : null, $descriptionFormat, $categoryId > 0 ? $categoryId : null);

                header('Location: ' . admin_url('downloads/all-downloads') . '?saved=1');
                exit;
            }
        }
    }
}

// Distinct from $allFolders below, which is the unrelated Media Library
// Folder tree the "Insert Image" picker's filter uses.
$allDownloadCategories = $downloadCategories->listAll();

$allFolders = $kernel->folders->listAll();
// Only the (small) folder tree is preloaded; the picker's image grid is
// queried on demand via the media_picker_query sub-action above.
$editorFolderTree = array_map(
    static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
    $kernel->folders->listAllForTree(),
);

/*
 * Preview image: thumbnailMediaId — an explicitly chosen representative
 * image, distinct from the download's own file — wins when set, since a
 * Url-typed download has no local file and a File-typed one's file may
 * not be an image. Falls back to the File-typed download's own file, or
 * null if neither applies or the referenced Media row is gone.
 *
 * @var array<string, mixed>|null $previewMedia
 */
$previewMediaId = $editingDownload?->thumbnailMediaId
    ?? ($editingDownload !== null && $editingDownload->type === DownloadType::File ? $editingDownload->mediaId : null);
$previewMedia = $previewMediaId !== null ? $kernel->media->find($previewMediaId) : null;

// Looked up separately from $previewMedia above, which can point at a
// distinct thumbnailMediaId rather than the download's actual file.
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

<?php if ($editingDownload !== null && $editingDownload->url === ''): ?>
    <div class="lp-alert lp-alert--warning">
        This download has no file or URL attached yet — it's hidden from the public site until you add one below, using "Replace with a file" or "Replace with a URL".
    </div>
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
                data-link-picker-csrf="<?= esc_attr(Csrf::token('link_picker_query')) ?>"
                data-shortcodes="<?= esc_attr((string) json_encode($kernel->shortcodes->toArray())) ?>"
                data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
                <?php if (lp_fontawesome_enabled()): ?>
                    data-icon-picker-csrf="<?= esc_attr(Csrf::token('font_awesome_icon_query')) ?>"
                    data-icon-picker-css="<?= esc_attr((string) json_encode((array) apply_filters('lp_fontawesome_css_urls', []))) ?>"
                <?php endif; ?>
                <?php if (lp_emoji_picker_enabled()): ?>
                    data-emoji-wysiwyg-enabled="<?= lp_emoji_picker_editor_enabled('wysiwyg') ? '1' : '0' ?>"
                    data-emoji-markdown-enabled="<?= lp_emoji_picker_editor_enabled('markdown') ? '1' : '0' ?>"
                    data-emoji-dataset="<?= esc_attr((string) json_encode(lp_emoji_picker_data()['dataset'])) ?>"
                    data-emoji-default-category="<?= esc_attr(lp_emoji_picker_data()['defaultCategory']) ?>"
                    data-emoji-recent="<?= esc_attr((string) json_encode($kernel->users->getRecentEmoji($currentUser->id))) ?>"
                    data-emoji-record-csrf="<?= esc_attr(Csrf::token('emoji_picker_record_recent')) ?>"
                <?php endif; ?>
                data-theme-stylesheet="<?= esc_url(theme_url('style.css')) ?>"
                data-more-tag-stylesheet="<?= esc_url(admin_asset_url('css/content-editor-iframe.css')) ?>"
                data-autosave-id="<?= esc_attr('download-' . $editingDownload->id) ?>"
            >
                <label for="download-description">Description</label>
                <textarea id="download-description" name="description" rows="4"><?= esc_html($editingDownload->description) ?></textarea>
            </div>

            <p class="lp-field">
                <label for="download-category">Category</label>
                <select id="download-category" name="category_id">
                    <option value="0">(Uncategorized)</option>
                    <?php foreach ($allDownloadCategories as $downloadCategory): ?>
                        <option value="<?= (int) $downloadCategory->id ?>" <?= $editingDownload->categoryId === $downloadCategory->id ? 'selected' : '' ?>><?= esc_html($downloadCategory->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php /* A download's type isn't fixed after creation — either option below can also switch it (Url -> File via the Replace-with-a-file panel, File -> Url via the URL field), not just edit the existing source in place. See DownloadService::convertToFile()/convertToUrl()'s own docblocks. */ ?>
            <p class="lp-field">
                <strong>Current source:</strong>
                <span class="lp-field__hint">
                    <?php if ($editingDownload->type === DownloadType::File): ?>
                        Uploaded file<?= $currentFileMedia !== null ? ' — ' . esc_html((string) $currentFileMedia['file_name']) : '' ?>
                    <?php else: ?>
                        External URL — <?= esc_html((string) ($editingDownload->targetUrl ?? '')) ?>
                    <?php endif; ?>
                </span>
            </p>

            <fieldset class="lp-field">
                <legend>Replace with a file</legend>
                <p class="lp-field__hint">
                    <?= $editingDownload->type === DownloadType::File
                        ? 'Upload a new file, or choose one already in the Media Library, to replace this download\'s current file. Leave both blank to keep the current file.'
                        : 'Upload a file, or choose one already in the Media Library, to switch this download from an external URL to a hosted file.' ?>
                </p>
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

            <fieldset class="lp-field">
                <legend>Replace with a URL</legend>
                <p class="lp-field__hint">
                    <?= $editingDownload->type === DownloadType::Url
                        ? 'Editing this updates the existing external link.'
                        : 'Filling this in switches this download from a hosted file to an external URL.' ?>
                </p>
                <p class="lp-field">
                    <label for="download-external-url">URL</label>
                    <input type="text" id="download-external-url" name="external_url" value="<?= $editingDownload->type === DownloadType::Url ? esc_attr($editingDownload->targetUrl ?? '') : '' ?>" placeholder="https://example.com/file.zip">
                </p>
            </fieldset>

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
                data-link-picker-csrf="<?= esc_attr(Csrf::token('link_picker_query')) ?>"
                data-shortcodes="<?= esc_attr((string) json_encode($kernel->shortcodes->toArray())) ?>"
                data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
                <?php if (lp_fontawesome_enabled()): ?>
                    data-icon-picker-csrf="<?= esc_attr(Csrf::token('font_awesome_icon_query')) ?>"
                    data-icon-picker-css="<?= esc_attr((string) json_encode((array) apply_filters('lp_fontawesome_css_urls', []))) ?>"
                <?php endif; ?>
                <?php if (lp_emoji_picker_enabled()): ?>
                    data-emoji-wysiwyg-enabled="<?= lp_emoji_picker_editor_enabled('wysiwyg') ? '1' : '0' ?>"
                    data-emoji-markdown-enabled="<?= lp_emoji_picker_editor_enabled('markdown') ? '1' : '0' ?>"
                    data-emoji-dataset="<?= esc_attr((string) json_encode(lp_emoji_picker_data()['dataset'])) ?>"
                    data-emoji-default-category="<?= esc_attr(lp_emoji_picker_data()['defaultCategory']) ?>"
                    data-emoji-recent="<?= esc_attr((string) json_encode($kernel->users->getRecentEmoji($currentUser->id))) ?>"
                    data-emoji-record-csrf="<?= esc_attr(Csrf::token('emoji_picker_record_recent')) ?>"
                <?php endif; ?>
                data-theme-stylesheet="<?= esc_url(theme_url('style.css')) ?>"
                data-more-tag-stylesheet="<?= esc_url(admin_asset_url('css/content-editor-iframe.css')) ?>"
                data-autosave-id=""
            >
                <label for="download-description">Description</label>
                <textarea id="download-description" name="description" rows="4"></textarea>
            </div>

            <p class="lp-field">
                <label for="download-category">Category</label>
                <select id="download-category" name="category_id">
                    <option value="0">(Uncategorized)</option>
                    <?php foreach ($allDownloadCategories as $downloadCategory): ?>
                        <option value="<?= (int) $downloadCategory->id ?>"><?= esc_html($downloadCategory->name) ?></option>
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

                <?php /* "Add from server" — an already-uploaded Media item, instead of uploading the same file again. */ ?>
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
        <?= Csrf::field('create_download_category') ?>
        <input type="hidden" name="form" value="create_download_category">
        <p class="lp-field">
            <label for="new-download-category-name">Name</label>
            <input type="text" id="new-download-category-name" name="name" required>
        </p>
        <button type="submit" class="lp-button lp-button--primary">Create</button>
    </form>
</details>
