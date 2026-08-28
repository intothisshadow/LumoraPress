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
use LumoraPress\Core\Security\TrustedImageOrigins;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use LumoraPress\Models\RevisionableType;
use LumoraPress\Plugins\FontAwesome\FontAwesomeService;

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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['form'] ?? null, ['editor_upload', 'convert_content', 'add_category', 'media_picker_query', 'featured_image_picker_query', 'link_picker_query', 'font_awesome_icon_query'], true)) {
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

    // font_awesome_icon_query is handled separately from the match()
    // below since it belongs to an optional plugin — FontAwesomeService
    // is only ever require_once'd (see font-awesome.php) when that
    // plugin is active, so this view (reachable regardless of which
    // plugins are active) must guard the class reference rather than
    // assume it's loaded, unlike appearance/font-awesome.php's own
    // settings screen, which is only ever reachable while active.
    if ($_POST['form'] === 'font_awesome_icon_query') {
        if (class_exists(FontAwesomeService::class, false)) {
            FontAwesomeService::instance()->queryIconsForPicker($_POST, $csrfToken);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Font Awesome is not active.']);
        }

        exit;
    }

    // link_picker_query is handled separately from the match() below
    // since it spans both Posts and Pages (a link picker opened from
    // the Post editor must still be able to target an existing Page,
    // and vice versa) — PostsController only holds a PostService, so
    // this queries $kernel->posts/$kernel->pages directly rather than
    // adding a PageService dependency to a controller named for the
    // other content type. Duplicated verbatim in pages/new.php's own
    // identical block, matching media_picker_query's existing
    // per-view-duplication precedent there.
    if ($_POST['form'] === 'link_picker_query') {
        if (!$currentUser->can('edit_posts') || !Csrf::verify('link_picker_query', $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not permitted.']);
            exit;
        }

        $term = trim((string) ($_POST['term'] ?? ''));
        $linkPickerFilters = $term !== '' ? ['term' => $term] : [];

        $linkPickerItems = [];

        foreach ($kernel->posts->paginateForAdmin(1, 15, null, $linkPickerFilters)['posts'] as $resultPost) {
            $linkPickerDate = $resultPost->publishedAt ?? $resultPost->updatedAt;
            $linkPickerItems[] = [
                'title' => $resultPost->title,
                'url' => post_permalink($resultPost),
                'type' => 'Post',
                'date' => $linkPickerDate->format('Y/m/d'),
                'sortKey' => $linkPickerDate->format('Y-m-d H:i:s'),
            ];
        }

        foreach ($kernel->pages->paginateForAdmin(1, 15, null, $linkPickerFilters)['pages'] as $resultPage) {
            $linkPickerDate = $resultPage->publishedAt ?? $resultPage->updatedAt;
            $linkPickerItems[] = [
                'title' => $resultPage->title,
                'url' => page_permalink($resultPage),
                'type' => 'Page',
                'date' => $linkPickerDate->format('Y/m/d'),
                'sortKey' => $linkPickerDate->format('Y-m-d H:i:s'),
            ];
        }

        usort($linkPickerItems, static fn (array $a, array $b): int => $b['sortKey'] <=> $a['sortKey']);

        echo json_encode([
            'items' => array_map(
                static fn (array $item): array => ['title' => $item['title'], 'url' => $item['url'], 'type' => $item['type'], 'date' => $item['date']],
                array_slice($linkPickerItems, 0, 20),
            ),
            'csrfToken' => Csrf::token('link_picker_query'),
        ]);
        exit;
    }

    match ($_POST['form']) {
        'editor_upload' => $controller->uploadEditorImage($_FILES, $currentUser->id, $currentUser->can('upload_files'), $csrfToken),
        'convert_content' => $controller->convertContent($_POST, $csrfToken),
        'add_category' => $controller->quickAddCategory($_POST, $currentUser->can('edit_posts'), $csrfToken),
        'media_picker_query' => $controller->queryMediaForPicker($_POST, $currentUser->can('edit_posts'), $csrfToken),
        'featured_image_picker_query' => $controller->queryFeaturedImagePicker($_POST, $currentUser->can('edit_posts'), $csrfToken),
    };

    exit;
}

require __DIR__ . '/../partials/editor-layout-save.php';

$postService = $kernel->posts;
$canPublish = $currentUser->can('publish_posts');
$canEditOthersPosts = $currentUser->can('edit_others_posts');
$canDeletePosts = $currentUser->can('delete_posts');

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
            // "Trusted staff shouldn't have to add a domain in Settings
            // just to embed an image" — an Administrator/Editor's own
            // save already just worked, so any external <img> origins in
            // it are trusted automatically. Gated on edit_others_posts,
            // never on the plain "can save this post" check every author
            // passes, and never runs for restore_revision (that content
            // was already trusted the first time it was saved). Scans the
            // *rendered* HTML, not the raw stored content — a Markdown
            // post stores `![alt](url)`, not a literal <img> tag, so
            // scanning the raw source would miss the (default-editor,
            // most common) Markdown case entirely.
            if ($form === 'save' && $canEditOthersPosts) {
                $savedContentFormat = ContentFormat::tryFrom((string) ($_POST['content_format'] ?? '')) ?? get_active_editor($currentUser->id);
                $renderedForAutoTrust = $kernel->content->render((string) ($_POST['content'] ?? ''), $savedContentFormat);
                TrustedImageOrigins::autoTrustFromContent($kernel->config, $renderedForAutoTrust, site_origin());
            }

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
$currentFeaturedImage = $post?->featuredImageId !== null ? $kernel->media->find($post->featuredImageId) : null;
/*
 * LP-115: the "Insert Image" picker's grid used to be preloaded here as
 * one data-media-library JSON blob (every image in the library, up to
 * 500 of them) — replaced by an on-demand AJAX query
 * (PostsController::queryMediaForPicker()) so opening the picker doesn't
 * require loading the whole library first. Only the (small) Folder tree
 * is still preloaded, for the picker's Folder filter <select>.
 */
$editorFolderTree = array_map(
    static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
    $kernel->folders->listAllForTree(),
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
                    data-media-picker-csrf="<?= esc_attr(Csrf::token('media_picker_query')) ?>"
                    data-link-picker-csrf="<?= esc_attr(Csrf::token('link_picker_query')) ?>"
                    data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
                    <?php if (lp_fontawesome_enabled()): ?>
                        data-icon-picker-csrf="<?= esc_attr(Csrf::token('font_awesome_icon_query')) ?>"
                        data-icon-picker-css="<?= esc_attr((string) json_encode((array) apply_filters('lp_fontawesome_css_urls', []))) ?>"
                    <?php endif; ?>
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
                        <a href="<?= esc_url(admin_url('appearance/customize')) ?>?tab=body">Appearance &rsaquo; Customize &rsaquo; Body</a>).
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
                                    <?php if ($post !== null && $canDeletePosts && $canEditPost($post)): ?>
                                        <?php $trashFormId = 'post-trash-form-' . $post->id; ?>
                                        <div class="lp-sidebar-box__actions lp-sidebar-box__actions--trash">
                                            <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('post_trash_' . $post->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                            <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $post->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                            <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this post to the Trash?">Move to Trash</button>
                                        </div>
                                    <?php endif; ?>
                                    <?php break;

                                case 'featured_image':
                                    $record = $post;
                                    $idPrefix = 'post';
                                    require __DIR__ . '/../partials/editor-featured-image.php';
                                    break;

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

                                case 'seo':
                                    $record = $post;
                                    $idPrefix = 'post';
                                    require __DIR__ . '/../partials/editor-seo.php';
                                    break;

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

<?php if ($post !== null && $canDeletePosts && $canEditPost($post)): ?>
    <form id="post-trash-form-<?= (int) $post->id ?>" method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>"></form>
<?php endif; ?>

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
