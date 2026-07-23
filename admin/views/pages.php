<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$pageService = $kernel->pages;
$canPublish = $currentUser->can('publish_posts');
$canDeletePages = $currentUser->can('delete_posts');
$canEditOthersPages = $currentUser->can('edit_others_posts');

$error = null;

/**
 * An author/contributor may only touch their own pages unless they hold
 * edit_others_posts (Administrator/Editor) — Pages reuse the Posts
 * capabilities, since no dedicated page capabilities exist yet.
 */
$canEditPage = static fn (Page $page): bool => $canEditOthersPages || $page->authorId === $currentUser->id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'save' && Csrf::verify('page_save', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? $pageService->findById($id) : null;

        if ($id > 0 && ($existing === null || !$canEditPage($existing))) {
            header('Location: ' . admin_url('pages') . '?error=forbidden');
            exit;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');
        $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $requestedStatus = PageStatus::tryFrom((string) ($_POST['status'] ?? '')) ?? PageStatus::Draft;
        $parentId = (int) ($_POST['parent_id'] ?? 0);

        // Contributors and anyone else without publish_posts can only ever save as a draft.
        $status = $canPublish ? $requestedStatus : PageStatus::Draft;

        $publishedAt = null;

        if ($status === PageStatus::Scheduled) {
            $rawPublishedAt = trim((string) ($_POST['published_at'] ?? ''));

            try {
                $publishedAt = $rawPublishedAt !== '' ? new DateTimeImmutable($rawPublishedAt) : null;
            } catch (\Exception) {
                $publishedAt = null;
            }
        }

        if ($title === '') {
            $error = 'A title is required.';
        } else {
            $page = $existing === null
                ? $pageService->create($title, $content, $excerpt, $currentUser->id, $status, $publishedAt, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null)
                : $pageService->update($id, $title, $content, $excerpt, $status, $publishedAt, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null);

            header('Location: ' . admin_url('pages') . '?action=edit&id=' . $page->id . '&saved=1');
            exit;
        }
    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('page_delete_' . $id, $token)) {
            header('Location: ' . admin_url('pages'));
            exit;
        }

        $existing = $id > 0 ? $pageService->findById($id) : null;

        if ($existing !== null && $canDeletePages && $canEditPage($existing)) {
            $pageService->delete($id);
        }

        header('Location: ' . admin_url('pages'));
        exit;
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingPage = null;

if ($action === 'edit') {
    $editingPage = $editingId !== null ? $pageService->findById($editingId) : null;

    if ($editingPage === null || !$canEditPage($editingPage)) {
        header('Location: ' . admin_url('pages') . '?error=forbidden');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Pages</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Page saved.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">You do not have permission to edit that page.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php
    $page = $editingPage;
    $statusOptions = [PageStatus::Draft, PageStatus::Published, PageStatus::Scheduled];
    $parentOptions = $pageService->listAllForParentSelect($page?->id);
    ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('pages')) ?>">
            <?= Csrf::field('page_save') ?>
            <input type="hidden" name="form" value="save">
            <?php if ($page !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $page->id ?>">
            <?php endif; ?>

            <p class="lp-field">
                <label for="page-title">Title</label>
                <input type="text" id="page-title" name="title" value="<?= esc_attr($page->title ?? '') ?>" required>
            </p>

            <p class="lp-field">
                <label for="page-slug">Slug</label>
                <input type="text" id="page-slug" name="slug" value="<?= esc_attr($page->slug ?? '') ?>">
                <span class="lp-field__hint">Leave blank to generate one automatically from the title.</span>
            </p>

            <p class="lp-field">
                <label for="page-content">Content</label>
                <textarea id="page-content" name="content" rows="12"><?= esc_html($page->content ?? '') ?></textarea>
            </p>

            <p class="lp-field">
                <label for="page-excerpt">Excerpt</label>
                <textarea id="page-excerpt" name="excerpt" rows="3"><?= esc_html($page->excerpt ?? '') ?></textarea>
            </p>

            <p class="lp-field">
                <label for="page-parent">Parent Page</label>
                <select id="page-parent" name="parent_id">
                    <option value="0">(No parent)</option>
                    <?php foreach ($parentOptions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= ($page?->parentId ?? 0) === $option['id'] ? 'selected' : '' ?>>
                            <?= esc_html($option['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php if ($canPublish): ?>
                <p class="lp-field">
                    <label for="page-status">Status</label>
                    <select id="page-status" name="status">
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?= esc_attr($option->value) ?>" <?= ($page?->status ?? PageStatus::Draft) === $option ? 'selected' : '' ?>>
                                <?= esc_html($option->label()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="lp-field">
                    <label for="page-published-at">Publish date (for scheduled pages)</label>
                    <input
                        type="datetime-local"
                        id="page-published-at"
                        name="published_at"
                        value="<?= esc_attr($page?->publishedAt?->format('Y-m-d\TH:i') ?? '') ?>"
                    >
                </p>
            <?php else: ?>
                <p class="lp-field__hint">Your role can save drafts only; an editor or administrator can publish this page.</p>
            <?php endif; ?>

            <button type="submit" class="lp-button lp-button--primary">Save Page</button>
            <a class="lp-button" href="<?= esc_url(admin_url('pages')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('pages')) ?>?action=new">Add New Page</a></p>

    <?php
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $statusFilter = PageStatus::tryFrom((string) ($_GET['status'] ?? ''));
    $pagination = $pageService->paginateForAdmin($page, statusFilter: $statusFilter);
    $statusLinks = ['' => 'All', ...array_combine(
        array_map(static fn (PageStatus $status): string => $status->value, PageStatus::cases()),
        array_map(static fn (PageStatus $status): string => $status->label(), PageStatus::cases()),
    )];
    ?>

    <p class="lp-admin__filters">
        <?php foreach ($statusLinks as $value => $label): ?>
            <a
                href="<?= esc_url(admin_url('pages')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
                class="<?= ($statusFilter?->value ?? '') === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
    </p>

    <section class="lp-admin__panel">
        <?php if ($pagination['pages'] === []): ?>
            <p class="lp-admin__widget-placeholder">No pages yet.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Title</th>
                        <th scope="col">Status</th>
                        <th scope="col">Date</th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagination['pages'] as $listedPage): ?>
                        <tr>
                            <td>
                                <?php if ($canEditPage($listedPage)): ?>
                                    <a href="<?= esc_url(admin_url('pages')) ?>?action=edit&id=<?= (int) $listedPage->id ?>"><?= esc_html($listedPage->title) ?></a>
                                <?php else: ?>
                                    <?= esc_html($listedPage->title) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="lp-status-badge lp-status-badge--<?= esc_attr($listedPage->status->value) ?>">
                                    <?= esc_html($listedPage->status->label()) ?>
                                </span>
                            </td>
                            <td><?= esc_html($listedPage->updatedAt->format('M j, Y')) ?></td>
                            <td>
                                <?php if ($canDeletePages && $canEditPage($listedPage)): ?>
                                    <form method="post" action="<?= esc_url(admin_url('pages')) ?>" onsubmit="return confirm('Delete this page permanently?');">
                                        <?= Csrf::field('page_delete_' . $listedPage->id) ?>
                                        <input type="hidden" name="form" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>">
                                        <button type="submit" class="lp-button lp-button--link">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php render_pagination($pagination, 'Pages pagination'); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
