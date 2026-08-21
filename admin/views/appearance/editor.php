<?php

/**
 * The admin Theme File Editor screen (LP-050).
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

$themeList = $kernel->themes->discover();
$activeThemeSlug = null;

foreach ($themeList as $info) {
    if ($info->isActive) {
        $activeThemeSlug = $info->slug;

        break;
    }
}

$knownSlugs = array_map(static fn ($info): string => $info->slug, $themeList);
$slug = trim((string) ($_GET['theme'] ?? $_POST['theme'] ?? ($activeThemeSlug ?? '')));

if (!in_array($slug, $knownSlugs, true)) {
    $slug = $activeThemeSlug ?? ($themeList[0]->slug ?? '');
}

$currentThemeInfo = null;

foreach ($themeList as $info) {
    if ($info->slug === $slug) {
        $currentThemeInfo = $info;

        break;
    }
}

$relativePath = trim((string) ($_GET['file'] ?? ''));
$error = null;
$unsavedContents = null;

/*
 * Download is a plain GET with no state change, so it needs no CSRF check
 * — but it does need to escape the admin chrome HTML that admin/index.php
 * has already queued into the output buffer (ob_start(), see its own
 * docblock) before it can send a raw file body with its own headers.
 */
if ($slug !== '' && ($_GET['download'] ?? '') === '1' && $relativePath !== '') {
    try {
        $downloadContents = $kernel->themeFileEditor->read($slug, $relativePath);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($relativePath) . '"');
        header('Content-Length: ' . (string) strlen($downloadContents));
        echo $downloadContents;
        exit;
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
}

/*
 * Rename/Delete/Duplicate/Restore-backup each render once per tree node
 * (or per backup) on one page load — many forms with the same "form"
 * value, so each needs its own CSRF action scoped to the exact target it
 * acts on (see admin/views/appearance/widgets.php's docblock for the
 * LP-012 incident this exact mistake caused). Save/Create File/Create
 * Folder each render exactly once per page (there is only ever one
 * "currently open file" editor panel, and one create-file/create-folder
 * form), so a fixed action name per form type is safe for those.
 */
$targetKey = static fn (string $path): string => sha1($slug . '|' . $path);

$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$postedPath = trim((string) ($_POST['path'] ?? ''));
$postedBackupId = trim((string) ($_POST['backup_id'] ?? ''));
$postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

$csrfAction = match ($form) {
    'save_file' => 'tfe_save',
    'create_file' => 'tfe_create_file',
    'create_folder' => 'tfe_create_folder',
    'rename' => 'tfe_rename_' . $targetKey($postedPath),
    'delete' => 'tfe_delete_' . $targetKey($postedPath),
    'duplicate' => 'tfe_duplicate_' . $targetKey($postedPath),
    'restore_backup' => 'tfe_restore_' . $targetKey($postedPath . '|' . $postedBackupId),
    default => 'tfe_unknown_form',
};

