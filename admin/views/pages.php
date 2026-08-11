<?php

/**
 * The admin Pages list screen.
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
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\RevisionableType;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * Editor image upload and format-switch conversion — see the identical
 * block's docblock in admin/views/posts.php for why these live here as
 * JSON sub-actions rather than their own admin page/route.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'editor_upload') {
    // See the identical comment in admin/views/posts.php's matching
    // block for why the output buffer must be discarded here.
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
        echo json_encode(['error' => 'Upload failed.', 'csrfToken' => Csrf::token('editor_upload')]);
        exit;
    }

    try {
        $uploaded = $kernel->media->upload($_FILES['file'], $currentUser->id);

        // See the identical comment in admin/views/posts/new.php's matching
        // block for why a fresh token is returned on every response here.
        echo json_encode([
            'data' => ['filePath' => $kernel->media->url($uploaded)],
            'url' => $kernel->media->url($uploaded),
            'csrfToken' => Csrf::token('editor_upload'),
        ]);
    } catch (\Throwable $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage(), 'csrfToken' => Csrf::token('editor_upload')]);
    }

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'convert_content') {
    // See the identical comment in admin/views/posts.php's matching
    // block for why the output buffer must be discarded here.
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
        $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
        $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $requestedStatus = PageStatus::tryFrom((string) ($_POST['status'] ?? '')) ?? PageStatus::Draft;
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $contentFormat = ContentFormat::tryFrom((string) ($_POST['content_format'] ?? '')) ?? get_active_editor($currentUser->id);

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

        // Featured image resolution (LP-040): upload wins over the
        // existing-image select, which wins over "remove", which wins
        // over just keeping the current value — same precedence
        // admin/views/posts.php uses.
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

        // Manual crop (LP-040) — see the identical block in
        // admin/views/posts/new.php for the full rationale.
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

        if ($title === '') {
            $error = 'A title is required.';
        } elseif ($error === null) {
            // LP-017: snapshot the pre-update content as a revision before
            // it's overwritten — see the identical block in
            // admin/views/posts.php for the full rationale.
            if ($existing !== null) {
                $kernel->revisions->save(
                    RevisionableType::Page,
                    $existing->id,
                    $existing->title,
                    $existing->content,
                    $existing->excerpt,
                    $existing->contentFormat,
                    $currentUser->id,
                );
            }

            $page = $existing === null
                ? $pageService->create($title, $content, $excerpt, $currentUser->id, $status, $publishedAt, $parentId > 0 ? $parentId : null, $featuredImageId, $slug !== '' ? $slug : null, $contentFormat, featuredImageCrop: $featuredImageCrop)
                : $pageService->update($id, $title, $content, $excerpt, $status, $publishedAt, $parentId > 0 ? $parentId : null, $featuredImageId, $slug !== '' ? $slug : null, $contentFormat, featuredImageCrop: $featuredImageCrop);

            $pageService->updateSeo($page->id, $metaTitle, $metaDescription);

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
            // Revisions live outside PageService (see RevisionService's
            // docblock) — clean them up here before the page itself is gone.
            $kernel->revisions->deleteAllFor(RevisionableType::Page, $id);
            $pageService->delete($id);
        }

        header('Location: ' . admin_url('pages'));
        exit;
    } elseif ($form === 'restore_revision') {
        $id = (int) ($_POST['id'] ?? 0);
        $revisionId = (int) ($_POST['revision_id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('page_restore_revision_' . $id, $token)) {
            header('Location: ' . admin_url('pages') . '?action=edit&id=' . $id);
            exit;
        }

        $existing = $id > 0 ? $pageService->findById($id) : null;
        $revision = $revisionId > 0 ? $kernel->revisions->find($revisionId) : null;

        if (
            $existing !== null
            && $canEditPage($existing)
            && $revision !== null
            && $revision->contentType === RevisionableType::Page
            && $revision->contentId === $existing->id
        ) {
            // Snapshot the current (pre-restore) state too, so restoring is
            // itself undoable.
            $kernel->revisions->save(
                RevisionableType::Page,
                $existing->id,
                $existing->title,
                $existing->content,
                $existing->excerpt,
                $existing->contentFormat,
                $currentUser->id,
            );

            $pageService->update(
                $existing->id,
                $revision->title,
                $revision->content,
                $revision->excerpt,
                $existing->status,
                $existing->publishedAt,
                $existing->parentId,
                $existing->featuredImageId,
                $existing->slug,
                $revision->contentFormat,
                featuredImageCrop: $existing->featuredImageCrop,
            );
        }

        header('Location: ' . admin_url('pages') . '?action=edit&id=' . $id . '&restored=1');
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

<?php if (isset($_GET['restored'])): ?>
    <div class="lp-alert lp-alert--success">Revision restored.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">You do not have permission to edit that page.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php
    $page = $editingPage;
    $statusOptions = [PageStatus::Draft, PageStatus::Published, PageStatus::Scheduled];
    $parentOptions = $pageService->listAllForParentSelect($page?->id);
    $imageOptions = $kernel->media->query(['type' => 'image'], 500, 0)['items'];
    $currentFeaturedImage = $page?->featuredImageId !== null ? $kernel->media->find($page->featuredImageId) : null;
    /*
     * LP-075's Attachment Display Settings step needs, per image, every
     * size it actually has a generated thumbnail for (plus the original
     * as "full") so the size/link-to choice can be resolved entirely
     * client-side with no extra request. thumbnailsForMany() (one query
     * for every image here, not one per image) keeps this from becoming
     * an N+1 query on a library with hundreds of uploads.
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
    ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('pages')) ?>" enctype="multipart/form-data">
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

            <?php $activeContentFormat = $page->contentFormat ?? get_active_editor($currentUser->id); ?>
            <p class="lp-field">
                <label for="page-content-format">Editor</label>
                <select id="page-content-format" name="content_format" data-lp-content-format-select>
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
                data-upload-url="<?= esc_url(admin_url('pages')) ?>"
                data-upload-csrf="<?= esc_attr(Csrf::token('editor_upload')) ?>"
                data-convert-csrf="<?= esc_attr(Csrf::token('convert_content')) ?>"
                data-media-library="<?= esc_attr((string) json_encode($editorMediaLibrary)) ?>"
                data-theme-stylesheet="<?= esc_url(theme_url('style.css')) ?>"
                data-autosave-id="<?= $page !== null ? esc_attr('page-' . $page->id) : '' ?>"
            >
                <label for="page-content">Content</label>
                <textarea id="page-content" name="content" rows="12"><?= esc_html($page->content ?? '') ?></textarea>
            </div>

            <p class="lp-field">
                <label for="page-excerpt">Excerpt</label>
                <textarea id="page-excerpt" name="excerpt" rows="3"><?= esc_html($page->excerpt ?? '') ?></textarea>
            </p>

            <fieldset class="lp-field">
                <legend>SEO (optional)</legend>

                <label for="page-meta-title">SEO title</label>
                <input type="text" id="page-meta-title" name="meta_title" value="<?= esc_attr($page->metaTitle ?? '') ?>" placeholder="Defaults to the title above">
                <span class="lp-field__hint">Overrides the browser tab title and search-result headline only — the title above is unchanged everywhere else on the site.</span>

                <label for="page-meta-description">Meta description</label>
                <textarea id="page-meta-description" name="meta_description" rows="2" placeholder="Defaults to the excerpt above"><?= esc_html($page->metaDescription ?? '') ?></textarea>
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
                            <?= ($page->featuredImageCrop ?? null) !== null ? 'Edit Crop' : 'Add Crop' ?>
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
                        <input type="hidden" name="featured_image_crop_x" data-lp-featured-crop-x value="<?= esc_attr((string) ($page->featuredImageCrop['x'] ?? '')) ?>">
                        <input type="hidden" name="featured_image_crop_y" data-lp-featured-crop-y value="<?= esc_attr((string) ($page->featuredImageCrop['y'] ?? '')) ?>">
                        <input type="hidden" name="featured_image_crop_width" data-lp-featured-crop-width value="<?= esc_attr((string) ($page->featuredImageCrop['width'] ?? '')) ?>">
                        <input type="hidden" name="featured_image_crop_height" data-lp-featured-crop-height value="<?= esc_attr((string) ($page->featuredImageCrop['height'] ?? '')) ?>">
                    </div>
                <?php endif; ?>

                <label for="page-featured-image-select">Choose from Media Manager</label>
                <select id="page-featured-image-select" name="featured_image_id">
                    <option value="0">(None)</option>
                    <?php foreach ($imageOptions as $imageOption): ?>
                        <option value="<?= (int) $imageOption['id'] ?>" <?= ($page?->featuredImageId ?? 0) === (int) $imageOption['id'] ? 'selected' : '' ?>>
                            <?= esc_html((string) $imageOption['file_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="page-featured-image-upload">Or upload a new image</label>
                <input type="file" id="page-featured-image-upload" name="featured_image_upload" accept="image/*">
            </fieldset>

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

    <?php if ($page !== null): ?>
        <?php
        $pageRevisions = $kernel->revisions->listFor(RevisionableType::Page, $page->id);
        $compareRevisionId = isset($_GET['compare_revision']) ? (int) $_GET['compare_revision'] : null;
        $compareRevision = $compareRevisionId !== null ? $kernel->revisions->find($compareRevisionId) : null;

        if ($compareRevision !== null && ($compareRevision->contentType !== RevisionableType::Page || $compareRevision->contentId !== $page->id)) {
            $compareRevision = null;
        }
        ?>
        <section class="lp-admin__panel lp-revisions">
            <h2>Revision History</h2>

            <?php if ($compareRevision !== null): ?>
                <div class="lp-revisions__compare">
                    <h3>Comparing revision from <?= esc_html($compareRevision->createdAt->format('M j, Y g:i A')) ?> to the current version</h3>
                    <p class="lp-field__hint">Title: “<?= esc_html($compareRevision->title) ?>” → “<?= esc_html($page->title) ?>”</p>
                    <pre class="lp-revisions__diff"><?php foreach (TextDiff::compare($compareRevision->content, $page->content) as $diffLine): ?><span class="lp-revisions__diff-line lp-revisions__diff-line--<?= esc_attr($diffLine['type']) ?>"><?= esc_html($diffLine['line']) ?>
</span><?php endforeach; ?></pre>
                    <a class="lp-button" href="<?= esc_url(admin_url('pages')) ?>?action=edit&id=<?= (int) $page->id ?>">Close comparison</a>
                </div>
            <?php endif; ?>

            <?php if ($pageRevisions === []): ?>
                <p class="lp-admin__widget-placeholder">No earlier revisions yet — one is saved automatically each time this page is updated.</p>
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
                        <?php foreach ($pageRevisions as $pageRevision): ?>
                            <?php $revisionAuthor = $kernel->users->findById($pageRevision->authorId); ?>
                            <tr>
                                <td><?= esc_html($pageRevision->createdAt->format('M j, Y g:i A')) ?></td>
                                <td><?= esc_html($revisionAuthor?->displayName ?? 'Unknown') ?></td>
                                <td class="lp-revisions__actions">
                                    <a class="lp-button lp-button--link" href="<?= esc_url(admin_url('pages')) ?>?action=edit&id=<?= (int) $page->id ?>&compare_revision=<?= (int) $pageRevision->id ?>">Compare to current</a>
                                    <form method="post" action="<?= esc_url(admin_url('pages')) ?>" data-lp-confirm="Restore this revision? The current content will be saved as a new revision first.">
                                        <?= Csrf::field('page_restore_revision_' . $page->id) ?>
                                        <input type="hidden" name="form" value="restore_revision">
                                        <input type="hidden" name="id" value="<?= (int) $page->id ?>">
                                        <input type="hidden" name="revision_id" value="<?= (int) $pageRevision->id ?>">
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
                                    <form method="post" action="<?= esc_url(admin_url('pages')) ?>" data-lp-confirm="Delete this page permanently?">
                                        <?= Csrf::field('page_delete_' . $listedPage->id) ?>
                                        <input type="hidden" name="form" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
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
