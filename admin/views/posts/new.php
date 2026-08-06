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

/*
 * Editor image upload (LP-015/LP-016), format-switch conversion, and
 * inline category creation (LP-008) are all JSON-responding sub-actions
 * of this same POST handler rather than their own admin page/route — a
 * small AJAX-only endpoint has nowhere else to live without adding an
 * unwanted visible nav entry. Handled before the CSRF-gated form dispatch
 * below since these fire from JS on this same edit screen, not the save
 * form itself.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'editor_upload') {
    // admin/index.php's ob_start() buffer already holds layout-header.php's
    // HTML shell by the time this runs (views/{page}/{subpage}.php is
    // required after layout-header.php unconditionally) — discard it
    // before sending a JSON response, or that buffered HTML would still
    // flush to the client ahead of/around this JSON on exit.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('upload_files') || !Csrf::verify('editor_upload', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['error' => 'Upload failed.']);
        exit;
    }

    try {
        $uploaded = $kernel->media->upload($_FILES['file'], $currentUser->id);
        echo json_encode(['data' => ['filePath' => $kernel->media->url($uploaded)], 'url' => $kernel->media->url($uploaded)]);
    } catch (\Throwable $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage()]);
    }

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'convert_content') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!Csrf::verify('convert_content', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);
        exit;
    }

    $from = ContentFormat::tryFrom((string) ($_POST['from'] ?? ''));
    $to = ContentFormat::tryFrom((string) ($_POST['to'] ?? ''));

    if ($from === null || $to === null) {
        http_response_code(422);
        echo json_encode(['error' => 'Unknown format.']);
        exit;
    }

    echo json_encode(['content' => $kernel->content->convertFormat((string) ($_POST['content'] ?? ''), $from, $to)]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'add_category') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('edit_posts') || !Csrf::verify('add_category', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    $name = trim((string) ($_POST['name'] ?? ''));

    if ($name === '') {
        http_response_code(422);
        echo json_encode(['error' => 'A category name is required.']);
        exit;
    }

    $category = $kernel->categories->findOrCreateByName($name);

    echo json_encode(['data' => ['id' => $category->id, 'name' => $category->name]]);
    exit;
}

$postService = $kernel->posts;
$canPublish = $currentUser->can('publish_posts');
$canEditOthersPosts = $currentUser->can('edit_others_posts');

$error = null;

/**
 * An author/contributor may only touch their own posts unless they hold
 * edit_others_posts (Administrator/Editor).
 */
