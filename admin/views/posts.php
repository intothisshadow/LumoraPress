<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$postService = $kernel->posts;
$canPublish = $currentUser->can('publish_posts');
$canDeletePosts = $currentUser->can('delete_posts');
$canEditOthersPosts = $currentUser->can('edit_others_posts');

$error = null;

/**
 * An author/contributor may only touch their own posts unless they hold
 * edit_others_posts (Administrator/Editor).
 */
$canEditPost = static fn (Post $post): bool => $canEditOthersPosts || $post->authorId === $currentUser->id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'quick_draft' && Csrf::verify('quick_draft', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title !== '') {
            $post = $postService->create(
                title: $title,
                content: trim((string) ($_POST['content'] ?? '')),
                excerpt: '',
                authorId: $currentUser->id,
                status: PostStatus::Draft,
            );

            header('Location: ' . admin_url('posts') . '?action=edit&id=' . $post->id);
            exit;
        }

        $error = 'A title is required to save a draft.';
    } elseif ($form === 'save' && Csrf::verify('post_save', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? $postService->findById($id) : null;

        if ($id > 0 && ($existing === null || !$canEditPost($existing))) {
            header('Location: ' . admin_url('posts') . '?error=forbidden');
            exit;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');
        $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $requestedStatus = PostStatus::tryFrom((string) ($_POST['status'] ?? '')) ?? PostStatus::Draft;

        // Contributors and anyone else without publish_posts can only ever save as a draft.
        $status = $canPublish ? $requestedStatus : PostStatus::Draft;

        $publishedAt = null;

        if ($status === PostStatus::Scheduled) {
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
            $post = $existing === null
                ? $postService->create($title, $content, $excerpt, $currentUser->id, $status, $publishedAt, slug: $slug !== '' ? $slug : null)
                : $postService->update($id, $title, $content, $excerpt, $status, $publishedAt, $existing->featuredImageId, $slug !== '' ? $slug : null);

            $kernel->categories->assignToPost($post->id, is_array($_POST['category_ids'] ?? null) ? $_POST['category_ids'] : []);
            $kernel->tags->assignToPost($post->id, explode(',', (string) ($_POST['tags'] ?? '')));

            header('Location: ' . admin_url('posts') . '?action=edit&id=' . $post->id . '&saved=1');
            exit;
        }
    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('post_delete_' . $id, $token)) {
            header('Location: ' . admin_url('posts'));
            exit;
        }

        $existing = $id > 0 ? $postService->findById($id) : null;

        if ($existing !== null && $canDeletePosts && $canEditPost($existing)) {
            $postService->delete($id);
        }

        header('Location: ' . admin_url('posts'));
        exit;
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingPost = null;

if ($action === 'edit') {
    $editingPost = $editingId !== null ? $postService->findById($editingId) : null;

    if ($editingPost === null || !$canEditPost($editingPost)) {
        header('Location: ' . admin_url('posts') . '?error=forbidden');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Posts</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Post saved.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">You do not have permission to edit that post.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php
    $post = $editingPost;
    $statusOptions = [PostStatus::Draft, PostStatus::Published, PostStatus::Scheduled];
    $allCategories = $kernel->categories->listAll();
    $assignedCategoryIds = $post !== null
        ? array_map(static fn ($category) => $category->id, $kernel->categories->categoriesForPost($post->id))
        : [];
    $allTagNames = array_map(static fn ($tag) => $tag->name, $kernel->tags->listAll());
    $assignedTagNames = $post !== null
        ? array_map(static fn ($tag) => $tag->name, $kernel->tags->tagsForPost($post->id))
        : [];
    ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('posts')) ?>">
            <?= Csrf::field('post_save') ?>
            <input type="hidden" name="form" value="save">
            <?php if ($post !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $post->id ?>">
            <?php endif; ?>

            <p class="lp-field">
                <label for="post-title">Title</label>
                <input type="text" id="post-title" name="title" value="<?= esc_attr($post->title ?? '') ?>" required>
            </p>

            <p class="lp-field">
                <label for="post-slug">Slug</label>
                <input type="text" id="post-slug" name="slug" value="<?= esc_attr($post->slug ?? '') ?>">
                <span class="lp-field__hint">Leave blank to generate one automatically from the title.</span>
            </p>

            <p class="lp-field">
                <label for="post-content">Content</label>
                <textarea id="post-content" name="content" rows="12"><?= esc_html($post->content ?? '') ?></textarea>
            </p>

            <p class="lp-field">
                <label for="post-excerpt">Excerpt</label>
                <textarea id="post-excerpt" name="excerpt" rows="3"><?= esc_html($post->excerpt ?? '') ?></textarea>
            </p>

            <?php if ($allCategories !== []): ?>
                <fieldset class="lp-field lp-field--checklist">
                    <legend>Categories</legend>
                    <?php foreach ($allCategories as $categoryOption): ?>
                        <label class="lp-field--checkbox">
                            <input
                                type="checkbox"
                                name="category_ids[]"
                                value="<?= (int) $categoryOption->id ?>"
                                <?= in_array($categoryOption->id, $assignedCategoryIds, true) ? 'checked' : '' ?>
                            >
                            <?= esc_html($categoryOption->name) ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <div class="lp-field lp-tag-input" data-lp-tag-input data-suggestions="<?= esc_attr(json_encode($allTagNames)) ?>">
                <label for="post-tags">Tags</label>
                <input
                    type="text"
                    id="post-tags"
                    name="tags"
                    value="<?= esc_attr(implode(', ', $assignedTagNames)) ?>"
                    placeholder="Comma-separated, e.g. news, opinion"
                >
                <span class="lp-field__hint">Separate multiple tags with commas. New tags are created automatically.</span>
            </div>

            <?php if ($canPublish): ?>
                <p class="lp-field">
                    <label for="post-status">Status</label>
                    <select id="post-status" name="status">
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?= esc_attr($option->value) ?>" <?= ($post?->status ?? PostStatus::Draft) === $option ? 'selected' : '' ?>>
                                <?= esc_html($option->label()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="lp-field">
                    <label for="post-published-at">Publish date (for scheduled posts)</label>
                    <input
                        type="datetime-local"
                        id="post-published-at"
                        name="published_at"
                        value="<?= esc_attr($post?->publishedAt?->format('Y-m-d\TH:i') ?? '') ?>"
                    >
                </p>
            <?php else: ?>
                <p class="lp-field__hint">Your role can save drafts only; an editor or administrator can publish this post.</p>
            <?php endif; ?>

            <button type="submit" class="lp-button lp-button--primary">Save Post</button>
            <a class="lp-button" href="<?= esc_url(admin_url('posts')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('posts')) ?>?action=new">Add New Post</a></p>

    <?php
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $statusFilter = PostStatus::tryFrom((string) ($_GET['status'] ?? ''));
    $pagination = $postService->paginateForAdmin($page, statusFilter: $statusFilter);
    $statusLinks = ['' => 'All', ...array_combine(
        array_map(static fn (PostStatus $status): string => $status->value, PostStatus::cases()),
        array_map(static fn (PostStatus $status): string => $status->label(), PostStatus::cases()),
    )];
    ?>

    <p class="lp-admin__filters">
        <?php foreach ($statusLinks as $value => $label): ?>
            <a
                href="<?= esc_url(admin_url('posts')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
                class="<?= ($statusFilter?->value ?? '') === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
    </p>

    <section class="lp-admin__panel">
        <?php if ($pagination['posts'] === []): ?>
            <p class="lp-admin__widget-placeholder">No posts yet.</p>
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
                    <?php foreach ($pagination['posts'] as $listedPost): ?>
                        <tr>
                            <td>
                                <?php if ($canEditPost($listedPost)): ?>
                                    <a href="<?= esc_url(admin_url('posts')) ?>?action=edit&id=<?= (int) $listedPost->id ?>"><?= esc_html($listedPost->title) ?></a>
                                <?php else: ?>
                                    <?= esc_html($listedPost->title) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="lp-status-badge lp-status-badge--<?= esc_attr($listedPost->status->value) ?>">
                                    <?= esc_html($listedPost->status->label()) ?>
                                </span>
                            </td>
                            <td><?= esc_html($listedPost->updatedAt->format('M j, Y')) ?></td>
                            <td>
                                <?php if ($canDeletePosts && $canEditPost($listedPost)): ?>
                                    <form method="post" action="<?= esc_url(admin_url('posts')) ?>" onsubmit="return confirm('Delete this post permanently?');">
                                        <?= Csrf::field('post_delete_' . $listedPost->id) ?>
                                        <input type="hidden" name="form" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>">
                                        <button type="submit" class="lp-button lp-button--link">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php render_pagination($pagination); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
