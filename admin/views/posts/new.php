<?php

/**
 * The admin post editor (both new-post and edit-post).
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

use LumoraPress\Controllers\Admin\PostsController;
use LumoraPress\Core\Content\TextDiff;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use LumoraPress\Models\RevisionableType;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$controller = new PostsController($kernel->posts, $kernel->categories, $kernel->tags, $kernel->revisions, $kernel->media, $kernel->thumbnails, $kernel->content);

/*
 * Editor image upload (LP-015/LP-016), format-switch conversion, and
 * inline category creation (LP-008) are all JSON-responding sub-actions
 * of this same POST handler rather than their own admin page/route — a
 * small AJAX-only endpoint has nowhere else to live without adding an
 * unwanted visible nav entry. Handled before the CSRF-gated form dispatch
 * below since these fire from JS on this same edit screen, not the save
 * form itself. POST handling itself lives in PostsController (LP-082);
 * this view only discards the buffered HTML shell, sets the JSON
 * Content-Type, dispatches to the matching controller method (which
 * echoes the JSON body directly rather than returning a value — see
 * uploadEditorImage()'s own docblock for why), and exits.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['form'] ?? null, ['editor_upload', 'convert_content', 'add_category'], true)) {
    // admin/index.php's ob_start() buffer already holds layout-header.php's
    // HTML shell by the time this runs (views/{page}/{subpage}.php is
    // required after layout-header.php unconditionally) — discard it
    // before sending a JSON response, or that buffered HTML would still
    // flush to the client ahead of/around this JSON on exit.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    match ($_POST['form']) {
        'editor_upload' => $controller->uploadEditorImage($_FILES, $currentUser->id, $currentUser->can('upload_files'), $csrfToken),
        'convert_content' => $controller->convertContent($_POST, $csrfToken),
        'add_category' => $controller->quickAddCategory($_POST, $currentUser->can('edit_posts'), $csrfToken),
    };

    exit;
}

require __DIR__ . '/../partials/editor-layout-save.php';

$postService = $kernel->posts;
$canPublish = $currentUser->can('publish_posts');
$canEditOthersPosts = $currentUser->can('edit_others_posts');

$error = null;

/**
 * An author/contributor may only touch their own posts unless they hold
 * edit_others_posts (Administrator/Editor).
 */
$canEditPost = static fn (Post $post): bool => $canEditOthersPosts || $post->authorId === $currentUser->id;

/*
 * POST handling for 'save'/'restore_revision' lives in PostsController
 * (LP-082, following the ThemesController precedent — see DECISIONS.md);
 * this view only reads the request, dispatches to the matching controller
 * method, and turns the returned AdminActionResult into either a redirect
 * or an inline $error string. The 'editor_upload'/'convert_content'/
 * 'add_category' JSON sub-actions are dispatched separately above, before
 * this block, since they exit immediately with a JSON body instead of
 * rendering the rest of this page.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'save' || $form === 'restore_revision') {
        $result = $form === 'save'
            ? $controller->save($_POST, $_FILES, $currentUser->id, $canPublish, $canEditOthersPosts, $csrfToken)
            : $controller->restoreRevision($_POST, $currentUser->id, $canEditOthersPosts, $csrfToken);

        if ($result->redirectUrl !== null) {
            redirect($result->redirectUrl);
        }

        $error = $result->errorMessage;
    }
}

$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingPost = null;

if ($editingId !== null) {
    $editingPost = $postService->findById($editingId);

    if ($editingPost === null || !$canEditPost($editingPost)) {
        header('Location: ' . admin_url('posts/all-posts') . '?error=forbidden');
        exit;
    }
}
?>
<h1 class="lp-admin__title"><?= $editingPost !== null ? 'Edit Post' : 'Add New Post' ?></h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Post saved.</div>
<?php endif; ?>

<?php if (isset($_GET['restored'])): ?>
    <div class="lp-alert lp-alert--success">Revision restored.</div>
<?php endif; ?>

<?php if (isset($_GET['duplicated'])): ?>
    <div class="lp-alert lp-alert--success">Post duplicated as a new draft.</div>
<?php endif; ?>

<?php
$post = $editingPost;
$statusOptions = [PostStatus::Draft, PostStatus::Published, PostStatus::Scheduled];
$categoryTree = $kernel->categories->listAllForTree();
$assignedCategoryIds = $post !== null
    ? array_map(static fn ($category) => $category->id, $kernel->categories->categoriesForPost($post->id))
    : [];
$allTagNames = array_map(static fn ($tag) => $tag->name, $kernel->tags->listAll());
$assignedTagNames = $post !== null
    ? array_map(static fn ($tag) => $tag->name, $kernel->tags->tagsForPost($post->id))
    : [];
$imageOptions = $kernel->media->query(['type' => 'image'], 500, 0)['items'];
$currentFeaturedImage = $post?->featuredImageId !== null ? $kernel->media->find($post->featuredImageId) : null;
/*
 * LP-075's Attachment Display Settings step needs, per image, every size
 * it actually has a generated thumbnail for (plus the original as
 * "full") so the size/link-to choice can be resolved entirely client-side
 * with no extra request. thumbnailsForMany() (one query for every image
 * here, not one per image) keeps this from becoming an N+1 query on a
 * library with hundreds of uploads.
 */