if ($slug !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($csrfAction, $postedToken)) {
    try {
        if ($form === 'save_file') {
            $contents = (string) ($_POST['contents'] ?? '');
            $forceSave = ($_POST['force_save'] ?? '') === '1';

            $syntaxError = str_ends_with($postedPath, '.php')
                ? $kernel->themeFileEditor->checkPhpSyntax($contents)
                : null;

            if ($syntaxError !== null && !$forceSave) {
                $error = 'PHP syntax error: ' . $syntaxError . ' — review it, or save again to force.';
                $relativePath = $postedPath;
                $unsavedContents = $contents;
            } else {
                $kernel->themeFileEditor->save($slug, $postedPath, $contents);

                header('Location: ' . admin_url('appearance/editor') . '?theme=' . rawurlencode($slug) . '&file=' . rawurlencode($postedPath) . '&saved=1');
                exit;
            }
        } elseif ($form === 'create_file') {
            $newPath = trim((string) ($_POST['new_path'] ?? ''));
            $kernel->themeFileEditor->createFile($slug, $newPath);

            header('Location: ' . admin_url('appearance/editor') . '?theme=' . rawurlencode($slug) . '&file=' . rawurlencode($newPath) . '&created=1');
            exit;
        } elseif ($form === 'create_folder') {
            $newPath = trim((string) ($_POST['new_path'] ?? ''));
            $kernel->themeFileEditor->createFolder($slug, $newPath);

            header('Location: ' . admin_url('appearance/editor') . '?theme=' . rawurlencode($slug) . '&created=1');
            exit;
        } elseif ($form === 'rename') {
            $newName = trim((string) ($_POST['new_name'] ?? ''));
            $newRelativePath = $kernel->themeFileEditor->rename($slug, $postedPath, $newName);

            header('Location: ' . admin_url('appearance/editor') . '?theme=' . rawurlencode($slug) . '&file=' . rawurlencode($newRelativePath) . '&renamed=1');
            exit;
        } elseif ($form === 'delete') {
            $kernel->themeFileEditor->delete($slug, $postedPath);

            header('Location: ' . admin_url('appearance/editor') . '?theme=' . rawurlencode($slug) . '&deleted=1');
            exit;
        } elseif ($form === 'duplicate') {
            $newRelativePath = $kernel->themeFileEditor->duplicate($slug, $postedPath);

            header('Location: ' . admin_url('appearance/editor') . '?theme=' . rawurlencode($slug) . '&file=' . rawurlencode($newRelativePath) . '&duplicated=1');
            exit;
        } elseif ($form === 'restore_backup') {
            $kernel->themeFileEditor->restoreBackup($slug, $postedPath, $postedBackupId);

            header('Location: ' . admin_url('appearance/editor') . '?theme=' . rawurlencode($slug) . '&file=' . rawurlencode($postedPath) . '&restored=1');
            exit;
        }
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$tree = $slug !== '' ? $kernel->themeFileEditor->tree($slug) : [];

/**
 * @param array<int, array{name: string, path: string, type: string, size: int, modifiedAt: int, writable: bool, children: array}> $nodes
 */
$findNode = static function (array $nodes, string $path) use (&$findNode): ?array {
    foreach ($nodes as $node) {
        if ($node['path'] === $path) {
            return $node;
        }

        if ($node['type'] === 'dir') {
            $found = $findNode($node['children'], $path);

            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
};

$currentNode = $relativePath !== '' ? $findNode($tree, $relativePath) : null;
$currentContents = null;
$currentBackups = [];

if ($relativePath !== '' && $currentNode !== null) {
    try {
        $currentContents = $unsavedContents ?? $kernel->themeFileEditor->read($slug, $relativePath);
        $currentBackups = $kernel->themeFileEditor->backups($slug, $relativePath);
    } catch (\Throwable $exception) {
        $error ??= $exception->getMessage();
        $relativePath = '';
        $currentNode = null;
    }
} elseif ($relativePath !== '') {
    $error ??= 'That file could not be found.';
    $relativePath = '';
}

$editorUrl = static fn (array $query = []) => admin_url('appearance/editor') . '?' . http_build_query(array_filter(['theme' => $slug] + $query, static fn ($v) => $v !== null && $v !== ''));
?>
<h1 class="lp-admin__title">Theme Editor</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php foreach (['saved' => 'Saved.', 'created' => 'Created.', 'renamed' => 'Renamed.', 'deleted' => 'Deleted.', 'duplicated' => 'Duplicated.', 'restored' => 'Backup restored.'] as $flag => $message): ?>
    <?php if (isset($_GET[$flag])): ?>
        <div class="lp-alert lp-alert--success"><?= esc_html($message) ?></div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($themeList === []): ?>
    <p class="lp-admin__widget-placeholder">No themes are installed.</p>
<?php else: ?>
    <form method="get" action="<?= esc_url(admin_url('appearance/editor')) ?>" class="lp-theme-editor__theme-select">
        <label class="lp-visually-hidden" for="tfe-theme-select">Theme</label>
        <select id="tfe-theme-select" name="theme" data-lp-auto-submit>
            <?php foreach ($themeList as $info): ?>
                <option value="<?= esc_attr($info->slug) ?>" <?= $info->slug === $slug ? 'selected' : '' ?>>
                    <?= esc_html($info->name) ?><?= $info->isActive ? ' (Active)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($slug === $activeThemeSlug): ?>
        <div class="lp-alert lp-alert--warning">
            You are editing the currently active theme. Changes take effect immediately on the public site.
        </div>
    <?php endif; ?>

    <div class="lp-theme-editor">
        <section class="lp-admin__panel lp-theme-editor__tree-panel">
            <h2>Files</h2>
            <p class="lp-field lp-theme-editor__search">
                <label for="tfe-file-search">Search files</label>
                <input type="search" id="tfe-file-search" data-lp-file-search placeholder="Search by file name&hellip;">
            </p>

            <ul class="lp-theme-editor__tree" data-lp-file-tree>
                <?php
                $renderNode = static function (array $node) use (&$renderNode, $editorUrl, $relativePath, $slug): void {
                    $isCurrent = $node['path'] === $relativePath;
                    ?>
                    <li class="lp-theme-editor__node lp-theme-editor__node--<?= esc_attr($node['type']) ?>" data-file-search="<?= esc_attr(strtolower($node['name'])) ?>">
                        <?php if ($node['type'] === 'dir'): ?>
                            <details open>
                                <summary class="lp-theme-editor__node-label"><?= esc_html($node['name']) ?></summary>
                                <ul>
                                    <?php foreach ($node['children'] as $child): ?>
                                        <?php $renderNode($child); ?>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php else: ?>
                            <a class="lp-theme-editor__node-label lp-theme-editor__node-label--file<?= $isCurrent ? ' lp-theme-editor__node-label--current' : '' ?>" href="<?= esc_url($editorUrl(['file' => $node['path']])) ?>">
                                <?= esc_html($node['name']) ?>
                                <?php if (!$node['writable']): ?><span class="lp-theme-editor__lock" title="Not writable">&#128274;</span><?php endif; ?>
                            </a>
                            <div class="lp-theme-editor__node-actions">
                                <form method="post" action="<?= esc_url(admin_url('appearance/editor')) ?>" class="lp-admin__inline-form">
                                    <input type="hidden" name="theme" value="<?= esc_attr($slug) ?>">
                                    <?= Csrf::field('tfe_duplicate_' . sha1($slug . '|' . $node['path'])) ?>
                                    <input type="hidden" name="form" value="duplicate">
                                    <input type="hidden" name="path" value="<?= esc_attr($node['path']) ?>">
                                    <button type="submit" class="lp-button lp-button--link" title="Duplicate">Duplicate</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <div class="lp-theme-editor__node-actions">
                            <details class="lp-theme-editor__rename">
                                <summary>Rename</summary>
                                <form method="post" action="<?= esc_url(admin_url('appearance/editor')) ?>" class="lp-admin__inline-form">
                                    <input type="hidden" name="theme" value="<?= esc_attr($slug) ?>">
                                    <?= Csrf::field('tfe_rename_' . sha1($slug . '|' . $node['path'])) ?>
                                    <input type="hidden" name="form" value="rename">
                                    <input type="hidden" name="path" value="<?= esc_attr($node['path']) ?>">
                                    <label class="lp-visually-hidden" for="rename-<?= esc_attr(sha1($node['path'])) ?>">New name</label>
                                    <input type="text" id="rename-<?= esc_attr(sha1($node['path'])) ?>" name="new_name" value="<?= esc_attr($node['name']) ?>" required>
                                    <button type="submit" class="lp-button lp-button--secondary">Rename</button>
                                </form>
                            </details>
                            <form method="post" action="<?= esc_url(admin_url('appearance/editor')) ?>" class="lp-admin__inline-form" data-lp-confirm="<?= esc_attr($node['type'] === 'dir' ? 'Delete this folder and everything in it? This cannot be undone.' : 'Delete this file? This cannot be undone.') ?>">
                                <input type="hidden" name="theme" value="<?= esc_attr($slug) ?>">
                                <?= Csrf::field('tfe_delete_' . sha1($slug . '|' . $node['path'])) ?>
                                <input type="hidden" name="form" value="delete">
                                <input type="hidden" name="path" value="<?= esc_attr($node['path']) ?>">
                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                            </form>
                        </div>
                    </li>
                    <?php
                };

                foreach ($tree as $node) {
                    $renderNode($node);
                }
                ?>
            </ul>

            <details class="lp-theme-editor__create">
                <summary>Create a new file or folder</summary>
                <form method="post" action="<?= esc_url(admin_url('appearance/editor')) ?>" class="lp-theme-editor__create-form">
                    <input type="hidden" name="theme" value="<?= esc_attr($slug) ?>">
                    <p class="lp-field">
                        <label for="tfe-new-path">Path (relative to the theme folder, e.g. <code>partials/example.php</code>)</label>
                        <input type="text" id="tfe-new-path" name="new_path" required>
                    </p>
                    <div class="lp-admin__inline-form">
                        <?= Csrf::field('tfe_create_file') ?>
                        <button type="submit" name="form" value="create_file" class="lp-button lp-button--primary">Create File</button>
                    </div>
                </form>
                <form method="post" action="<?= esc_url(admin_url('appearance/editor')) ?>" class="lp-theme-editor__create-form">
                    <input type="hidden" name="theme" value="<?= esc_attr($slug) ?>">
                    <p class="lp-field">
                        <label for="tfe-new-folder-path">Folder path</label>
                        <input type="text" id="tfe-new-folder-path" name="new_path" required>
                    </p>
                    <div class="lp-admin__inline-form">
                        <?= Csrf::field('tfe_create_folder') ?>
                        <button type="submit" name="form" value="create_folder" class="lp-button lp-button--primary">Create Folder</button>
                    </div>
                </form>
            </details>

            <?php if ($currentThemeInfo !== null && ($currentThemeInfo->readmeFile !== null || $currentThemeInfo->changelogFile !== null)): ?>
                <p class="lp-theme-editor__shortcuts">
                    <?php if ($currentThemeInfo->readmeFile !== null): ?>
                        <a href="<?= esc_url($editorUrl(['file' => $currentThemeInfo->readmeFile])) ?>">Open README</a>
                    <?php endif; ?>
                    <?php if ($currentThemeInfo->changelogFile !== null): ?>
                        <a href="<?= esc_url($editorUrl(['file' => $currentThemeInfo->changelogFile])) ?>">Open CHANGELOG</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </section>

        <section class="lp-admin__panel lp-theme-editor__editor-panel">
            <?php if ($currentThemeInfo !== null): ?>
                <p class="lp-theme-editor__meta">
                    <?= esc_html($currentThemeInfo->name) ?>
                    <?php if ($currentThemeInfo->version !== ''): ?> &middot; v<?= esc_html($currentThemeInfo->version) ?><?php endif; ?>
                    <?php if ($currentThemeInfo->author !== ''): ?> &middot; by <?= esc_html($currentThemeInfo->author) ?><?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($relativePath === '' || $currentNode === null || $currentContents === null): ?>
                <p class="lp-admin__widget-placeholder">Select a file from the list to view or edit it.</p>
            <?php else: ?>
                <h2><?= esc_html($relativePath) ?></h2>

                <p class="lp-theme-editor__file-meta">
                    <?= number_format($currentNode['size']) ?> bytes &middot;
                    Last modified <?= esc_html(date('Y-m-d H:i', $currentNode['modifiedAt'])) ?> &middot;
                    Encoding: UTF-8
                    <?php if (!$currentNode['writable']): ?> &middot; <strong>Read-only (not writable by the web server)</strong><?php endif; ?>
                </p>

                <p class="lp-theme-editor__toolbar">
                    <a class="lp-button lp-button--secondary" href="<?= esc_url($editorUrl(['file' => $relativePath, 'download' => '1'])) ?>">Download</a>
                </p>

                <form method="post" action="<?= esc_url(admin_url('appearance/editor')) ?>" data-lp-file-editor-form <?= str_ends_with($relativePath, '.php') ? 'data-lp-confirm="Save changes to this PHP file?"' : '' ?>>
                    <input type="hidden" name="theme" value="<?= esc_attr($slug) ?>">
                    <?= Csrf::field('tfe_save') ?>
                    <input type="hidden" name="form" value="save_file">
                    <input type="hidden" name="path" value="<?= esc_attr($relativePath) ?>">

                    <div data-lp-theme-file-editor data-extension="<?= esc_attr(strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))) ?>">
                        <textarea id="tfe-editor" name="contents" rows="24" class="lp-code-textarea" <?= $currentNode['writable'] ? '' : 'readonly' ?>><?= esc_html($currentContents) ?></textarea>
                    </div>

                    <button type="submit" class="lp-button lp-button--primary" <?= $currentNode['writable'] ? '' : 'disabled' ?>>Save Changes</button>
                    <?php if ($error !== null && str_starts_with($error, 'PHP syntax error')): ?>
                        <!-- Plain name="force_save" value="1" submit button — the browser includes a
                             clicked submit button's own name/value with the form data automatically,
                             so forcing a save through a known PHP syntax error needs no JS at all. -->
                        <button type="submit" name="force_save" value="1" class="lp-button lp-button--danger">Save Anyway</button>
                    <?php endif; ?>
                </form>

                <?php if ($currentBackups !== []): ?>
                    <details class="lp-theme-editor__backups">
                        <summary>Version history (<?= count($currentBackups) ?>)</summary>
                        <ul class="lp-theme-editor__backup-list">
                            <?php foreach ($currentBackups as $backup): ?>
                                <li>
                                    <?= esc_html(date('Y-m-d H:i:s', $backup['createdAt'])) ?>
                                    (<?= number_format($backup['size']) ?> bytes)
                                    <form method="post" action="<?= esc_url(admin_url('appearance/editor')) ?>" class="lp-admin__inline-form" data-lp-confirm="Restore this version? The current content will be backed up first.">
                                        <input type="hidden" name="theme" value="<?= esc_attr($slug) ?>">
                                        <?= Csrf::field('tfe_restore_' . sha1($slug . '|' . $relativePath . '|' . $backup['id'])) ?>
                                        <input type="hidden" name="form" value="restore_backup">
                                        <input type="hidden" name="path" value="<?= esc_attr($relativePath) ?>">
                                        <input type="hidden" name="backup_id" value="<?= esc_attr($backup['id']) ?>">
                                        <button type="submit" class="lp-button lp-button--secondary">Restore</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
