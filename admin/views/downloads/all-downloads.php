<?php

/**
 * The admin Downloads > All Downloads screen (LPP-009): a real list table with status tabs, category filter, bulk actions, and Duplicate/Trash row actions.
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
use LumoraPress\Plugins\Downloads\DownloadCategoryService;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\Downloads\DownloadStatus;
use LumoraPress\Plugins\Downloads\DownloadType;
use LumoraPress\Plugins\Downloads\GitHubImportService;
use LumoraPress\Plugins\Downloads\GitHubSourceStore;
use LumoraPress\Plugins\Downloads\GitHubUpdateScanner;

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

$error = null;

$githubStore = new GitHubSourceStore($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$githubScanner = new GitHubUpdateScanner(GitHubImportService::fromKernel($kernel), $githubStore, $kernel->config);

// The bulk update check: the page's script posts here once per small batch and gets JSON back.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'github_check_run' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    // admin/index.php has already buffered the page shell; discard it so only JSON is sent.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('upload_files') || !Csrf::verify('github_check_run', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted. Reload the page and try again.', 'csrfToken' => Csrf::token('github_check_run')]);
        exit;
    }

    echo json_encode($githubScanner->scanBatch((int) ($_POST['run_started'] ?? time())) + ['csrfToken' => Csrf::token('github_check_run')]);
    exit;
}

// No dedicated Controller class — this plugin handles its own POST bodies
// inline via DownloadService calls, its established convention.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $id = (int) ($_POST['id'] ?? 0);

    if ($form === 'trash' && Csrf::verify('download_trash_' . $id, $csrfToken)) {
        $downloads->trash($id);
        redirect(admin_url('downloads/all-downloads') . '?trashed=1');
    } elseif ($form === 'restore' && Csrf::verify('download_restore_' . $id, $csrfToken)) {
        $downloads->restore($id);
        redirect(admin_url('downloads/all-downloads') . '?status=trashed&restored=1');
    } elseif ($form === 'delete_permanently' && Csrf::verify('download_delete_permanently_' . $id, $csrfToken)) {
        $downloads->delete($id);
        redirect(admin_url('downloads/all-downloads') . '?status=trashed&deleted=1');
    } elseif ($form === 'duplicate' && Csrf::verify('download_duplicate_' . $id, $csrfToken)) {
        $downloads->duplicate($id);
        redirect(admin_url('downloads/all-downloads') . '?duplicated=1');
    } elseif ($form === 'bulk_action' && Csrf::verify('downloads_bulk_action', $csrfToken)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_map('intval', (array) ($_POST['download_ids'] ?? []));
        $statusPassthrough = (string) ($_POST['status'] ?? '');

        foreach ($ids as $bulkId) {
            match ($bulkAction) {
                'trash' => $downloads->trash($bulkId),
                'restore' => $downloads->restore($bulkId),
                'delete_permanently' => $downloads->delete($bulkId),
                default => null,
            };
        }

        redirect(admin_url('downloads/all-downloads') . '?bulk_done=1' . ($statusPassthrough !== '' ? '&status=' . urlencode($statusPassthrough) : ''));
    }
}
?>
<h1 class="lp-admin__title">Downloads</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Download saved.</div>
<?php endif; ?>

<?php if (isset($_GET['trashed'])): ?>
    <div class="lp-alert lp-alert--success">Download moved to Trash.</div>
<?php endif; ?>

<?php if (isset($_GET['restored'])): ?>
    <div class="lp-alert lp-alert--success">Download restored.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Download permanently deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['duplicated'])): ?>
    <div class="lp-alert lp-alert--success">Download duplicated.</div>
<?php endif; ?>

<?php if (isset($_GET['bulk_done'])): ?>
    <div class="lp-alert lp-alert--success">Bulk action applied.</div>
<?php endif; ?>

<p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('downloads/add-new')) ?>">Add New Download</a></p>

<?php
$page = max(1, (int) ($_GET['paged'] ?? 1));
$statusFilter = ((string) ($_GET['status'] ?? '')) === 'trashed' ? DownloadStatus::Trashed : null;
$isTrashView = $statusFilter === DownloadStatus::Trashed;

$categoryFilter = (int) ($_GET['category_id'] ?? 0);
$termFilter = trim((string) ($_GET['q'] ?? ''));
$noFileFilter = !$isTrashView && isset($_GET['no_file']);
$orderBy = (string) ($_GET['orderby'] ?? 'title');
$orderDir = (string) ($_GET['order'] ?? 'asc');

$listFilters = ['term' => $termFilter, 'categoryId' => $categoryFilter, 'noFile' => $noFileFilter];
$pagination = $downloads->paginateForAdmin($page, statusFilter: $statusFilter, filters: $listFilters, orderBy: $orderBy, orderDir: $orderDir);

// One query for the whole page of rows, to flag the ones imported from GitHub.
$githubRepositories = $githubStore
    ->repositoriesForDownloads(array_map(static fn ($listedDownload): int => $listedDownload->id, $pagination['downloads']));

$githubTotal = $githubScanner->total();
$githubResults = $githubScanner->results();
$canCheckGitHub = $githubTotal > 0 && $currentUser->can('upload_files');
// A check skips downloads already answered in the last hour, so continuing after GitHub's limit
// stopped one picks up where it left off instead of starting over.
$githubRunStarted = time() - 3600;

$statusCounts = [
    DownloadStatus::Live->value => $downloads->countByStatus(DownloadStatus::Live),
    DownloadStatus::Trashed->value => $downloads->countByStatus(DownloadStatus::Trashed),
];
$noFileCount = $downloads->countWithoutFile();
$statusLinks = [
    '' => 'Live (' . $statusCounts[DownloadStatus::Live->value] . ')',
    'no_file' => 'No file attached (' . $noFileCount . ')',
    'trashed' => 'Trash (' . $statusCounts[DownloadStatus::Trashed->value] . ')',
];

$allCategories = $downloadCategories->listAll();
$categoriesById = [];

foreach ($allCategories as $category) {
    $categoriesById[$category->id] = $category;
}

$sortLink = static function (string $column) use ($orderBy, $orderDir, $statusFilter, $categoryFilter, $noFileFilter): string {
    $nextDir = $orderBy === $column && $orderDir === 'asc' ? 'desc' : 'asc';
    $query = ['orderby' => $column, 'order' => $nextDir];

    if ($statusFilter !== null) {
        $query['status'] = $statusFilter->value;
    }

    if ($categoryFilter > 0) {
        $query['category_id'] = $categoryFilter;
    }

    if ($noFileFilter) {
        $query['no_file'] = 1;
    }

    return admin_url('downloads/all-downloads') . '?' . http_build_query($query);
};
?>

<p class="lp-admin__filters">
    <?php foreach ($statusLinks as $value => $label): ?>
        <?php
        $linkQuery = match ($value) {
            'trashed' => '?status=trashed',
            'no_file' => '?no_file=1',
            default => '',
        };
        $isActiveLink = $value === 'no_file' ? $noFileFilter : (!$noFileFilter && ($statusFilter?->value ?? '') === $value);
        ?>
        <a
            href="<?= esc_url(admin_url('downloads/all-downloads') . $linkQuery) ?>"
            class="<?= $isActiveLink ? 'is-active' : '' ?>"
        ><?= esc_html($label) ?></a>
    <?php endforeach; ?>
</p>

<section class="lp-admin__panel">
    <details class="lp-admin__collapsible" <?= ($categoryFilter > 0 || $termFilter !== '') ? 'open' : '' ?>>
    <summary>Search &amp; Filter</summary>
    <div class="lp-admin__collapsible__body">
    <form method="get" action="<?= esc_url(admin_url('downloads/all-downloads')) ?>" class="lp-admin__filter-form">
        <?php if ($statusFilter !== null): ?>
            <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
        <?php endif; ?>
        <?php if ($noFileFilter): ?>
            <input type="hidden" name="no_file" value="1">
        <?php endif; ?>
        <p class="lp-field">
            <label for="downloads-category-filter">Category</label>
            <select id="downloads-category-filter" name="category_id">
                <option value="0">All categories</option>
                <?php foreach ($allCategories as $filterCategory): ?>
                    <option value="<?= (int) $filterCategory->id ?>" <?= $categoryFilter === $filterCategory->id ? 'selected' : '' ?>><?= esc_html($filterCategory->name) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="lp-field">
            <label for="downloads-q">Search title</label>
            <input type="text" id="downloads-q" name="q" value="<?= esc_attr($termFilter) ?>">
        </p>
        <button type="submit" class="lp-button">Filter</button>
    </form>
    </div>
    </details>
</section>

<?php if ($canCheckGitHub): ?>
    <?php
    $githubWithChanges = count(array_filter($githubResults, static fn (array $result): bool => $result['status'] === GitHubUpdateScanner::STATUS_CHANGES));
    $githubUnavailable = count(array_filter($githubResults, static fn (array $result): bool => $result['status'] === GitHubUpdateScanner::STATUS_ERROR));
    ?>
    <section class="lp-admin__panel" data-lp-github-check>
        <h2>GitHub Updates</h2>
        <p class="lp-field__hint">
            <?= (int) $githubTotal ?> download<?= $githubTotal === 1 ? ' was' : 's were' ?> imported from or linked to GitHub. Checking asks GitHub whether each repository changed since it was last synced, and marks the ones that did with an Update available badge. Nothing runs by itself, and nothing is changed by a check: each update is reviewed and applied from the download's own screen.
            Without a GitHub access token (Downloads &rsaquo; Import from GitHub) GitHub allows only 60 requests an hour, and each download costs at least one, so a long list may stop part-way. Click again later to carry on; downloads checked in the last hour are skipped.
        </p>
        <?php if ($githubResults !== []): ?>
            <p><?= (int) $githubWithChanges ?> with changes to review<?= $githubUnavailable > 0 ? ', ' . (int) $githubUnavailable . ' whose repository could not be read' : '' ?>, from the checks so far.</p>
        <?php endif; ?>
        <form method="post" action="<?= esc_url(admin_url('downloads/all-downloads')) ?>" data-lp-github-check-form data-lp-github-check-total="<?= (int) $githubTotal ?>">
            <?= Csrf::field('github_check_run') ?>
            <input type="hidden" name="form" value="github_check_run">
            <input type="hidden" name="run_started" value="<?= (int) $githubRunStarted ?>">
            <button type="submit" class="lp-button lp-button--secondary">Check for Updates</button>
        </form>
        <div data-lp-github-check-progress hidden>
            <progress data-lp-github-check-bar max="1" value="0"></progress>
            <p role="status" data-lp-github-check-status></p>
        </div>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <?php if ($pagination['downloads'] === []): ?>
        <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : ($noFileFilter ? 'Every download has a file attached.' : 'No downloads yet.') ?></p>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('downloads/all-downloads')) ?>" data-lp-bulk-form>
            <?= Csrf::field('downloads_bulk_action') ?>
            <input type="hidden" name="form" value="bulk_action">
            <?php if ($statusFilter !== null): ?>
                <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
            <?php endif; ?>

            <p class="lp-admin__bulk-actions">
                <label class="lp-visually-hidden" for="downloads-bulk-action">Bulk action</label>
                <select id="downloads-bulk-action" name="bulk_action">
                    <option value="">Bulk actions</option>
                    <?php if ($isTrashView): ?>
                        <option value="restore">Restore</option>
                        <option value="delete_permanently">Delete Permanently</option>
                    <?php else: ?>
                        <option value="trash">Move to Trash</option>
                    <?php endif; ?>
                </select>
                <button type="submit" class="lp-button lp-button--secondary">Apply</button>
            </p>

            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">
                            <label class="lp-visually-hidden" for="downloads-select-all">Select all</label>
                            <input type="checkbox" id="downloads-select-all" data-lp-select-all="download_ids[]" data-lp-select-all-scope="table">
                        </th>
                        <th scope="col"><a href="<?= esc_url($sortLink('id')) ?>">ID</a></th>
                        <th scope="col"><a href="<?= esc_url($sortLink('title')) ?>">Title</a></th>
                        <th scope="col">Category</th>
                        <th scope="col">Shortcode</th>
                        <th scope="col">Type</th>
                        <th scope="col">Status</th>
                        <th scope="col">Downloads</th>
                        <th scope="col"><a href="<?= esc_url($sortLink('date')) ?>">Date</a></th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagination['downloads'] as $listedDownload): ?>
                        <tr>
                            <td>
                                <label class="lp-visually-hidden" for="download-select-<?= (int) $listedDownload->id ?>">Select "<?= esc_html($listedDownload->title) ?>"</label>
                                <input type="checkbox" id="download-select-<?= (int) $listedDownload->id ?>" name="download_ids[]" value="<?= (int) $listedDownload->id ?>">
                            </td>
                            <td><?= (int) $listedDownload->id ?></td>
                            <td>
                                <?php if (!$isTrashView): ?>
                                    <a href="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $listedDownload->id ?>"><?= esc_html($listedDownload->title) ?></a>
                                <?php else: ?>
                                    <?= esc_html($listedDownload->title) ?>
                                <?php endif; ?>
                                <?php if ($listedDownload->url === ''): ?>
                                    <br>
                                    <span class="lp-status-badge lp-status-badge--warning" title="Imported without a file/URL — attach one from the Edit Download screen. Hidden from the public site until then.">No file attached</span>
                                <?php endif; ?>
                                <?php if (isset($githubRepositories[$listedDownload->id])): ?>
                                    <br>
                                    <a class="lp-status-badge" href="https://github.com/<?= esc_attr($githubRepositories[$listedDownload->id]) ?>" title="Imported from <?= esc_attr($githubRepositories[$listedDownload->id]) ?>">GitHub</a>
                                    <?php $githubResult = $githubResults[$listedDownload->id] ?? null; ?>
                                    <?php if (($githubResult['status'] ?? '') === GitHubUpdateScanner::STATUS_CHANGES): ?>
                                        <a class="lp-status-badge lp-status-badge--warning" href="<?= esc_url(admin_url('downloads/import-github') . '?update=' . (int) $listedDownload->id) ?>">Update available</a>
                                    <?php elseif (($githubResult['status'] ?? '') === GitHubUpdateScanner::STATUS_ERROR): ?>
                                        <span class="lp-status-badge lp-status-badge--warning" title="<?= esc_attr($githubResult['message']) ?>">Repository unavailable</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= esc_html($listedDownload->categoryId !== null ? ($categoriesById[$listedDownload->categoryId]?->name ?? 'Uncategorized') : 'Uncategorized') ?></td>
                            <td>
                                <?php /* Nothing renders for a trashed download or one with nothing to serve, so no shortcode is offered for it. */ ?>
                                <?php if ($listedDownload->trashedAt === null && $listedDownload->url !== ''): ?>
                                    <code class="lp-download-shortcode">[lumora_downloads download_id="<?= (int) $listedDownload->id ?>"]</code>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?= $listedDownload->type === DownloadType::File ? 'File' : 'URL' ?></td>
                            <td>
                                <span class="lp-status-badge lp-status-badge--<?= esc_attr($listedDownload->status()->value) ?>">
                                    <?= esc_html($listedDownload->status() === DownloadStatus::Live ? 'Live' : 'Trashed') ?>
                                </span>
                            </td>
                            <td><?= (int) $downloads->downloadCount($listedDownload) ?></td>
                            <td><?= esc_html($listedDownload->createdAt->format('M j, Y')) ?></td>
                            <td class="lp-admin__row-actions">
                                <?php if ($isTrashView): ?>
                                    <?php $restoreFormId = 'download-restore-form-' . $listedDownload->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('download_restore_' . $listedDownload->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                        <input type="hidden" name="form" value="restore" form="<?= esc_attr($restoreFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedDownload->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                    </span>
                                    <?php $deletePermFormId = 'download-delete-permanently-form-' . $listedDownload->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('download_delete_permanently_' . $listedDownload->id)) ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                        <input type="hidden" name="form" value="delete_permanently" form="<?= esc_attr($deletePermFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedDownload->id ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deletePermFormId) ?>" data-lp-confirm="Permanently delete this download? This cannot be undone.">Delete Permanently</button>
                                    </span>
                                <?php else: ?>
                                    <?php $duplicateFormId = 'download-duplicate-form-' . $listedDownload->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('download_duplicate_' . $listedDownload->id)) ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                        <input type="hidden" name="form" value="duplicate" form="<?= esc_attr($duplicateFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedDownload->id ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($duplicateFormId) ?>">Duplicate</button>
                                    </span>
                                    <?php $trashFormId = 'download-trash-form-' . $listedDownload->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('download_trash_' . $listedDownload->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                        <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedDownload->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this download to the Trash?">Trash</button>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <?php
        // Out-of-band target forms for each row action button — a nested
        // <form> is invalid HTML and would merge into the bulk-action form.
        foreach ($pagination['downloads'] as $listedDownload):
            if ($isTrashView):
                ?>
                <form id="download-restore-form-<?= (int) $listedDownload->id ?>" method="post" action="<?= esc_url(admin_url('downloads/all-downloads')) ?>"></form>
                <form id="download-delete-permanently-form-<?= (int) $listedDownload->id ?>" method="post" action="<?= esc_url(admin_url('downloads/all-downloads')) ?>"></form>
                <?php
            else:
                ?>
                <form id="download-duplicate-form-<?= (int) $listedDownload->id ?>" method="post" action="<?= esc_url(admin_url('downloads/all-downloads')) ?>"></form>
                <form id="download-trash-form-<?= (int) $listedDownload->id ?>" method="post" action="<?= esc_url(admin_url('downloads/all-downloads')) ?>"></form>
                <?php
            endif;
        endforeach;
        ?>

        <?php render_pagination($pagination, 'Downloads pagination'); ?>
    <?php endif; ?>
</section>
