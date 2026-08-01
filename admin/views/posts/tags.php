<?php
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
        } else {
            $tag = $existing === null
                ? $tagService->create($name, $description, $slug !== '' ? $slug : null)
                : $tagService->update($id, $name, $description, $slug !== '' ? $slug : null);

            header('Location: ' . admin_url('posts/tags') . '?action=edit&id=' . $tag->id . '&saved=1');
            exit;
        }
    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('tag_delete_' . $id, $token)) {
            header('Location: ' . admin_url('posts/tags'));
            exit;
        }

        if ($canDeleteTags && $id > 0) {
            $tagService->delete($id);
        }

        header('Location: ' . admin_url('posts/tags'));
        exit;
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
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

    <?php $rows = $tagService->listAllWithPostCounts(); ?>

    <section class="lp-admin__panel">
        <?php if ($rows === []): ?>
            <p class="lp-admin__widget-placeholder">No tags yet.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Posts</th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $listedTag = $row['tag']; ?>
                        <tr>
                            <td>
                                <a href="<?= esc_url(admin_url('posts/tags')) ?>?action=edit&id=<?= (int) $listedTag->id ?>"><?= esc_html($listedTag->name) ?></a>
                            </td>
                            <td><?= esc_html($listedTag->slug) ?></td>
                            <td><?= (int) $row['postCount'] ?></td>
                            <td>
                                <?php if ($canDeleteTags): ?>
                                    <form method="post" action="<?= esc_url(admin_url('posts/tags')) ?>" onsubmit="return confirm('Delete this tag permanently?');">
                                        <?= Csrf::field('tag_delete_' . $listedTag->id) ?>
                                        <input type="hidden" name="form" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $listedTag->id ?>">
                                        <button type="submit" class="lp-button lp-button--link">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>
