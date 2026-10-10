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
use LumoraPress\Plugins\Downloads\GitHubTokenStore;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
$downloadCategories = new DownloadCategoryService($kernel->database, $tablePrefix);
$sourceStore = new GitHubSourceStore($kernel->database, $tablePrefix);
$downloadService = new DownloadService($kernel->database, $tablePrefix, $kernel->media, $kernel->redirects, $downloadCategories, $kernel->thumbnails, $kernel->mediaStats);
$tokenStore = new GitHubTokenStore($kernel->config);
$importer = new GitHubImportService(
    new GitHubClient(token: $tokenStore->token()),
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

if ($isPost && ($_POST['form'] ?? null) === 'github_token' && $currentUser->can('upload_files') && Csrf::verify('github_token', $token)) {
    $removing = ($_POST['remove_token'] ?? '') === '1';
    $newToken = $removing ? '' : trim((string) ($_POST['github_token'] ?? ''));

    if ($newToken === '' && !$removing) {
        $error = 'Paste an access token to save, or use Remove Token.';
    } elseif ($tokenStore->save($newToken)) {
        header('Location: ' . admin_url('downloads/import-github') . '?token_saved=' . ($removing ? '0' : '1') . (isset($_GET['link']) ? '&link=' . (int) $_GET['link'] : ''));
        exit;
    } else {
        $error = $tokenStore->canEncrypt()
            ? 'That does not look like a GitHub access token.'
            : 'This server cannot store the token securely, so it was not saved.';
    }
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

// A preview or update check asked for by the page's script returns just the result panel, so the screen can show it
// in place; opening the same address directly renders the full page.
if (!$isPost && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    ob_start();
    require dirname(__DIR__) . '/partials/github-import-result.php';
    echo json_encode(['html' => (string) ob_get_clean(), 'error' => $error]);
    exit;
}
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

<?php if (isset($_GET['token_saved'])): ?>
    <div class="lp-alert lp-alert--success"><?= $_GET['token_saved'] === '1' ? 'GitHub access token saved.' : 'GitHub access token removed.' ?></div>
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
            <a class="lp-button lp-button--primary" href="<?= esc_url($endpoint) ?>?update=<?= (int) $updateId ?>&amp;check=1" data-lp-github-lookup>Check for changes</a>
            <a class="lp-button" href="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $updateId ?>">Back to the download</a>
        </p>
    </section>

    <div data-lp-github-result aria-live="polite">
        <?php require dirname(__DIR__) . '/partials/github-import-result.php'; ?>
    </div>

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
        <h2>GitHub Access Token (optional)</h2>
        <p class="lp-field__hint">Without a token GitHub allows only 60 requests an hour from this server, which a few updates can use up. A token raises that to 5,000. Create one at GitHub &rsaquo; Settings &rsaquo; Developer settings &rsaquo; Personal access tokens, with no permissions selected, since only public information is read. It is stored encrypted and sent only to GitHub's API.</p>
        <form method="post" action="<?= esc_url($endpoint . $linkQuery) ?>">
            <?= Csrf::field('github_token') ?>
            <input type="hidden" name="form" value="github_token">
            <p class="lp-field">
                <label for="github-token">Access token</label>
                <input type="password" id="github-token" name="github_token" value="" maxlength="255" spellcheck="false" autocomplete="new-password" placeholder="<?= $tokenStore->isSet() ? 'A token is saved. Paste a new one to replace it.' : '' ?>">
            </p>
            <button type="submit" class="lp-button lp-button--primary">Save Token</button>
            <?php if ($tokenStore->isSet()): ?>
                <button type="submit" name="remove_token" value="1" class="lp-button">Remove Token</button>
            <?php endif; ?>
        </form>
    </section>

    <section class="lp-admin__panel">
        <h2>Choose a Repository</h2>
        <p class="lp-field__hint">Images in the README that stay on another site, such as badges, are added to <a href="<?= esc_url(admin_url('settings/security')) ?>">Trusted Image Sources</a> when the download is saved, so they display. You can remove them there at any time.</p>
        <form method="get" action="<?= esc_url($endpoint) ?>" data-lp-github-lookup>
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

    <div data-lp-github-result aria-live="polite">
        <?php require dirname(__DIR__) . '/partials/github-import-result.php'; ?>
    </div>

<?php endif; ?>