$editorThumbnailsByMediaId = $kernel->thumbnails->thumbnailsForMany(array_map(static fn (array $item): int => (int) $item['id'], $imageOptions));
$editorMediaLibrary = array_map(
    static function (array $item) use ($kernel, $editorThumbnailsByMediaId): array {
        $sizes = [
            'full' => [
                'url' => $kernel->media->url($item),
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
            ],
        ];

        foreach ($editorThumbnailsByMediaId[(int) $item['id']] ?? [] as $thumbnail) {
            $sizes[(string) $thumbnail['size_name']] = [
                'url' => $kernel->thumbnails->url($item, (string) $thumbnail['size_name']),
                'width' => (int) $thumbnail['width'],
                'height' => (int) $thumbnail['height'],
            ];
        }

        return [
            'url' => $kernel->media->url($item),
            'name' => (string) $item['file_name'],
            'alt' => (string) ($item['alt_text'] ?? ''),
            'sizes' => $sizes,
        ];
    },
    $imageOptions,
);
$postMeta = $post !== null ? $postService->metaForPost($post->id) : [];
$allUsers = $canEditOthersPosts ? $kernel->users->listAll() : [];
?>
<?php
$screenType = 'post';
$boxTitles = [
    'publish' => 'Publish',
    'featured_image' => 'Featured Image',
    'categories' => 'Categories',
    'tags' => 'Tags',
    'comments' => 'Discussion',
    'seo' => 'SEO (optional)',
    'custom_fields' => 'Custom Fields',
    'author' => 'Author',
];
$collapsibleBoxes = ['seo', 'custom_fields', 'author'];
$availableBoxes = ['publish', 'featured_image', 'categories', 'tags', 'comments', 'seo', 'custom_fields'];

if ($canEditOthersPosts && $post !== null) {
    $availableBoxes[] = 'author';
}

$savedLayout = $kernel->users->getEditorLayoutPreferences($currentUser->id, $screenType);
$savedOrder = array_values(array_intersect($savedLayout['order'], $availableBoxes));
// Saved order first, then any box not already in it appended at the end
// (covers a first-ever visit, and a future box id added after a user's
// preferences were last saved).
$boxOrder = array_values(array_unique(array_merge($savedOrder, $availableBoxes)));
$collapsedBoxes = array_values(array_intersect($savedLayout['collapsed'], $collapsibleBoxes));

