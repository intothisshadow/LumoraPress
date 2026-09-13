<?php

/**
 * The admin Tags management screen.
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

$tagService = $kernel->tags;
$canDeleteTags = $currentUser->can('delete_posts');

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'save' && Csrf::verify('tag_save', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? $tagService->findById($id) : null;

        if ($id > 0 && $existing === null) {
            header('Location: ' . admin_url('posts/tags') . '?error=forbidden');
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($name === '') {
            $error = 'A name is required.';

            // The form posts to a plain URL with no ?action= query string, so without this,
            // a validation error here would fall through to the list view below instead of
            // redisplaying the form the error banner is actually about.
            $action = $existing === null ? 'new' : 'edit';
            $editingId = $existing?->id;
        } else {
            try {
                $tag = $existing === null
                    ? $tagService->create($name, $description, $slug !== '' ? $slug : null)
                    : $tagService->update($id, $name, $description, $slug !== '' ? $slug : null);

                header('Location: ' . admin_url('posts/tags') . '?action=edit&id=' . $tag->id . '&saved=1');
                exit;
            } catch (\InvalidArgumentException $exception) {
                // Same redisplay reasoning as the empty-name branch above —
                // reached only for the max-length case, since $name === ''
                // is already caught before this point.
                $error = $exception->getMessage();
                $action = $existing === null ? 'new' : 'edit';
                $editingId = $existing?->id;
            }
        }
    } elseif ($form === 'trash') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('tag_trash_' . $id, $token)) {
            header('Location: ' . admin_url('posts/tags'));
            exit;
        }

        if ($canDeleteTags && $id > 0) {
            $tagService->trash($id);
        }

        header('Location: ' . admin_url('posts/tags') . '?trashed=1');
        exit;
    } elseif ($form === 'restore_tag') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('tag_restore_' . $id, $token)) {
            header('Location: ' . admin_url('posts/tags'));
            exit;
        }

        if ($canDeleteTags && $id > 0) {
            $tagService->restore($id);
        }

        header('Location: ' . admin_url('posts/tags') . '?status=trash&tag_restored=1');
        exit;
    } elseif ($form === 'delete_permanently') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('tag_delete_permanently_' . $id, $token)) {
            header('Location: ' . admin_url('posts/tags'));
            exit;
        }

        $existing = $id > 0 ? $tagService->findById($id) : null;

        // Permanent delete is only offered for tags already in the Trash
        // — Move to Trash is the only reachable path to removing one
        // from the "All" view.
        if ($existing !== null && $existing->isTrashed() && $canDeleteTags) {
            $tagService->delete($id);
        }

        header('Location: ' . admin_url('posts/tags') . '?status=trash&tag_deleted=1');
        exit;
    } elseif ($form === 'empty_trash') {
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('tags_empty_trash', $token)) {
            header('Location: ' . admin_url('posts/tags'));
            exit;
        }

        if ($canDeleteTags) {
            $tagService->emptyTrash();
        }

        header('Location: ' . admin_url('posts/tags') . '?status=trash&trash_emptied=1');
        exit;
    } elseif ($form === 'bulk_action' && Csrf::verify('tags_bulk_action', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($_POST['tag_ids'] ?? null) ? $_POST['tag_ids'] : [])));
        $mergeTargetId = (int) ($_POST['merge_target_id'] ?? 0);
        $statusSuffix = isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : '';

        if ($canDeleteTags) {
            if ($bulkAction === 'trash') {
                foreach ($ids as $id) {
                    $tagService->trash($id);
                }
            } elseif ($bulkAction === 'restore') {
                foreach ($ids as $id) {
                    $tagService->restore($id);
                }
            } elseif ($bulkAction === 'delete_permanently') {
                foreach ($ids as $id) {
                    $existing = $tagService->findById($id);

                    if ($existing !== null && $existing->isTrashed()) {
                        $tagService->delete($id);
                    }
                }
            } elseif ($bulkAction === 'merge' && $mergeTargetId > 0) {
                foreach ($ids as $id) {
                    if ($id !== $mergeTargetId) {
                        $tagService->merge($id, $mergeTargetId);
                    }
                }
            } elseif ($bulkAction === 'remove_unused') {
                $tagService->deleteUnused();
            }
        }

        header('Location: ' . admin_url('posts/tags') . $statusSuffix);
        exit;
    }
}

$statusFilter = (string) ($_GET['status'] ?? '');
$isTrashView = $statusFilter === 'trash';

$action ??= is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId ??= isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingTag = null;

if ($action === 'edit') {
    $editingTag = $editingId !== null ? $tagService->findById($editingId) : null;

    if ($editingTag === null) {
        header('Location: ' . admin_url('posts/tags') . '?error=forbidden');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Tags</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Tag saved.</div>
<?php endif; ?>

<?php if (isset($_GET['trashed'])): ?>
    <div class="lp-alert lp-alert--success">Tag moved to Trash.</div>
<?php endif; ?>

<?php if (isset($_GET['tag_restored'])): ?>
    <div class="lp-alert lp-alert--success">Tag restored.</div>
<?php endif; ?>

<?php if (isset($_GET['tag_deleted'])): ?>
    <div class="lp-alert lp-alert--success">Tag permanently deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['trash_emptied'])): ?>
    <div class="lp-alert lp-alert--success">Trash emptied.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">That tag could not be found.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php $tag = $editingTag; ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('posts/tags')) ?>">
            <?= Csrf::field('tag_save') ?>
            <input type="hidden" name="form" value="save">
            <?php if ($tag !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $tag->id ?>">
            <?php endif; ?>

            <p class="lp-field">
                <label for="tag-name">Name</label>
                <input type="text" id="tag-name" name="name" value="<?= esc_attr($tag->name ?? '') ?>" required>
            </p>

            <p class="lp-field">
                <label for="tag-slug">Slug</label>
                <input type="text" id="tag-slug" name="slug" value="<?= esc_attr($tag->slug ?? '') ?>">
                <span class="lp-field__hint">Leave blank to generate one automatically from the name.</span>
            </p>

            <p class="lp-field">
                <label for="tag-description">Description</label>
                <textarea id="tag-description" name="description" rows="3"><?= esc_html($tag->description ?? '') ?></textarea>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save Tag</button>
            <a class="lp-button" href="<?= esc_url(admin_url('posts/tags')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('posts/tags')) ?>?action=new">Add New Tag</a></p>

    <?php
    $trashedTagsCount = $tagService->trashedCount();
    $statusLinks = ['' => 'All (' . $tagService->count() . ')', 'trash' => 'Trash (' . $trashedTagsCount . ')'];

    $termFilter = trim((string) ($_GET['q'] ?? ''));
    $minPostsFilter = (int) ($_GET['min_posts'] ?? 0);
    $dateFromFilter = (string) ($_GET['date_from'] ?? '');
    $dateToFilter = (string) ($_GET['date_to'] ?? '');
    $lastUsedFromFilter = (string) ($_GET['last_used_from'] ?? '');
    $lastUsedToFilter = (string) ($_GET['last_used_to'] ?? '');
    $tagFilters = [
        'term' => $termFilter,
        'minPosts' => $minPostsFilter,
        'dateFrom' => $dateFromFilter,
        'dateTo' => $dateToFilter,
        'lastUsedFrom' => $lastUsedFromFilter,
        'lastUsedTo' => $lastUsedToFilter,
    ];
    $hasActiveFilter = $termFilter !== '' || $minPostsFilter > 0 || $dateFromFilter !== '' || $dateToFilter !== '' || $lastUsedFromFilter !== '' || $lastUsedToFilter !== '';

    $rows = $isTrashView ? $tagService->listTrashedWithPostCounts() : $tagService->listAllWithPostCounts($tagFilters);

    $mostUsedTags = $tagService->mostUsed(5);
    $leastUsedTags = $tagService->leastUsed(5);
    $unusedTagsCount = $tagService->unusedCount();
    $recentlyCreatedTags = $tagService->recentlyCreated(5);
    $recentlyUsedTags = $tagService->recentlyUsed(5);
    ?>

    <p class="lp-admin__filters">
        <?php foreach ($statusLinks as $value => $label): ?>
            <a
                href="<?= esc_url(admin_url('posts/tags')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
                class="<?= $statusFilter === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!$isTrashView): ?>
        <section class="lp-admin__panel">
            <details class="lp-admin__collapsible" <?= $hasActiveFilter ? 'open' : '' ?>>
                <summary>Search &amp; Filter</summary>
                <div class="lp-admin__collapsible__body">
                    <form method="get" action="<?= esc_url(admin_url('posts/tags')) ?>" class="lp-admin__filter-form">
                        <p class="lp-field">
                            <label for="tags-q">Search name or slug</label>
                            <input type="text" id="tags-q" name="q" value="<?= esc_attr($termFilter) ?>">
                        </p>
                        <p class="lp-field">
                            <label for="tags-min-posts">Minimum posts</label>
                            <input type="number" id="tags-min-posts" name="min_posts" min="0" value="<?= $minPostsFilter > 0 ? (int) $minPostsFilter : '' ?>">
                        </p>
                        <p class="lp-field">
                            <label for="tags-date-from">Created from</label>
                            <input type="date" id="tags-date-from" name="date_from" value="<?= esc_attr($dateFromFilter) ?>">
                        </p>
                        <p class="lp-field">
                            <label for="tags-date-to">Created to</label>
                            <input type="date" id="tags-date-to" name="date_to" value="<?= esc_attr($dateToFilter) ?>">
                        </p>
                        <p class="lp-field">
                            <label for="tags-last-used-from">Last used from</label>
                            <input type="date" id="tags-last-used-from" name="last_used_from" value="<?= esc_attr($lastUsedFromFilter) ?>">
                        </p>
                        <p class="lp-field">
                            <label for="tags-last-used-to">Last used to</label>
                            <input type="date" id="tags-last-used-to" name="last_used_to" value="<?= esc_attr($lastUsedToFilter) ?>">
                        </p>
                        <button type="submit" class="lp-button">Filter</button>
                    </form>
                </div>
            </details>
        </section>
    <?php endif; ?>

    <section class="lp-admin__panel">
        <details class="lp-admin__collapsible">
            <summary>Usage Statistics</summary>
            <div class="lp-admin__collapsible__body">
                <p><?= (int) $unusedTagsCount ?> unused <?= $unusedTagsCount === 1 ? 'tag has' : 'tags have' ?> no assigned posts — see "Remove unused tags" under Bulk actions above.</p>

                <div class="lp-admin__grid">
                    <section class="lp-admin__panel">
                        <h2>Most Used</h2>
                        <?php if ($mostUsedTags === []): ?>
                            <p class="lp-admin__widget-placeholder">No tagged posts yet.</p>
                        <?php else: ?>
                            <ul class="lp-admin__meta-list">
                                <?php foreach ($mostUsedTags as $statRow): ?>
                                    <li>
                                        <span><a href="<?= esc_url(admin_url('posts/tags')) ?>?action=edit&id=<?= (int) $statRow['tag']->id ?>"><?= esc_html($statRow['tag']->name) ?></a></span>
                                        <span><?= (int) $statRow['postCount'] ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="lp-admin__panel">
                        <h2>Least Used</h2>
                        <?php if ($leastUsedTags === []): ?>
                            <p class="lp-admin__widget-placeholder">No tagged posts yet.</p>
                        <?php else: ?>
                            <ul class="lp-admin__meta-list">
                                <?php foreach ($leastUsedTags as $statRow): ?>
                                    <li>
                                        <span><a href="<?= esc_url(admin_url('posts/tags')) ?>?action=edit&id=<?= (int) $statRow['tag']->id ?>"><?= esc_html($statRow['tag']->name) ?></a></span>
                                        <span><?= (int) $statRow['postCount'] ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="lp-admin__panel">
                        <h2>Recently Created</h2>
                        <?php if ($recentlyCreatedTags === []): ?>
                            <p class="lp-admin__widget-placeholder">No tags yet.</p>
                        <?php else: ?>
                            <ul class="lp-admin__meta-list">
                                <?php foreach ($recentlyCreatedTags as $recentTag): ?>
                                    <li>
                                        <span><a href="<?= esc_url(admin_url('posts/tags')) ?>?action=edit&id=<?= (int) $recentTag->id ?>"><?= esc_html($recentTag->name) ?></a></span>
                                        <span><?= esc_html($recentTag->createdAt->format('M j, Y')) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="lp-admin__panel">
                        <h2>Recently Used</h2>
                        <?php if ($recentlyUsedTags === []): ?>
                            <p class="lp-admin__widget-placeholder">No tagged posts yet.</p>
                        <?php else: ?>
                            <ul class="lp-admin__meta-list">
                                <?php foreach ($recentlyUsedTags as $statRow): ?>
                                    <li>
                                        <span><a href="<?= esc_url(admin_url('posts/tags')) ?>?action=edit&id=<?= (int) $statRow['tag']->id ?>"><?= esc_html($statRow['tag']->name) ?></a></span>
                                        <span><?= esc_html($statRow['lastUsedAt']->format('M j, Y')) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </details>
    </section>

    <section class="lp-admin__panel">
        <?php if ($isTrashView && $rows !== []): ?>
            <form method="post" action="<?= esc_url(admin_url('posts/tags')) ?>" data-lp-confirm="Permanently delete every tag in the Trash? This cannot be undone.">
                <?= Csrf::field('tags_empty_trash') ?>
                <input type="hidden" name="form" value="empty_trash">
                <button type="submit" class="lp-button lp-button--danger">Empty Trash</button>
            </form>
        <?php endif; ?>

        <?php if ($rows === []): ?>
            <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : ($hasActiveFilter ? 'No tags match these filters.' : 'No tags yet.') ?></p>
        <?php else: ?>
            <form id="tags-bulk-form" method="post" action="<?= esc_url(admin_url('posts/tags')) ?>" data-lp-bulk-form>
                <?= Csrf::field('tags_bulk_action') ?>
                <input type="hidden" name="form" value="bulk_action">
                <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?= esc_attr($statusFilter) ?>">
                <?php endif; ?>

                <?php if ($canDeleteTags): ?>
                    <p class="lp-admin__bulk-actions">
                        <label class="lp-visually-hidden" for="tags-bulk-action">Bulk action</label>
                        <select id="tags-bulk-action" name="bulk_action">
                            <option value="">Bulk actions</option>
                            <?php if ($isTrashView): ?>
                                <option value="restore">Restore</option>
                                <option value="delete_permanently">Delete Permanently</option>
                            <?php else: ?>
                                <option value="trash">Move to Trash</option>
                                <option value="merge">Merge into&hellip;</option>
                                <option value="remove_unused">Remove unused tags</option>
                            <?php endif; ?>
                        </select>
                        <?php if (!$isTrashView): ?>
                            <label class="lp-visually-hidden" for="tags-merge-target">Merge target</label>
                            <select id="tags-merge-target" name="merge_target_id">
                                <option value="0">(select a tag to merge into)</option>
                                <?php foreach ($tagService->listAll() as $mergeTargetOption): ?>
                                    <option value="<?= (int) $mergeTargetOption->id ?>"><?= esc_html($mergeTargetOption->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="lp-button lp-button--secondary" data-lp-confirm="Apply this bulk action to the selected tags?">Apply</button>
                    </p>
                <?php endif; ?>

                <table class="lp-table">
                    <thead>
                        <tr>
                            <?php if ($canDeleteTags): ?>
                                <th scope="col">
                                    <label class="lp-visually-hidden" for="tags-select-all">Select all</label>
                                    <input type="checkbox" id="tags-select-all" data-lp-select-all="tag_ids[]" data-lp-select-all-scope="table">
                                </th>
                            <?php endif; ?>
                            <th scope="col">Name</th>
                            <th scope="col">Slug</th>
                            <th scope="col">Posts</th>
                            <?php if (!$isTrashView): ?>
                                <th scope="col">Last Used</th>
                            <?php endif; ?>
                            <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php $listedTag = $row['tag']; ?>
                            <tr>
                                <?php if ($canDeleteTags): ?>
                                    <td>
                                        <label class="lp-visually-hidden" for="tag-select-<?= (int) $listedTag->id ?>">Select "<?= esc_html($listedTag->name) ?>"</label>
                                        <input type="checkbox" id="tag-select-<?= (int) $listedTag->id ?>" name="tag_ids[]" value="<?= (int) $listedTag->id ?>">
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($isTrashView): ?>
                                        <?= esc_html($listedTag->name) ?>
                                    <?php else: ?>
                                        <a href="<?= esc_url(admin_url('posts/tags')) ?>?action=edit&id=<?= (int) $listedTag->id ?>"><?= esc_html($listedTag->name) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc_html($listedTag->slug) ?></td>
                                <td>
                                    <?php if ($row['postCount'] > 0): ?>
                                        <a href="<?= esc_url(admin_url('posts/all-posts')) ?>?tag=<?= (int) $listedTag->id ?>"><?= (int) $row['postCount'] ?></a>
                                    <?php else: ?>
                                        0
                                    <?php endif; ?>
                                </td>
                                <?php if (!$isTrashView): ?>
                                    <td><?= $row['lastUsedAt'] !== null ? esc_html($row['lastUsedAt']->format('M j, Y')) : '—' ?></td>
                                <?php endif; ?>
                                <td class="lp-admin__row-actions">
                                    <?php if ($canDeleteTags): ?>
                                        <?php if ($isTrashView): ?>
                                            <?php $restoreFormId = 'tag-restore-form-' . $listedTag->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('tag_restore_' . $listedTag->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="form" value="restore_tag" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedTag->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                            </span>
                                            <?php $deletePermFormId = 'tag-delete-permanently-form-' . $listedTag->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('tag_delete_permanently_' . $listedTag->id)) ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="form" value="delete_permanently" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedTag->id ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deletePermFormId) ?>" data-lp-confirm="Permanently delete this tag? This cannot be undone.">Delete Permanently</button>
                                            </span>
                                        <?php else: ?>
                                            <?php $trashFormId = 'tag-trash-form-' . $listedTag->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('tag_trash_' . $listedTag->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedTag->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this tag to the Trash?">Trash</button>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php
            // Out-of-band target forms — standalone <form>s the buttons
            // point at via form="", since a <form> can't nest inside
            // tags-bulk-form.
            if ($canDeleteTags):
                foreach ($rows as $row):
                    $listedTag = $row['tag'];
                    ?>
                    <?php if ($isTrashView): ?>
                        <form id="tag-restore-form-<?= (int) $listedTag->id ?>" method="post" action="<?= esc_url(admin_url('posts/tags')) ?>"></form>
                        <form id="tag-delete-permanently-form-<?= (int) $listedTag->id ?>" method="post" action="<?= esc_url(admin_url('posts/tags')) ?>"></form>
                    <?php else: ?>
                        <form id="tag-trash-form-<?= (int) $listedTag->id ?>" method="post" action="<?= esc_url(admin_url('posts/tags')) ?>"></form>
                    <?php endif; ?>
                    <?php
                endforeach;
            endif;
            ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
