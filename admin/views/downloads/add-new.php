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
        $description = trim((string) ($_POST['description'] ?? ''));
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $type = ($_POST['type'] ?? '') === 'url' ? DownloadType::Url : DownloadType::File;
        $externalUrl = trim((string) ($_POST['external_url'] ?? ''));

        if ($title === '') {
            $error = 'Please enter a title.';
        } elseif ($type === DownloadType::File && (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE)) {
            $error = 'Please choose a file to upload.';
        } elseif ($type === DownloadType::Url && $externalUrl === '') {
            $error = 'Please enter a URL.';
        } else {
            try {
                $downloads->create(
                    title: $title,
                    description: $description,
                    folderId: $folderId > 0 ? $folderId : null,
                    type: $type,
                    file: $type === DownloadType::File ? $_FILES['file'] : null,
                    externalUrl: $type === DownloadType::Url ? $externalUrl : null,
                    uploadedByUserId: $currentUser->id,
                );

                header('Location: ' . admin_url('downloads/all-downloads') . '?saved=1');
                exit;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'update_download') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('update_download_' . $id, $token)) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $externalUrl = trim((string) ($_POST['external_url'] ?? ''));

            if ($title === '') {
                $error = 'Please enter a title.';
            } else {
                $downloads->update($id, $title, $description, $folderId > 0 ? $folderId : null, $externalUrl !== '' ? $externalUrl : null);

                header('Location: ' . admin_url('downloads/all-downloads') . '?saved=1');
                exit;
            }
        }
    }
}

$allFolders = $kernel->folders->listAll();
?>
<h1 class="lp-admin__title"><?= $editingDownload !== null ? 'Edit Download' : 'Add New Download' ?></h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<section class="lp-admin__panel">
    <?php if ($editingDownload !== null): ?>
        <form method="post" action="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $editingDownload->id ?>">
            <?= Csrf::field('update_download_' . $editingDownload->id) ?>
            <input type="hidden" name="form" value="update_download">
            <input type="hidden" name="id" value="<?= (int) $editingDownload->id ?>">

            <p class="lp-field">
                <label for="download-title">Title</label>
                <input type="text" id="download-title" name="title" value="<?= esc_attr($editingDownload->title) ?>" required>
            </p>

            <p class="lp-field">
                <label for="download-description">Description</label>
                <textarea id="download-description" name="description" rows="4"><?= esc_html($editingDownload->description) ?></textarea>
            </p>

            <p class="lp-field">
                <label for="download-folder">Category</label>
                <select id="download-folder" name="folder_id">
                    <option value="0">(Uncategorized)</option>
                    <?php foreach ($allFolders as $folder): ?>
                        <option value="<?= (int) $folder->id ?>" <?= $editingDownload->folderId === $folder->id ? 'selected' : '' ?>><?= esc_html($folder->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php /* Changing the underlying file/type of a download isn't supported — see DownloadService::update()'s docblock; delete and re-add covers that case. */ ?>
            <p class="lp-field">
                <strong>Download source:</strong>
                <span class="lp-field__hint"><?= $editingDownload->type === DownloadType::File ? 'Uploaded file' : 'External URL' ?> &mdash; not editable here; delete and re-add to change it.</span>
            </p>

            <?php if ($editingDownload->type === DownloadType::Url): ?>
                <p class="lp-field">
                    <label for="download-external-url">URL</label>
                    <input type="text" id="download-external-url" name="external_url" value="<?= esc_attr($editingDownload->targetUrl ?? '') ?>">
                </p>
            <?php endif; ?>

            <button type="submit" class="lp-button lp-button--primary">Save Changes</button>
        </form>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('downloads/add-new')) ?>" enctype="multipart/form-data">
            <?= Csrf::field('create_download') ?>
            <input type="hidden" name="form" value="create_download">

            <p class="lp-field">
                <label for="download-title">Title</label>
                <input type="text" id="download-title" name="title" required>
            </p>

            <p class="lp-field">
                <label for="download-description">Description</label>
                <textarea id="download-description" name="description" rows="4"></textarea>
            </p>

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
