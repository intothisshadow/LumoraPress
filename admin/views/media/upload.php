<?php

/**
 * The admin Media Manager's main grid and upload screen.
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
use LumoraPress\Models\Folder;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$mediaService = $kernel->media;
$folderService = $kernel->folders;
$thumbnailService = $kernel->thumbnails;

$error = null;

/**
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

    if ($form === 'upload' && Csrf::verify('upload', $token)) {
        $folderId = (int) ($_POST['folder_id'] ?? 0);

        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a file to upload.';
        } else {
            try {
                $uploaded = $mediaService->upload($_FILES['file'], $currentUser->id, $folderId > 0 ? $folderId : null);
                $thumbnailService->generate($uploaded);
                header('Location: ' . admin_url('media/media') . '?action=edit&id=' . $uploaded['id'] . '&saved=1');
                exit;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}

$allFolders = $folderService->listAll();
$folderOptions = $buildFolderOptions($allFolders, []);
?>
<h1 class="lp-admin__title">Media Manager</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Upload</h2>
    <form method="post" action="<?= esc_url(admin_url('media/upload')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('upload') ?>
        <input type="hidden" name="form" value="upload">

        <p class="lp-field">
            <label for="media-file">File</label>
            <input type="file" id="media-file" name="file" required>
        </p>

        <p class="lp-field">
            <label for="media-upload-folder">Folder</label>
            <select id="media-upload-folder" name="folder_id">
                <option value="0">(General Uploads)</option>
                <?php foreach ($folderOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"><?= esc_html($option['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Upload</button>
    </form>
</section>