$canEditPost = static fn (Post $post): bool => $canEditOthersPosts || $post->authorId === $currentUser->id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'save' && Csrf::verify('post_save', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? $postService->findById($id) : null;

        if ($id > 0 && ($existing === null || !$canEditPost($existing))) {
            header('Location: ' . admin_url('posts/all-posts') . '?error=forbidden');
            exit;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');
        $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
        $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
        $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $requestedStatus = PostStatus::tryFrom((string) ($_POST['status'] ?? '')) ?? PostStatus::Draft;
        $commentsOpen = ($_POST['comments_open'] ?? null) !== null;
        $contentFormat = ContentFormat::tryFrom((string) ($_POST['content_format'] ?? '')) ?? get_active_editor($currentUser->id);

        // Contributors and anyone else without publish_posts can save as a
        // Draft or submit for review (Pending Review, LP-008), but never
        // set Published/Scheduled/Trashed themselves.
        $status = $canPublish
            ? $requestedStatus
            : ($requestedStatus === PostStatus::PendingReview ? PostStatus::PendingReview : PostStatus::Draft);

        $publishedAt = null;

        if ($status === PostStatus::Scheduled) {
            $rawPublishedAt = trim((string) ($_POST['published_at'] ?? ''));

            try {
                $publishedAt = $rawPublishedAt !== '' ? new DateTimeImmutable($rawPublishedAt) : null;
            } catch (\Exception) {
                $publishedAt = null;
            }
        }

        // Visibility/Sticky (LP-008) are publish-time decisions, same
        // gate as Status/Schedule above.
        $visibility = $canPublish
            ? (PostVisibility::tryFrom((string) ($_POST['visibility'] ?? '')) ?? PostVisibility::Public)
            : ($existing?->visibility ?? PostVisibility::Public);
        $isSticky = $canPublish ? ($_POST['is_sticky'] ?? null) !== null : ($existing?->isSticky ?? false);

        // Schedule unpublishing (LP-008) — same gate; a blank field means
        // "no scheduled unpublish", not "leave the existing one alone",
        // since the field always round-trips the current value back
        // through the form (see the edit form below).
        $unpublishAt = null;
        $clearUnpublishAt = false;

        if ($canPublish) {
            $rawUnpublishAt = trim((string) ($_POST['unpublish_at'] ?? ''));

            if ($rawUnpublishAt === '') {
                $clearUnpublishAt = true;
            } else {
                try {
                    $unpublishAt = new DateTimeImmutable($rawUnpublishAt);
                } catch (\Exception) {
                    $unpublishAt = null;
                    $clearUnpublishAt = true;
                }
            }
        }

        // Featured image resolution (LP-040): upload wins over the
        // existing-image select, which wins over "remove", which wins
        // over just keeping the current value — same precedence
        // admin/views/pages.php uses.
        $featuredImageId = $existing?->featuredImageId;

        if (($_POST['remove_featured_image'] ?? '') === '1') {
            $featuredImageId = null;
        }

        $selectedFeaturedImageId = (int) ($_POST['featured_image_id'] ?? 0);

        if ($selectedFeaturedImageId > 0) {
            $featuredImageId = $selectedFeaturedImageId;
        }

        if (isset($_FILES['featured_image_upload']) && $_FILES['featured_image_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedFeaturedImage = $kernel->media->upload($_FILES['featured_image_upload'], $currentUser->id);
                $kernel->thumbnails->generate($uploadedFeaturedImage);
                $featuredImageId = (int) $uploadedFeaturedImage['id'];
            } catch (\Throwable $exception) {
                $error = 'Featured image upload failed: ' . $exception->getMessage();
            }
        }

        // Manual crop (LP-040): the hidden featured_image_crop_for_id
        // field records which media id the on-screen rectangle was drawn
        // against. If the featured image changed in this same request
        // (a fresh upload, a different Media Manager selection, or
        // removal) that rectangle no longer applies to anything — it's
        // silently dropped rather than persisted against the wrong image.
        $featuredImageCrop = null;
        $cropForId = (int) ($_POST['featured_image_crop_for_id'] ?? 0);

        if ($cropForId > 0 && $cropForId === $featuredImageId) {
            $cropX = $_POST['featured_image_crop_x'] ?? '';
            $cropY = $_POST['featured_image_crop_y'] ?? '';
            $cropWidth = $_POST['featured_image_crop_width'] ?? '';
            $cropHeight = $_POST['featured_image_crop_height'] ?? '';

            if (
                is_numeric($cropX) && is_numeric($cropY) && is_numeric($cropWidth) && is_numeric($cropHeight)
                && (int) $cropWidth > 0 && (int) $cropHeight > 0
            ) {
                $featuredImageCrop = [
                    'x' => max(0, (int) $cropX),
                    'y' => max(0, (int) $cropY),
                    'width' => (int) $cropWidth,
                    'height' => (int) $cropHeight,
                ];
            }
        }

        if ($error === null) {
            try {
                // LP-017: snapshot the pre-update content as a revision
                // before it's overwritten. Nothing to snapshot on create —
                // there is no prior state yet.
                if ($existing !== null) {
                    $kernel->revisions->save(
                        RevisionableType::Post,
                        $existing->id,
                        $existing->title,
                        $existing->content,
                        $existing->excerpt,
                        $existing->contentFormat,
                        $currentUser->id,
                    );
                }

                $post = $existing === null
                    ? $postService->create($title, $content, $excerpt, $currentUser->id, $status, $publishedAt, $featuredImageId, slug: $slug !== '' ? $slug : null, commentsOpen: $commentsOpen, contentFormat: $contentFormat, featuredImageCrop: $featuredImageCrop, visibility: $visibility, isSticky: $isSticky, unpublishAt: $unpublishAt)
                    : $postService->update($id, $title, $content, $excerpt, $status, $publishedAt, $featuredImageId, $slug !== '' ? $slug : null, $commentsOpen, $contentFormat, featuredImageCrop: $featuredImageCrop, visibility: $visibility, isSticky: $isSticky, unpublishAt: $unpublishAt, clearUnpublishAt: $clearUnpublishAt);

                $kernel->categories->assignToPost($post->id, is_array($_POST['category_ids'] ?? null) ? $_POST['category_ids'] : []);
                $kernel->tags->assignToPost($post->id, explode(',', (string) ($_POST['tags'] ?? '')));
                $postService->updateSeo($post->id, $metaTitle, $metaDescription);

                // Author reassignment (LP-008) — Editor/Administrator only,
                // same edit_others_posts gate that already lets them edit
                // another author's post at all.
                if ($canEditOthersPosts) {
                    $reassignAuthorId = (int) ($_POST['author_id'] ?? 0);

                    if ($reassignAuthorId > 0) {
                        $postService->reassignAuthor($post->id, $reassignAuthorId);
                    }
                }

                // Custom fields (LP-008) — a repeatable key/value row
                // editor; meta_keys[]/meta_values[] are parallel arrays
                // built by the same index client-side.
                $metaKeys = is_array($_POST['meta_keys'] ?? null) ? $_POST['meta_keys'] : [];
                $metaValues = is_array($_POST['meta_values'] ?? null) ? $_POST['meta_values'] : [];
                $metaPairs = [];

                foreach ($metaKeys as $metaIndex => $metaKey) {
                    $metaPairs[] = ['key' => (string) $metaKey, 'value' => (string) ($metaValues[$metaIndex] ?? '')];
                }

                $postService->replaceMetaForPost($post->id, $metaPairs);

                header('Location: ' . admin_url('posts/new') . '?id=' . $post->id . '&saved=1');
                exit;
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'restore_revision') {
        $id = (int) ($_POST['id'] ?? 0);
        $revisionId = (int) ($_POST['revision_id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('post_restore_revision_' . $id, $token)) {
            header('Location: ' . admin_url('posts/new') . '?id=' . $id);
            exit;
        }

        $existing = $id > 0 ? $postService->findById($id) : null;
        $revision = $revisionId > 0 ? $kernel->revisions->find($revisionId) : null;

        if (
            $existing !== null
            && $canEditPost($existing)
            && $revision !== null
            && $revision->contentType === RevisionableType::Post
            && $revision->contentId === $existing->id
        ) {
            // Snapshot the current (pre-restore) state too, so restoring is
            // itself undoable — mirrors the snapshot-before-overwrite done
            // on every normal save above.
            $kernel->revisions->save(
                RevisionableType::Post,
                $existing->id,
                $existing->title,
                $existing->content,
                $existing->excerpt,
                $existing->contentFormat,
                $currentUser->id,
            );

            $postService->update(
                $existing->id,
                $revision->title,
                $revision->content,
                $revision->excerpt,
                $existing->status,
                $existing->publishedAt,
                $existing->featuredImageId,
                $existing->slug,
                $existing->commentsOpen,
                $revision->contentFormat,
                featuredImageCrop: $existing->featuredImageCrop,
            );
        }

        header('Location: ' . admin_url('posts/new') . '?id=' . $id . '&restored=1');
        exit;
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
$allCategories = $kernel->categories->listAll();
$assignedCategoryIds = $post !== null
    ? array_map(static fn ($category) => $category->id, $kernel->categories->categoriesForPost($post->id))
    : [];
$allTagNames = array_map(static fn ($tag) => $tag->name, $kernel->tags->listAll());
$assignedTagNames = $post !== null
    ? array_map(static fn ($tag) => $tag->name, $kernel->tags->tagsForPost($post->id))
    : [];
$imageOptions = $kernel->media->query(['type' => 'image'], 500, 0)['items'];
$currentFeaturedImage = $post?->featuredImageId !== null ? $kernel->media->find($post->featuredImageId) : null;
$editorMediaLibrary = array_map(
    static fn (array $item): array => ['url' => $kernel->media->url($item), 'name' => (string) $item['file_name']],
    $imageOptions,
);
$postMeta = $post !== null ? $postService->metaForPost($post->id) : [];
$allUsers = $canEditOthersPosts ? $kernel->users->listAll() : [];
?>
<section class="lp-admin__panel">
    <form method="post" action="<?= esc_url(admin_url('posts/new')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('post_save') ?>
        <input type="hidden" name="form" value="save">
        <?php if ($post !== null): ?>
            <input type="hidden" name="id" value="<?= (int) $post->id ?>">
        <?php endif; ?>

        <p class="lp-field">
            <label for="post-title">Title</label>
            <input type="text" id="post-title" name="title" value="<?= esc_attr($post->title ?? '') ?>" required>
        </p>

        <p class="lp-field" data-lp-url-preview data-base-url="<?= esc_url(site_url('post/')) ?>">
            <label for="post-slug">Slug</label>
            <input type="text" id="post-slug" name="slug" value="<?= esc_attr($post->slug ?? '') ?>">
            <span class="lp-field__hint">Leave blank to generate one automatically from the title.</span>
            <span class="lp-field__hint">URL: <code data-lp-url-preview-value><?= esc_html(site_url('post/' . ($post->slug ?? ''))) ?></code></span>
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
        </p>

        <fieldset class="lp-field">
            <legend>SEO (optional)</legend>

            <label for="post-meta-title">SEO title</label>
            <input type="text" id="post-meta-title" name="meta_title" value="<?= esc_attr($post->metaTitle ?? '') ?>" placeholder="Defaults to the title above">
            <span class="lp-field__hint">Overrides the browser tab title and search-result headline only — the title above is unchanged everywhere else on the site.</span>

            <label for="post-meta-description">Meta description</label>
            <textarea id="post-meta-description" name="meta_description" rows="2" placeholder="Defaults to the excerpt above"><?= esc_html($post->metaDescription ?? '') ?></textarea>
            <span class="lp-field__hint">Shown in search results and social share previews. Leave blank to use the excerpt.</span>
        </fieldset>

        <fieldset class="lp-field">
            <legend>Featured Image</legend>

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
        </fieldset>

        <fieldset class="lp-field lp-field--checklist" data-lp-category-field>
            <legend>Categories</legend>
            <div data-lp-category-list>
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
            </div>

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
        </fieldset>

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

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comments_open" value="1" <?= ($post->commentsOpen ?? true) ? 'checked' : '' ?>>
            Allow comments on this post
        </label>

        <fieldset class="lp-field" data-lp-custom-fields>
            <legend>Custom Fields</legend>
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
        </fieldset>

        <?php if ($canEditOthersPosts && $post !== null): ?>
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
        <?php endif; ?>

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

            <p class="lp-field">
                <label for="post-visibility">Visibility</label>
                <select id="post-visibility" name="visibility">
                    <?php foreach (PostVisibility::cases() as $visibilityOption): ?>
                        <option value="<?= esc_attr($visibilityOption->value) ?>" <?= ($post?->visibility ?? PostVisibility::Public) === $visibilityOption ? 'selected' : '' ?>>
                            <?= esc_html($visibilityOption->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="lp-field__hint">Private posts are only visible to logged-in staff (the author, or anyone who can edit posts).</span>
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
                <span class="lp-field__hint">Leave blank to keep this post published indefinitely. Once this time passes, the post is automatically no longer publicly visible — the stored status is unchanged, so republishing just means clearing or moving this date.</span>
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

        <button type="submit" class="lp-button lp-button--primary">Save Post</button>
        <?php if ($post !== null): ?>
            <a class="lp-button" href="<?= esc_url(site_url('preview/' . $post->id)) ?>" target="_blank" rel="noopener">Preview</a>
        <?php endif; ?>
        <a class="lp-button" href="<?= esc_url(admin_url('posts/all-posts')) ?>">Cancel</a>
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
