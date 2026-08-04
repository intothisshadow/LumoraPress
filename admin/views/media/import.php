<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Folder;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$folderService = $kernel->folders;
$importService = $kernel->mediaImport;
$allowedImportDirectories = (array) (json_decode((string) $kernel->config->option('media_import_allowed_directories', '[]'), true) ?: []);

$error = null;
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

    if ($form === 'scan_import' && Csrf::verify('scan_import', $token)) {
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
            header('Location: ' . admin_url('media/import') . '?import_token=' . $importToken);
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

        header('Location: ' . admin_url('media/import') . '?import_token=' . $importToken);
        exit;
    } elseif ($form === 'media_import_settings' && $currentUser->can('manage_options') && Csrf::verify('media_import_settings', $token)) {
        /*
         * LP-061: this page (like the rest of Media Manager) only
         * requires upload_files, which Author/Editor roles both hold —
         * but which server directories can be scanned is site-wide
         * configuration, previously gated by manage_options on
         * Settings > Media. The $currentUser->can('manage_options')
         * check above (mirrored by the section below not rendering at
         * all for a non-manage_options viewer) keeps that restriction
         * intact even though it now lives on a less-privileged page.
         */
        $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['media_import_allowed_directories'] ?? '')) ?: [];
        $directories = array_values(array_unique(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '')));
        $kernel->config->setOption('media_import_allowed_directories', json_encode($directories));

        header('Location: ' . admin_url('media/import') . '?saved=1');
        exit;
    }
}

$allFolders = $folderService->listAll();
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
<h1 class="lp-admin__title">Media Manager</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($currentUser->can('manage_options')): ?>
    <section class="lp-admin__panel">
        <h2>Import Settings</h2>
        <form method="post" action="<?= esc_url(admin_url('media/import')) ?>">
            <?= Csrf::field('media_import_settings') ?>
            <input type="hidden" name="form" value="media_import_settings">

            <p class="lp-field">
                <label for="media-import-directories">Allowed import directories (one absolute path per line)</label>
                <textarea id="media-import-directories" name="media_import_allowed_directories" rows="4"><?= esc_html(implode("\n", $allowedImportDirectories)) ?></textarea>
                <span class="lp-field__hint">Only these directories (and their subdirectories) can be scanned below. Leave empty to disable server import entirely.</span>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save</button>
        </form>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Import from Server</h2>

    <?php if ($allowedImportDirectories === []): ?>
        <p class="lp-admin__widget-placeholder">
            <?php if ($currentUser->can('manage_options')): ?>
                No import directories are configured. Add one or more absolute server paths above under "Import Settings".
            <?php else: ?>
                No import directories are configured. Ask an administrator to add one or more absolute server paths on this page.
            <?php endif; ?>
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
            <form method="post" action="<?= esc_url(admin_url('media/import')) ?>" id="import-bulk-continue">
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
            <a class="lp-button" href="<?= esc_url(admin_url('media/import')) ?>">Import more files</a>
        <?php endif; ?>
    <?php elseif ($scanResults !== null): ?>
        <?php if ($scanResults['files'] === []): ?>
            <p class="lp-admin__widget-placeholder">No importable files were found in that directory.</p>
            <a class="lp-button" href="<?= esc_url(admin_url('media/import')) ?>">Back</a>
        <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('media/import')) ?>">
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
                <a class="lp-button" href="<?= esc_url(admin_url('media/import')) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('media/import')) ?>">
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