/*
 * updateEditorLayoutPreferences() always writes order and collapsed
 * together as one snapshot (see UserService), so a real save never
 * leaves order empty — an empty $savedLayout['order'] reliably means
 * this user has never customized this screen's sidebar at all, not
 * that they explicitly saved zero collapsed boxes. SEO and Custom
 * Fields default to collapsed on that first-ever visit, matching
 * classic WordPress's own postbox defaults for optional/secondary
 * fields; Author reassignment (also collapsible) stays expanded by
 * default since it's a more consequential field to leave hidden.
 */
if ($savedLayout['order'] === []) {
    $collapsedBoxes = array_values(array_intersect(['seo', 'custom_fields'], $collapsibleBoxes));
}
?>
<section class="lp-admin__panel">
    <form method="post" action="<?= esc_url(admin_url('posts/new')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('post_save') ?>
        <input type="hidden" name="form" value="save">
        <?php if ($post !== null): ?>
            <input type="hidden" name="id" value="<?= (int) $post->id ?>">
        <?php endif; ?>

        <div class="lp-editor-layout">
            <div class="lp-editor-layout__main">
                <p class="lp-field">
                    <label for="post-title">Title</label>
                    <input type="text" id="post-title" name="title" value="<?= esc_attr($post->title ?? '') ?>" required>
                </p>

                <?php if ($post !== null && $post->status === PostStatus::Published): ?>
                    <p class="lp-field lp-permalink">
                        <span class="lp-permalink__label">Permalink:</span>
                        <a class="lp-permalink__url" href="<?= esc_url(post_permalink($post)) ?>" target="_blank" rel="noopener"><?= esc_html(post_permalink($post)) ?></a>
                        <a class="lp-button lp-button--secondary lp-permalink__view" href="<?= esc_url(post_permalink($post)) ?>" target="_blank" rel="noopener">View Post</a>
                    </p>
                <?php endif; ?>

                <?php $permalinkPreviewBase = $kernel->permalinks->postUrlPreviewBase($post); ?>
                <p class="lp-field" data-lp-url-preview data-base-url="<?= esc_url($permalinkPreviewBase) ?>">
                    <label for="post-slug">Slug</label>
                    <input type="text" id="post-slug" name="slug" value="<?= esc_attr($post->slug ?? '') ?>">
                    <span class="lp-field__hint">Leave blank to generate one automatically from the title.</span>
                    <span class="lp-field__hint">URL: <code data-lp-url-preview-value><?= esc_html($permalinkPreviewBase . ($post->slug ?? '')) ?></code></span>
                </p>

                <?php $activeContentFormat = $post->contentFormat ?? get_active_editor($currentUser->id); ?>
                <p class="lp-field">
                    <label for="post-content-format">Editor</label>
                    <select id="post-content-format" name="content_format" data-lp-content-format-select>
                        <?php foreach (ContentFormat::cases() as $formatOption): ?>
                            <option value="<?= esc_attr($formatOption->value) ?>" <?= $activeContentFormat === $formatOption ? 'selected' : '' ?>>
                                <?= esc_html($formatOption->label()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <div
                    class="lp-field lp-content-editor"
                    data-lp-content-editor
                    data-format="<?= esc_attr($activeContentFormat->value) ?>"
                    data-upload-url="<?= esc_url(admin_url('posts/new')) ?>"
                    data-upload-csrf="<?= esc_attr(Csrf::token('editor_upload')) ?>"
                    data-convert-csrf="<?= esc_attr(Csrf::token('convert_content')) ?>"
                    data-media-library="<?= esc_attr((string) json_encode($editorMediaLibrary)) ?>"
                    data-theme-stylesheet="<?= esc_url(theme_url('style.css')) ?>"
                    data-autosave-id="<?= $post !== null ? esc_attr('post-' . $post->id) : '' ?>"
                >
                    <label for="post-content">Content</label>
                    <textarea id="post-content" name="content" rows="12"><?= esc_html($post->content ?? '') ?></textarea>
                </div>

                <p class="lp-field">
                    <label for="post-excerpt">Excerpt</label>
                    <textarea id="post-excerpt" name="excerpt" rows="3"><?= esc_html($post->excerpt ?? '') ?></textarea>
                    <span class="lp-field__hint">
                        Shown as this post's preview on the front page and archives (Settings for this are on
                        <a href="<?= esc_url(admin_url('appearance/theme-options')) ?>">Appearance &rsaquo; Theme Options &rsaquo; Post Display</a>).
                        Leave blank to use the content up to a Read More tag in the editor above, or an automatically
                        generated excerpt if there's no Read More tag either.
                    </span>
                </p>
            </div>

            <aside
                class="lp-editor-layout__sidebar"
                data-lp-sortable-group="editor-sidebar"
                data-lp-sortable-ajax-url="<?= esc_url(admin_url('posts/new')) ?>"
                data-lp-sortable-ajax-csrf="<?= esc_attr(Csrf::token('editor_layout_' . $screenType)) ?>"
                data-lp-editor-screen-type="<?= esc_attr($screenType) ?>"
            >
                <?php foreach ($boxOrder as $boxId): ?>
                    <?php
                    $isCollapsed = in_array($boxId, $collapsedBoxes, true);
                    $isCollapsible = in_array($boxId, $collapsibleBoxes, true);
                    ?>
                    <div class="lp-sidebar-box<?= $isCollapsed ? ' lp-sidebar-box--collapsed' : '' ?>" data-lp-sortable-item data-lp-sortable-id="<?= esc_attr($boxId) ?>">
                        <div class="lp-sidebar-box__header" data-lp-drag-handle>
                            <span class="lp-sidebar-box__title"><?= esc_html($boxTitles[$boxId] ?? $boxId) ?></span>
                            <?php if ($isCollapsible): ?>
                                <button type="button" class="lp-sidebar-box__toggle" data-lp-sidebar-box-toggle aria-expanded="<?= $isCollapsed ? 'false' : 'true' ?>" aria-label="Toggle <?= esc_attr($boxTitles[$boxId] ?? $boxId) ?>">
                                    <span aria-hidden="true">&#9662;</span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="lp-sidebar-box__content">
                            <?php switch ($boxId):
                                case 'publish': ?>
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
                                            <label for="post-published-at">Publish date</label>
                                            <input
                                                type="datetime-local"
                                                id="post-published-at"
                                                name="published_at"
                                                value="<?= esc_attr($post?->publishedAt?->format('Y-m-d\TH:i') ?? '') ?>"
                                            >
                                        </p>

                                        <p class="lp-field">
                                            <label for="post-visibility">Visibility</label>
                                            <select id="post-visibility" name="visibility">
                                                <?php foreach (PostVisibility::cases() as $visibilityOption): ?>
                                                    <option value="<?= esc_attr($visibilityOption->value) ?>" <?= ($post?->visibility ?? PostVisibility::Public) === $visibilityOption ? 'selected' : '' ?>>
                                                        <?= esc_html($visibilityOption->label()) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </p>

                                        <label class="lp-field--checkbox">
                                            <input type="checkbox" name="is_sticky" value="1" <?= ($post->isSticky ?? false) ? 'checked' : '' ?>>
                                            Stick this post to the top of the homepage
                                        </label>

                                        <p class="lp-field">
                                            <label for="post-unpublish-at">Unpublish date (optional)</label>
                                            <input
                                                type="datetime-local"
                                                id="post-unpublish-at"
                                                name="unpublish_at"
                                                value="<?= esc_attr($post?->unpublishAt?->format('Y-m-d\TH:i') ?? '') ?>"
                                            >
                                        </p>
                                    <?php else: ?>
                                        <p class="lp-field">
                                            <label for="post-status">Status</label>
                                            <select id="post-status" name="status">
                                                <option value="<?= esc_attr(PostStatus::Draft->value) ?>" <?= ($post?->status ?? PostStatus::Draft) !== PostStatus::PendingReview ? 'selected' : '' ?>>Draft</option>
                                                <option value="<?= esc_attr(PostStatus::PendingReview->value) ?>" <?= ($post?->status ?? PostStatus::Draft) === PostStatus::PendingReview ? 'selected' : '' ?>>Submit for Review</option>
                                            </select>
                                            <span class="lp-field__hint">An editor or administrator can publish this post once it's submitted for review.</span>
                                        </p>
                                    <?php endif; ?>

                                    <div class="lp-sidebar-box__actions">
                                        <button type="submit" class="lp-button lp-button--primary">Save Post</button>
                                        <?php if ($post !== null): ?>
                                            <a class="lp-button" href="<?= esc_url(site_url('preview/' . $post->id)) ?>" target="_blank" rel="noopener">Preview</a>
                                        <?php endif; ?>
                                        <a class="lp-button" href="<?= esc_url(admin_url('posts/all-posts')) ?>">Cancel</a>
                                    </div>
                                    <?php break;

                                case 'featured_image': ?>
                                    <?php if ($currentFeaturedImage !== null): ?>
                                        <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentFeaturedImage)) ?>" alt="">
                                        <label class="lp-field--checkbox">
                                            <input type="checkbox" name="remove_featured_image" value="1"> Remove current featured image
                                        </label>

                                        <div class="lp-featured-crop" data-lp-featured-crop>
                                            <button type="button" class="lp-button lp-button--secondary" data-lp-featured-crop-toggle>
                                                <?= ($post->featuredImageCrop ?? null) !== null ? 'Edit Crop' : 'Add Crop' ?>
                                            </button>

                                            <div class="lp-featured-crop__editor" data-lp-featured-crop-editor hidden>
                                                <div class="lp-featured-crop__stage" data-lp-featured-crop-stage>
                                                    <img src="<?= esc_url($kernel->media->url($currentFeaturedImage)) ?>" alt="" data-lp-featured-crop-image>
                                                    <div class="lp-featured-crop__rect" data-lp-featured-crop-rect hidden>
                                                        <div class="lp-featured-crop__handle" data-lp-featured-crop-handle></div>
                                                    </div>
                                                </div>
                                                <p class="lp-field__hint">Drag to select the area to use as the featured image. Drag inside the selection to move it, or its bottom-right corner to resize it.</p>
                                                <button type="button" class="lp-button lp-button--link" data-lp-featured-crop-clear>Clear Crop</button>
                                            </div>

                                            <input type="hidden" name="featured_image_crop_for_id" value="<?= (int) $currentFeaturedImage['id'] ?>">
                                            <input type="hidden" name="featured_image_crop_x" data-lp-featured-crop-x value="<?= esc_attr((string) ($post->featuredImageCrop['x'] ?? '')) ?>">
                                            <input type="hidden" name="featured_image_crop_y" data-lp-featured-crop-y value="<?= esc_attr((string) ($post->featuredImageCrop['y'] ?? '')) ?>">
                                            <input type="hidden" name="featured_image_crop_width" data-lp-featured-crop-width value="<?= esc_attr((string) ($post->featuredImageCrop['width'] ?? '')) ?>">
                                            <input type="hidden" name="featured_image_crop_height" data-lp-featured-crop-height value="<?= esc_attr((string) ($post->featuredImageCrop['height'] ?? '')) ?>">
                                        </div>
                                    <?php endif; ?>

                                    <label for="post-featured-image-select">Choose from Media Manager</label>
                                    <select id="post-featured-image-select" name="featured_image_id">
                                        <option value="0">(None)</option>
                                        <?php foreach ($imageOptions as $imageOption): ?>
                                            <option value="<?= (int) $imageOption['id'] ?>" <?= ($post?->featuredImageId ?? 0) === (int) $imageOption['id'] ? 'selected' : '' ?>>
                                                <?= esc_html((string) $imageOption['file_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <label for="post-featured-image-upload">Or upload a new image</label>
                                    <input type="file" id="post-featured-image-upload" name="featured_image_upload" accept="image/*">
                                    <?php break;

                                case 'categories': ?>
                                    <div class="lp-field lp-field--checklist" data-lp-category-field>
                                        <ul class="lp-menus-add-panel__list" data-lp-category-list>
                                            <?php foreach ($categoryTree as $categoryRow): ?>
                                                <?php $categoryOption = $categoryRow['category']; ?>
                                                <li<?= $categoryRow['depth'] > 0 ? ' data-style-margin-left="' . ((int) $categoryRow['depth'] * 1.5) . 'rem"' : '' ?>>
                                                    <label class="lp-field--checkbox">
                                                        <input
                                                            type="checkbox"
                                                            name="category_ids[]"
                                                            value="<?= (int) $categoryOption->id ?>"
                                                            <?= in_array($categoryOption->id, $assignedCategoryIds, true) ? 'checked' : '' ?>
                                                        >
                                                        <?= esc_html($categoryOption->name) ?>
                                                    </label>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>

                                        <?php if ($currentUser->can('edit_posts')): ?>
                                            <details class="lp-category-quick-add" data-lp-category-quick-add data-add-url="<?= esc_url(admin_url('posts/new')) ?>" data-add-csrf="<?= esc_attr(Csrf::token('add_category')) ?>">
                                                <summary>+ Add New Category</summary>
                                                <p class="lp-field">
                                                    <label class="lp-visually-hidden" for="post-new-category-name">New category name</label>
                                                    <input type="text" id="post-new-category-name" data-lp-category-name-input placeholder="New category name">
                                                    <button type="button" class="lp-button lp-button--secondary" data-lp-category-add-button>Add New Category</button>
                                                </p>
                                                <p class="lp-field__hint" data-lp-category-add-error hidden></p>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                    <?php break;

                                case 'tags': ?>
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
                                    <?php break;

                                case 'comments': ?>
                                    <?php
                                    // Settings > Discussion's "Allow comments on new posts" (LP-047)
                                    // only sets the default for a brand-new post's checkbox below — an
                                    // existing post's own saved comments_open value always wins.
                                    $defaultCommentsOpen = $post !== null ? $post->commentsOpen : $kernel->commentModeration->defaultCommentsOpenForNewPosts();
                                    ?>
                                    <label class="lp-field--checkbox">
                                        <input type="checkbox" name="comments_open" value="1" <?= $defaultCommentsOpen ? 'checked' : '' ?>>
                                        Allow comments on this post
                                    </label>
                                    <?php break;

                                case 'seo': ?>
                                    <label for="post-meta-title">SEO title</label>
                                    <input type="text" id="post-meta-title" name="meta_title" value="<?= esc_attr($post->metaTitle ?? '') ?>" placeholder="Defaults to the title above">
                                    <span class="lp-field__hint">Overrides the browser tab title and search-result headline only — the title above is unchanged everywhere else on the site.</span>

                                    <label for="post-meta-description">Meta description</label>
                                    <textarea id="post-meta-description" name="meta_description" rows="2" placeholder="Defaults to the excerpt above"><?= esc_html($post->metaDescription ?? '') ?></textarea>
                                    <span class="lp-field__hint">Shown in search results and social share previews. Leave blank to use the excerpt.</span>
                                    <?php break;

                                case 'custom_fields': ?>
                                    <div data-lp-custom-fields>
                                        <div data-lp-custom-fields-rows>
                                            <?php foreach ([...$postMeta, ['key' => '', 'value' => '']] as $metaPair): ?>
                                                <div class="lp-custom-fields__row">
                                                    <input type="text" name="meta_keys[]" value="<?= esc_attr($metaPair['key']) ?>" placeholder="Field name">
                                                    <input type="text" name="meta_values[]" value="<?= esc_attr($metaPair['value']) ?>" placeholder="Value">
                                                    <button type="button" class="lp-button lp-button--link" data-lp-custom-fields-remove>Remove</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="lp-button lp-button--secondary" data-lp-custom-fields-add>Add Custom Field</button>
                                        <span class="lp-field__hint">Simple key/value data a theme or plugin can read against this post.</span>
                                    </div>
                                    <?php break;

                                case 'author': ?>
                                    <p class="lp-field">
                                        <label for="post-author">Author</label>
                                        <select id="post-author" name="author_id">
                                            <?php foreach ($allUsers as $userOption): ?>
                                                <option value="<?= (int) $userOption->id ?>" <?= $post->authorId === $userOption->id ? 'selected' : '' ?>>
                                                    <?= esc_html($userOption->displayName) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>
                                    <?php break;
                            endswitch; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </aside>
        </div>
    </form>
</section>

<?php if ($post !== null): ?>
    <?php
    $postRevisions = $kernel->revisions->listFor(RevisionableType::Post, $post->id);
    $compareRevisionId = isset($_GET['compare_revision']) ? (int) $_GET['compare_revision'] : null;
    $compareRevision = $compareRevisionId !== null ? $kernel->revisions->find($compareRevisionId) : null;

    if ($compareRevision !== null && ($compareRevision->contentType !== RevisionableType::Post || $compareRevision->contentId !== $post->id)) {
        $compareRevision = null;
    }
    ?>
    <section class="lp-admin__panel lp-revisions">
        <h2>Revision History</h2>

        <?php if ($compareRevision !== null): ?>
            <div class="lp-revisions__compare">
                <h3>Comparing revision from <?= esc_html($compareRevision->createdAt->format('M j, Y g:i A')) ?> to the current version</h3>
                <p class="lp-field__hint">Title: “<?= esc_html($compareRevision->title) ?>” → “<?= esc_html($post->title) ?>”</p>
                <pre class="lp-revisions__diff"><?php foreach (TextDiff::compare($compareRevision->content, $post->content) as $diffLine): ?><span class="lp-revisions__diff-line lp-revisions__diff-line--<?= esc_attr($diffLine['type']) ?>"><?= esc_html($diffLine['line']) ?>
</span><?php endforeach; ?></pre>
                <a class="lp-button" href="<?= esc_url(admin_url('posts/new')) ?>?id=<?= (int) $post->id ?>">Close comparison</a>
            </div>
        <?php endif; ?>

        <?php if ($postRevisions === []): ?>
            <p class="lp-admin__widget-placeholder">No earlier revisions yet — one is saved automatically each time this post is updated.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Author</th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($postRevisions as $postRevision): ?>
                        <?php $revisionAuthor = $kernel->users->findById($postRevision->authorId); ?>
                        <tr>
                            <td><?= esc_html($postRevision->createdAt->format('M j, Y g:i A')) ?></td>
                            <td><?= esc_html($revisionAuthor?->displayName ?? 'Unknown') ?></td>
                            <td class="lp-revisions__actions">
                                <a class="lp-button lp-button--link" href="<?= esc_url(admin_url('posts/new')) ?>?id=<?= (int) $post->id ?>&compare_revision=<?= (int) $postRevision->id ?>">Compare to current</a>
                                <form method="post" action="<?= esc_url(admin_url('posts/new')) ?>" data-lp-confirm="Restore this revision? The current content will be saved as a new revision first.">
                                    <?= Csrf::field('post_restore_revision_' . $post->id) ?>
                                    <input type="hidden" name="form" value="restore_revision">
                                    <input type="hidden" name="id" value="<?= (int) $post->id ?>">
                                    <input type="hidden" name="revision_id" value="<?= (int) $postRevision->id ?>">
                                    <button type="submit" class="lp-button lp-button--link">Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>
