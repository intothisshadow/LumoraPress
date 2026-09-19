<?php

/**
 * The admin Link Directory > Export / Import screen (LPP-027): download every category/link as one JSON file, or upload one exported elsewhere to add its categories/links here.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.17.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\LinkDirectory\LinkDirectoryCategoryService;
use LumoraPress\Plugins\LinkDirectory\LinkDirectoryPortabilityService;
use LumoraPress\Plugins\LinkDirectory\LinkService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
$linkCategories = new LinkDirectoryCategoryService($kernel->database, $tablePrefix);
$links = new LinkService($kernel->database, $tablePrefix);
$appVersion = require LUMORA_ROOT . '/version.php';
$portability = new LinkDirectoryPortabilityService($linkCategories, $links, (string) $appVersion['version']);

// A plain GET with no state change needs no CSRF check, but it does need
// to clear admin/index.php's output buffer before sending a raw file
// body — same reasoning Maintenance > Tools' own Export Settings uses.
if (($_GET['export'] ?? '') === '1') {
    $exportJson = $portability->export();
    $exportFilename = 'lumorapress-link-directory-' . date('Y-m-d') . '.json';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
    header('Content-Length: ' . (string) strlen($exportJson));
    echo $exportJson;
    exit;
}

$importError = null;
$importResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'import' && Csrf::verify('link_directory_import', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $upload = $_FILES['import_file'] ?? null;

        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $importError = 'Please choose an exported Link Directory file to upload.';
        } else {
            $contents = file_get_contents((string) $upload['tmp_name']);

            if ($contents === false) {
                $importError = 'The uploaded file could not be read.';
            } else {
                try {
                    $importResult = $portability->import($contents);
                } catch (\RuntimeException $exception) {
                    $importError = $exception->getMessage();
                }
            }
        }
    }
}
?>
<h1 class="lp-admin__title">Link Directory &mdash; Export / Import</h1>

<?php if ($importError !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($importError) ?></div>
<?php endif; ?>

<?php if ($importResult !== null): ?>
    <div class="lp-alert lp-alert--success">
        Imported <?= (int) $importResult['categoriesCreated'] ?> categor<?= $importResult['categoriesCreated'] === 1 ? 'y' : 'ies' ?>
        and <?= (int) $importResult['linksCreated'] ?> link<?= $importResult['linksCreated'] === 1 ? '' : 's' ?>.
    </div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Export</h2>
    <p class="lp-field__hint">
        Downloads every category and link currently in your Link Directory
        as one JSON file — useful as a backup, or to copy your whole
        directory into a different Lumora Press install. Thumbnails aren't
        included, since a thumbnail is a Media Library reference specific
        to this install; re-add one after importing if a link needs it.
    </p>
    <a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('link-directory/export-import') . '?export=1') ?>">Download Link Directory File</a>
</section>

<section class="lp-admin__panel">
    <h2>Import</h2>
    <p class="lp-field__hint">
        Upload a Link Directory file exported from this or another Lumora
        Press install. Every category and link in the file is added as new
        &mdash; nothing already here is changed or removed, so importing
        the same file twice creates duplicates.
    </p>
    <form method="post" action="<?= esc_url(admin_url('link-directory/export-import')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('link_directory_import') ?>
        <input type="hidden" name="form" value="import">
        <p class="lp-field">
            <label for="link-directory-import-file">Link Directory file</label>
            <input type="file" id="link-directory-import-file" name="import_file" accept=".json,application/json" required>
        </p>
        <button type="submit" class="lp-button lp-button--primary">Upload and Import</button>
    </form>
</section>
