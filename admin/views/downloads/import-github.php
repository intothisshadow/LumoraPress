<?php

/**
 * The admin Downloads > Import from GitHub screen: import a download from a public repository, or update one that was.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\Downloads\DownloadCategoryService;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\Downloads\GitHubClient;
use LumoraPress\Plugins\Downloads\GitHubImportException;
use LumoraPress\Plugins\Downloads\GitHubImportService;
use LumoraPress\Plugins\Downloads\GitHubRepositoryRef;
use LumoraPress\Plugins\Downloads\GitHubSourceStore;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
$downloadCategories = new DownloadCategoryService($kernel->database, $tablePrefix);
$sourceStore = new GitHubSourceStore($kernel->database, $tablePrefix);
$downloadService = new DownloadService($kernel->database, $tablePrefix, $kernel->media, $kernel->redirects, $downloadCategories, $kernel->thumbnails, $kernel->mediaStats);
$importer = new GitHubImportService(
    new GitHubClient(),
    $sourceStore,
    $downloadService,
    $kernel->media,
    $kernel->folders,
    $kernel->config,
    $kernel->thumbnails,
    LUMORA_ROOT . '/content/uploads',
    sys_get_temp_dir(),
);

$token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
$error = null;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// The progress script's calls: one JSON response per phase, each carrying the next single-use token.
if ($isPost && ($_POST['form'] ?? null) === 'github_import_run' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    // admin/index.php has already buffered the page shell; discard it so only JSON is sent.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    $respond = static function (array $data, int $status = 200): never {
        http_response_code($status);
        echo json_encode($data + ['csrfToken' => Csrf::token('github_import_run')]);
        exit;
    };

    if (!$currentUser->can('upload_files') || !Csrf::verify('github_import_run', $token)) {
        $respond(['error' => 'Not permitted. Reload the page and try again.'], 403);
    }

    $jobToken = is_string($_POST['job'] ?? null) ? $_POST['job'] : '';
    $strings = static fn (string $key): array => array_values(array_filter((array) ($_POST[$key] ?? []), 'is_string'));

    try {
        switch ((string) ($_POST['phase'] ?? '')) {
            case 'begin_import':
                $ref = GitHubRepositoryRef::fromParts((string) ($_POST['owner'] ?? ''), (string) ($_POST['repo'] ?? ''));

                if ($ref === null) {
                    $respond(['error' => 'That repository could not be read.'], 422);
                }

                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $respond($importer->beginImport(
                    $currentUser->id,
                    $ref,
                    (string) ($_POST['title'] ?? ''),
                    (string) ($_POST['description'] ?? ''),
                    $categoryId > 0 ? $categoryId : null,
                    $strings('paths'),
                    ($_POST['allow_duplicate'] ?? '') === '1',
                ));
                // no break: respond() never returns

            case 'begin_update':
                $respond($importer->beginUpdate($currentUser->id, (int) ($_POST['download_id'] ?? 0), [
                    'description' => ($_POST['apply_description'] ?? '') === '1',
                    'title' => ($_POST['apply_title'] ?? '') === '1',
                    'branch' => ($_POST['apply_branch'] ?? '') === '1',
                    'add' => $strings('add'),
                    'replace' => $strings('replace'),
                    'remove' => $strings('remove'),
                ]));

            case 'step':
                $respond($importer->runStep($jobToken, $currentUser->id));

            case 'finish':
                $result = $importer->finish($jobToken, $currentUser->id);
                $respond([
                    'done' => true,
                    'failed' => $result['failed'],
                    'redirect' => admin_url('downloads/add-new') . '?id=' . $result['downloadId'] . '&saved=1',
                ]);

            case 'cancel':
                $importer->cancel($jobToken, $currentUser->id);
                $respond(['cancelled' => true]);
        }
    } catch (GitHubImportException | InvalidArgumentException | RuntimeException $exception) {
        $respond(['error' => $exception->getMessage()], 422);
    }

    $respond(['error' => 'Unknown request.'], 400);
}

if ($isPost && ($_POST['form'] ?? null) === 'github_account' && $currentUser->can('upload_files') && Csrf::verify('github_account', $token)) {
    if ($importer->setAccount((string) ($_POST['github_account'] ?? ''))) {
        header('Location: ' . admin_url('downloads/import-github') . '?account_saved=1' . (isset($_GET['link']) ? '&link=' . (int) $_GET['link'] : ''));
        exit;
    }

    $error = 'That is not a valid GitHub username.';
}

if ($isPost && ($_POST['form'] ?? null) === 'github_link' && $currentUser->can('upload_files') && Csrf::verify('github_link', $token)) {
    $linkRef = GitHubRepositoryRef::fromParts((string) ($_POST['owner'] ?? ''), (string) ($_POST['repo'] ?? ''));
    $linkTargetId = max(0, (int) ($_POST['download_id'] ?? 0));

    if ($linkRef === null) {
        $error = 'That repository could not be read.';
    } else {
        try {
            $importer->linkDownload($linkTargetId, $linkRef, ($_POST['switch_link'] ?? '') === '1');
            header('Location: ' . admin_url('downloads/import-github') . '?update=' . $linkTargetId . '&check=1&linked=1');
            exit;
        } catch (GitHubImportException | InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
}

if ($isPost && ($_POST['form'] ?? null) === 'github_refresh' && $currentUser->can('upload_files') && Csrf::verify('github_refresh', $token)) {
    try {
        $importer->repositoryChoices(true);
        header('Location: ' . admin_url('downloads/import-github') . '?refreshed=1' . (isset($_GET['link']) ? '&link=' . (int) $_GET['link'] : ''));
        exit;
    } catch (GitHubImportException $exception) {
        $error = $exception->getMessage();
    }
}

$updateId = max(0, (int) ($_GET['update'] ?? 0));
$linkId = max(0, (int) ($_GET['link'] ?? ($_POST['download_id'] ?? 0)));
$linkDownload = null;
$linkPreview = null;
$account = $importer->account();
$choices = ['repos' => [], 'imported' => [], 'fetchedAt' => 0];
$preview = null;
$updateCheck = null;
$updateDownload = null;
$updateSource = null;

if ($updateId > 0) {
    $updateSource = $sourceStore->find($updateId);
    $updateDownload = $updateSource !== null ? $downloadService->findById($updateId) : null;

    if ($updateSource === null || $updateDownload === null) {
        $error = 'That download was not imported from GitHub.';
        $updateId = 0;
    } elseif (isset($_GET['check'])) {
        try {
            $updateCheck = $importer->checkForUpdates($updateId);
        } catch (GitHubImportException $exception) {
            $error = $exception->getMessage();
        }
    }
}

if ($updateId === 0 && $linkId > 0) {
    $linkDownload = $downloadService->findById($linkId);

    if ($linkDownload === null || $sourceStore->find($linkId) !== null) {
        $error ??= $linkDownload === null ? 'That download no longer exists.' : 'This download is already linked to a GitHub repository.';
        $linkDownload = null;
        $linkId = 0;
    }
}

if ($updateId === 0) {
    if ($account !== '') {
        try {
            $choices = $importer->repositoryChoices();
        } catch (GitHubImportException $exception) {
            $error ??= $exception->getMessage();
        }
    }

    $repoUrl = trim((string) ($_GET['repo_url'] ?? ''));
    $repoName = trim((string) ($_GET['repo'] ?? ''));
    $wantsPreview = $repoUrl !== '' || $repoName !== '';
    $ref = $repoUrl !== '' ? GitHubRepositoryRef::parse($repoUrl) : GitHubRepositoryRef::fromParts($account, $repoName);

    if ($wantsPreview && $ref === null) {
        $error ??= 'That is not a GitHub repository. Use an address like https://github.com/owner/repository.';
    } elseif ($ref !== null && $wantsPreview) {
        try {
            if ($linkDownload !== null) {
                $linkPreview = $importer->previewLink($linkId, $ref);
            } else {
                $preview = $importer->preview($ref);
            }
        } catch (GitHubImportException | InvalidArgumentException $exception) {
            $error ??= $exception->getMessage();
        }
    }
}

$csrfRun = Csrf::token('github_import_run');
$endpoint = admin_url('downloads/import-github');
$allCategories = $downloadCategories->listAll();
$formatSize = static fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, (int) round($bytes / 1024))) . ' KB';
?>
<?php
// Keeps a download being linked selected while the account is saved or the list refreshed.
$linkQuery = $linkId > 0 ? '?link=' . $linkId : '';
?>
<h1 class="lp-admin__title"><?= $updateId > 0 ? 'Update from GitHub' : ($linkId > 0 ? 'Link to GitHub' : 'Import from GitHub') ?></h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['account_saved'])): ?>
    <div class="lp-alert lp-alert--success">GitHub account saved.</div>
<?php endif; ?>

<?php if (isset($_GET['refreshed'])): ?>
    <div class="lp-alert lp-alert--success">Repository list refreshed.</div>
<?php endif; ?>

<?php if (isset($_GET['linked'])): ?>
    <div class="lp-alert lp-alert--success">Linked. Nothing on the download was changed. Below is what GitHub has that the download doesn't.</div>
<?php endif; ?>

<?php if ($updateId > 0 && $updateSource !== null && $updateDownload !== null): ?>
    <section class="lp-admin__panel">
        <h2>GitHub Source</h2>
        <p>
            <strong><?= esc_html($updateDownload->title) ?></strong> was imported from
            <a href="<?= esc_url($updateSource['ref']->htmlUrl()) ?>"><?= esc_html($updateSource['ref']->fullName()) ?></a>
            (branch <code><?= esc_html($updateSource['branch']) ?></code>), last synced <?= esc_html($updateSource['syncedAt']) ?>.
        </p>
        <p class="lp-field__hint">Checking asks GitHub a few questions and changes nothing. It only happens when you click the button, and nothing here runs on its own.</p>
        <p>
            <a class="lp-button lp-button--primary" href="<?= esc_url($endpoint) ?>?update=<?= (int) $updateId ?>&amp;check=1">Check for changes</a>
            <a class="lp-button" href="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $updateId ?>">Back to the download</a>
        </p>
    </section>

    <?php if ($updateCheck !== null && !$updateCheck['hasChanges']): ?>
        <div class="lp-alert lp-alert--success">This download is up to date with GitHub.</div>
    <?php elseif ($updateCheck !== null): ?>
        <section class="lp-admin__panel">
            <h2>Changes on GitHub</h2>
            <form method="post" action="<?= esc_url($endpoint) ?>?update=<?= (int) $updateId ?>" data-lp-github-import data-lp-github-mode="update" data-lp-github-endpoint="<?= esc_url($endpoint) ?>" data-lp-github-csrf="<?= esc_attr($csrfRun) ?>">
                <input type="hidden" name="download_id" value="<?= (int) $updateId ?>">

                <?php if ($updateCheck['branchChanged']): ?>
                    <p class="lp-field">
                        <label class="lp-field--checkbox">
                            <input type="checkbox" name="apply_branch" value="1" checked>
                            The default branch is now <code><?= esc_html($updateCheck['newBranch']) ?></code> (was <code><?= esc_html($updateCheck['branch']) ?></code>). Point the download link at it.
                        </label>
                    </p>
                <?php endif; ?>

                <?php if ($updateCheck['readmeChanged']): ?>
                    <p class="lp-field">
                        <label class="lp-field--checkbox">
                            <input type="checkbox" name="apply_description" value="1" <?= $updateCheck['descriptionEditedLocally'] ? '' : 'checked' ?>>
                            The README changed. Replace the description with the new text.
                        </label>
                        <?php if ($updateCheck['descriptionEditedLocally']): ?>
                            <span class="lp-field__hint">You have edited this description since it was imported, so this is unticked to keep your changes.</span>
                        <?php endif; ?>
                    </p>
                    <?php if ($updateCheck['titleChanged']): ?>
                        <p class="lp-field">
                            <label class="lp-field--checkbox">
                                <input type="checkbox" name="apply_title" value="1" <?= $updateCheck['titleEditedLocally'] ? '' : 'checked' ?>>
                                Change the title to &ldquo;<?= esc_html($updateCheck['newTitle']) ?>&rdquo;.
                            </label>
                            <?php if ($updateCheck['titleEditedLocally']): ?>
                                <span class="lp-field__hint">You have edited this title since it was imported, so this is unticked.</span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($updateCheck['newImages'] !== []): ?>
                    <fieldset class="lp-field">
                        <legend>New screenshots</legend>
                        <?php foreach ($updateCheck['newImages'] as $image): ?>
                            <label class="lp-field--checkbox"><input type="checkbox" name="add[]" value="<?= esc_attr($image['path']) ?>" checked> <?= esc_html($image['name']) ?> <span class="lp-field__hint"><?= esc_html($formatSize($image['size'])) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ($updateCheck['changedImages'] !== []): ?>
                    <fieldset class="lp-field">
                        <legend>Changed screenshots</legend>
                        <span class="lp-field__hint">The image is replaced where it is, so anything already pointing at it keeps working.</span>
                        <?php foreach ($updateCheck['changedImages'] as $image): ?>
                            <label class="lp-field--checkbox"><input type="checkbox" name="replace[]" value="<?= esc_attr($image['path']) ?>" checked> <?= esc_html($image['name']) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ($updateCheck['removedImages'] !== []): ?>
                    <fieldset class="lp-field">
                        <legend>Removed from GitHub</legend>
                        <span class="lp-field__hint">These are kept here unless you tick them, in which case they are also deleted from the Media library.</span>
                        <?php foreach ($updateCheck['removedImages'] as $image): ?>
                            <label class="lp-field--checkbox"><input type="checkbox" name="remove[]" value="<?= esc_attr($image['path']) ?>"> <?= esc_html(basename($image['path'])) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <button type="submit" class="lp-button lp-button--primary">Apply Selected Changes</button>
            </form>

            <div data-lp-github-progress hidden>
                <progress data-lp-github-bar max="1" value="0"></progress>
                <p class="lp-field__hint" data-lp-github-status role="status"></p>
                <ul data-lp-github-failures></ul>
            </div>
            <noscript><p class="lp-field__hint">This screen needs JavaScript to apply changes.</p></noscript>
        </section>
    <?php endif; ?>
<?php else: ?>
    <?php if ($linkDownload !== null): ?>
        <section class="lp-admin__panel">
            <h2>Link &ldquo;<?= esc_html($linkDownload->title) ?>&rdquo; to a Repository</h2>
            <p>Linking gives this download the Update from GitHub button. Its title and description are left exactly as they are, and you can choose below whether its download link should also point at GitHub. Choose the repository and check how it matches.</p>
            <p class="lp-field__hint">Screenshots already in this download's Media folder are matched to the repository's files by their content, so nothing is downloaded or duplicated. Anything on GitHub the download doesn't have yet is offered as new when you next update.</p>
            <p><a class="lp-button" href="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $linkId ?>">Back to the download</a></p>
        </section>
    <?php endif; ?>

    <section class="lp-admin__panel">
        <h2>GitHub Account</h2>
        <p class="lp-field__hint">Your public repositories are listed below so you can pick one. This only reads public information, and GitHub is contacted only when you use this screen.</p>
        <form method="post" action="<?= esc_url($endpoint . $linkQuery) ?>">
            <?= Csrf::field('github_account') ?>
            <input type="hidden" name="form" value="github_account">
            <p class="lp-field">
                <label for="github-account">GitHub username</label>
                <input type="text" id="github-account" name="github_account" value="<?= esc_attr($account) ?>" maxlength="39" spellcheck="false" autocomplete="off">
            </p>
            <button type="submit" class="lp-button lp-button--primary">Save Account</button>
        </form>
        <?php if ($account !== ''): ?>
            <form method="post" action="<?= esc_url($endpoint . $linkQuery) ?>" class="lp-admin__inline-form">
                <?= Csrf::field('github_refresh') ?>
                <input type="hidden" name="form" value="github_refresh">
                <button type="submit" class="lp-button">Refresh Repository List</button>
                <?php if ($choices['fetchedAt'] > 0): ?>
                    <span class="lp-field__hint">Listed <?= esc_html(date('Y-m-d H:i', $choices['fetchedAt'])) ?>.</span>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>

    <section class="lp-admin__panel">
        <h2>Choose a Repository</h2>
        <form method="get" action="<?= esc_url($endpoint) ?>">
            <?php if ($linkId > 0): ?>
                <input type="hidden" name="link" value="<?= (int) $linkId ?>">
            <?php endif; ?>
            <?php if ($account !== ''): ?>
                <p class="lp-field">
                    <label for="github-repo">Repository</label>
                    <select id="github-repo" name="repo">
                        <option value="">Choose a repository&hellip;</option>
                        <?php foreach ($choices['repos'] as $repository): ?>
                            <?php
                            $notes = array_filter([
                                isset($choices['imported'][$repository['name']]) ? 'already imported' : '',
                                $repository['fork'] ? 'fork' : '',
                                $repository['archived'] ? 'archived' : '',
                            ]);
                            ?>
                            <option value="<?= esc_attr($repository['name']) ?>" <?= $repoName === $repository['name'] ? 'selected' : '' ?>>
                                <?= esc_html($repository['name'] . ($notes !== [] ? ' (' . implode(', ', $notes) . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
            <p class="lp-field">
                <label for="github-repo-url">Or a repository address</label>
                <input type="text" id="github-repo-url" name="repo_url" value="<?= esc_attr($repoUrl) ?>" placeholder="https://github.com/owner/repository" spellcheck="false" autocomplete="off">
                <span class="lp-field__hint">For a repository outside your account. It is used instead of the list above when filled in.</span>
            </p>
            <button type="submit" class="lp-button lp-button--primary"><?= $linkId > 0 ? 'Check Match' : 'Preview' ?></button>
        </form>
    </section>

    <?php if ($linkPreview !== null): ?>
        <?php $linkRef = $linkPreview['ref']; ?>
        <section class="lp-admin__panel">
            <h2>Match: <?= esc_html($linkRef->fullName()) ?></h2>
            <p>
                <?= count($linkPreview['matched']) ?> of this download's screenshots already match a file in the repository.
                <?= count($linkPreview['unmatched']) ?> file<?= count($linkPreview['unmatched']) === 1 ? '' : 's' ?> in the repository <?= count($linkPreview['unmatched']) === 1 ? 'is' : 'are' ?> not on this download yet.
            </p>

            <?php if ($linkPreview['matched'] !== []): ?>
                <table class="lp-table">
                    <thead>
                        <tr><th scope="col">In the repository</th><th scope="col">Matches this download's file</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linkPreview['matched'] as $match): ?>
                            <tr>
                                <td><code><?= esc_html($match['path']) ?></code></td>
                                <td><?= esc_html($match['mediaName']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="lp-field__hint">None of the images in this download's Media folder are in the repository. Linking still works: the repository's screenshots will be offered as new.</p>
            <?php endif; ?>

            <p class="lp-field__hint">
                Linking records the repository (branch <code><?= esc_html($linkPreview['branch']) ?></code>) and which files match. The README is not applied: after linking, the update screen offers it with the box unticked if your description differs, so your own text is never replaced by accident.
            </p>

            <form method="post" action="<?= esc_url($endpoint) ?>?link=<?= (int) $linkId ?>">
                <?= Csrf::field('github_link') ?>
                <?php if ($linkPreview['switchesLink']): ?>
                    <p class="lp-field">
                        <label>
                            <input type="checkbox" name="switch_link" value="1" checked>
                            Make the download link GitHub's zip of the <code><?= esc_html($linkPreview['branch']) ?></code> branch, as an import does
                        </label>
                        <span class="lp-field__hint"><code><?= esc_html($linkPreview['zipUrl']) ?></code> always serves the latest code, so nothing has to be stored on your server.<?= $linkPreview['replacesFile'] ? ' This download currently serves an uploaded file; that file is removed from the Media Library if nothing else uses it.' : '' ?> Untick it to keep the current link.</span>
                    </p>
                <?php else: ?>
                    <p class="lp-field__hint">This download's link already points at this repository's zip, so it stays as it is.</p>
                <?php endif; ?>
                <input type="hidden" name="form" value="github_link">
                <input type="hidden" name="download_id" value="<?= (int) $linkId ?>">
                <input type="hidden" name="owner" value="<?= esc_attr($linkRef->owner) ?>">
                <input type="hidden" name="repo" value="<?= esc_attr($linkRef->repo) ?>">
                <button type="submit" class="lp-button lp-button--primary">Link to This Repository</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($preview !== null): ?>
        <?php $previewRef = $preview['ref']; ?>
        <?php if ($preview['existingDownloadId'] !== null): ?>
            <div class="lp-alert lp-alert--warning">
                This repository has already been imported.
                <a href="<?= esc_url($endpoint) ?>?update=<?= (int) $preview['existingDownloadId'] ?>">Update it instead</a>,
                or <a href="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $preview['existingDownloadId'] ?>">open the download</a>.
                To import it again as a second download, tick the box at the bottom of the form.
            </div>
        <?php endif; ?>

        <section class="lp-admin__panel">
            <h2>Preview: <?= esc_html($previewRef->fullName()) ?></h2>
            <form method="post" action="<?= esc_url($endpoint) ?>" data-lp-github-import data-lp-github-mode="import" data-lp-github-endpoint="<?= esc_url($endpoint) ?>" data-lp-github-csrf="<?= esc_attr($csrfRun) ?>">
                <input type="hidden" name="owner" value="<?= esc_attr($previewRef->owner) ?>">
                <input type="hidden" name="repo" value="<?= esc_attr($previewRef->repo) ?>">

                <p class="lp-field">
                    <label for="github-title">Title</label>
                    <input type="text" id="github-title" name="title" value="<?= esc_attr($preview['title']) ?>" required>
                </p>

                <p class="lp-field">
                    <label for="github-category">Category</label>
                    <select id="github-category" name="category_id">
                        <option value="0">(Uncategorized)</option>
                        <?php foreach ($allCategories as $category): ?>
                            <option value="<?= (int) $category->id ?>"><?= esc_html($category->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="lp-field">
                    <label for="github-description">Description (Markdown)</label>
                    <textarea id="github-description" name="description" rows="16" spellcheck="false"><?= esc_html($preview['description']) ?></textarea>
                    <span class="lp-field__hint">
                        <?= $preview['hasReadme'] ? 'The README, without its own screenshots. ' : 'This repository has no README. ' ?>
                        The full editor, with visual editing, is available on the download once it is imported.
                    </span>
                </p>

                <fieldset class="lp-field">
                    <legend>Screenshots</legend>
                    <?php if ($preview['screenshots'] === []): ?>
                        <span class="lp-field__hint">No screenshots were found in this repository. The download is still created without them.</span>
                    <?php else: ?>
                        <span class="lp-field__hint">Copied into a Media folder named <strong><?= esc_html($preview['repoName']) ?></strong> and shown after the description. The first one is the download's thumbnail.</span>
                        <div class="lp-github-shots">
                            <?php foreach ($preview['screenshots'] as $shot): ?>
                                <label class="lp-github-shots__item">
                                    <input type="checkbox" name="paths[]" value="<?= esc_attr($shot['path']) ?>" <?= $shot['tooLarge'] ? 'disabled' : 'checked' ?>>
                                    <img class="lp-github-shots__thumb" src="<?= esc_url($previewRef->rawUrl($preview['branch'], $shot['path'])) ?>" alt="" loading="lazy">
                                    <span class="lp-github-shots__name"><?= esc_html($shot['name']) ?></span>
                                    <span class="lp-field__hint"><?= $shot['tooLarge'] ? 'Too large to import (' . esc_html($formatSize($shot['size'])) . ')' : esc_html($formatSize($shot['size'])) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>

                <p class="lp-field">
                    <span class="lp-field__hint">The download links to GitHub's zip of the <code><?= esc_html($preview['branch']) ?></code> branch: <code><?= esc_html($previewRef->archiveUrl($preview['branch'])) ?></code></span>
                </p>

                <?php if ($preview['existingDownloadId'] !== null): ?>
                    <p class="lp-field">
                        <label class="lp-field--checkbox"><input type="checkbox" name="allow_duplicate" value="1"> Import it again as a second download</label>
                    </p>
                <?php endif; ?>

                <button type="submit" class="lp-button lp-button--primary">Import</button>
            </form>

            <div data-lp-github-progress hidden>
                <progress data-lp-github-bar max="1" value="0"></progress>
                <p class="lp-field__hint" data-lp-github-status role="status"></p>
                <ul data-lp-github-failures></ul>
            </div>
            <noscript><p class="lp-field__hint">This screen needs JavaScript to import.</p></noscript>
        </section>
    <?php endif; ?>
<?php endif; ?>
