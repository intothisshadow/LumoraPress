<?php

/**
 * POST-handling logic for the admin Posts screens (list + editor).
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use DateTimeImmutable;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use LumoraPress\Models\RevisionableType;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\ContentRenderer;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\RevisionService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\ThumbnailService;
use Throwable;

/**
 * Extracted from admin/views/posts/all-posts.php and admin/views/posts/
 * new.php (LP-082, following the ThemesController precedent — see
 * DECISIONS.md's LP-082 entry for why methods here return an
 * AdminActionResult instead of calling header()/exit themselves). This is
 * also the ownership/capability-logic testing gap LP-008's own Testing
 * checklist named ("isn't unit-tested directly") and its "no end-to-end
 * admin-flow test yet" line — both close together, since neither was
 * testable until this logic existed outside a template file.
 *
 * new.php's three AJAX-only JSON sub-actions (editor image upload, Markdown/
 * HTML format conversion, inline category quick-add — uploadEditorImage()/
 * convertContent()/quickAddCategory() below) close LP-008's "Editor
 * testing" line the same way: each has real capability/CSRF/error-handling
 * branching, so it wasn't the passthrough-with-nothing-to-test case the
 * rest of this class's earlier docblock once assumed — it just needed a
 * JSON-shaped counterpart to AdminActionResult (see each method's own
 * docblock) rather than the redirect-shaped one.
 */
final class PostsController
{
    public function __construct(
        private readonly PostService $posts,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly RevisionService $revisions,
        private readonly MediaService $media,
        private readonly ThumbnailService $thumbnails,
        private readonly ContentRenderer $content,
    ) {
    }

    /**
     * An author/contributor may only touch their own posts unless they hold
     * edit_others_posts (Administrator/Editor) — mirrors the closure every
     * admin Posts view built inline before this extraction.
     */
    private function canEditPost(Post $post, int $currentUserId, bool $canEditOthersPosts): bool
    {
        return $canEditOthersPosts || $post->authorId === $currentUserId;
    }

    /**
     * @param array<string, mixed> $post
     */
    public function quickDraft(array $post, int $currentUserId, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('quick_draft', $csrfToken)) {
            return $this->invalidRequest();
        }

        $title = trim((string) ($post['title'] ?? ''));

        if ($title === '') {
            return AdminActionResult::error('A title is required to save a draft.');
        }

        $created = $this->posts->create(
            title: $title,
            content: trim((string) ($post['content'] ?? '')),
            excerpt: '',
            authorId: $currentUserId,
            status: PostStatus::Draft,
        );

        return AdminActionResult::redirect(admin_url('posts/new') . '?id=' . $created->id);
    }

    /**
     * @param array<string, mixed> $post
     */
    public function trash(array $post, int $currentUserId, bool $canDeletePosts, bool $canEditOthersPosts, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('post_trash_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/all-posts'));
        }

        $existing = $id > 0 ? $this->posts->findById($id) : null;

        if ($existing !== null && $canDeletePosts && $this->canEditPost($existing, $currentUserId, $canEditOthersPosts)) {
            $this->posts->trash($id);
        }

        return AdminActionResult::redirect(admin_url('posts/all-posts') . '?trashed=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function restorePost(array $post, int $currentUserId, bool $canDeletePosts, bool $canEditOthersPosts, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('post_restore_post_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/all-posts'));
        }

        $existing = $id > 0 ? $this->posts->findById($id) : null;

        if ($existing !== null && $canDeletePosts && $this->canEditPost($existing, $currentUserId, $canEditOthersPosts)) {
            $this->posts->restore($id);
        }

        return AdminActionResult::redirect(admin_url('posts/all-posts') . '?status=' . PostStatus::Trashed->value . '&post_restored=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function deletePermanently(array $post, int $currentUserId, bool $canDeletePosts, bool $canEditOthersPosts, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('post_delete_permanently_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/all-posts'));
        }

        $existing = $id > 0 ? $this->posts->findById($id) : null;

        // Permanent delete is only offered (and only honoured) for posts
        // already in the Trash — Move to Trash is the only reachable path
        // to actually removing a post from every other status, the same
        // "delete means trash first" guardrail WordPress's own list table
        // enforces.
        if (
            $existing !== null
            && $existing->status === PostStatus::Trashed
            && $canDeletePosts
            && $this->canEditPost($existing, $currentUserId, $canEditOthersPosts)
        ) {
            $this->revisions->deleteAllFor(RevisionableType::Post, $id);
            $this->posts->delete($id);
        }

        return AdminActionResult::redirect(admin_url('posts/all-posts') . '?status=' . PostStatus::Trashed->value . '&post_deleted=1');
    }

    /**
     * Permanently deletes every trashed post the current user is allowed
     * to touch (see canEditPost()) — same per-post revision cleanup and
     * ownership guard as deletePermanently()/bulkAction()'s
     * delete_permanently case, just applied to the whole Trash tab at
     * once instead of one id or a checked selection.
     */
    public function emptyTrash(int $currentUserId, bool $canDeletePosts, bool $canEditOthersPosts, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('posts_empty_trash', $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/all-posts'));
        }

        if ($canDeletePosts) {
            $trashedCount = $this->posts->countByStatus(PostStatus::Trashed);
            $trashedPosts = $trashedCount > 0
                ? $this->posts->paginateForAdmin(1, $trashedCount, PostStatus::Trashed)['posts']
                : [];

            foreach ($trashedPosts as $trashedPost) {
                if (!$this->canEditPost($trashedPost, $currentUserId, $canEditOthersPosts)) {
                    continue;
                }

                $this->revisions->deleteAllFor(RevisionableType::Post, $trashedPost->id);
                $this->posts->delete($trashedPost->id);
            }
        }

        return AdminActionResult::redirect(admin_url('posts/all-posts') . '?status=' . PostStatus::Trashed->value . '&trash_emptied=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function duplicate(array $post, int $currentUserId, bool $canEditOthersPosts, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('post_duplicate_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/all-posts'));
        }

        $existing = $id > 0 ? $this->posts->findById($id) : null;

        if ($existing !== null && $this->canEditPost($existing, $currentUserId, $canEditOthersPosts)) {
            $duplicate = $this->posts->duplicate($id, $currentUserId);

            if ($duplicate !== null) {
                $this->categories->assignToPost(
                    $duplicate->id,
                    array_map(static fn ($category) => (string) $category->id, $this->categories->categoriesForPost($existing->id)),
                );
                $this->tags->assignToPost(
                    $duplicate->id,
                    array_map(static fn ($tag) => $tag->name, $this->tags->tagsForPost($existing->id)),
                );
                $this->posts->replaceMetaForPost($duplicate->id, $this->posts->metaForPost($existing->id));

                return AdminActionResult::redirect(admin_url('posts/new') . '?id=' . $duplicate->id . '&duplicated=1');
            }
        }

        return AdminActionResult::redirect(admin_url('posts/all-posts'));
    }

    /**
     * @param array<string, mixed> $post
     */
    public function bulkAction(
        array $post,
        int $currentUserId,
        bool $canPublish,
        bool $canDeletePosts,
        bool $canEditOthersPosts,
        bool $canEditPosts,
        ?string $csrfToken,
    ): AdminActionResult {
        if (!Csrf::verify('posts_bulk_action', $csrfToken)) {
            return $this->invalidRequest();
        }

        $statusSuffix = isset($post['status']) ? '?status=' . urlencode((string) $post['status']) : '';
        $bulkAction = (string) ($post['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($post['post_ids'] ?? null) ? $post['post_ids'] : [])));
        $editableIds = array_values(array_filter($ids, function (int $id) use ($currentUserId, $canEditOthersPosts): bool {
            $existing = $this->posts->findById($id);

            return $existing !== null && $this->canEditPost($existing, $currentUserId, $canEditOthersPosts);
        }));

        // Change author/Change category/Change visibility (LP-008) act on
        // the whole editable selection in one PostService/CategoryService
        // call rather than per-id inside the loop below, since they're not
        // gated per-post the way trash/publish/etc. already are one row at
        // a time.
        if ($bulkAction === 'change_author' && $canEditOthersPosts) {
            $targetAuthorId = (int) ($post['target_author_id'] ?? 0);

            if ($targetAuthorId > 0) {
                $this->posts->bulkReassignAuthor($editableIds, $targetAuthorId);
            }

            return AdminActionResult::redirect(admin_url('posts/all-posts') . $statusSuffix);
        }

        if ($bulkAction === 'add_category' && $canEditPosts) {
            $targetCategoryId = (int) ($post['target_category_id'] ?? 0);

            if ($targetCategoryId > 0) {
                $this->categories->bulkAddToPosts($editableIds, $targetCategoryId);
            }

            return AdminActionResult::redirect(admin_url('posts/all-posts') . $statusSuffix);
        }

        if (($bulkAction === 'set_public' || $bulkAction === 'set_private') && $canPublish) {
            $this->posts->bulkSetVisibility($editableIds, $bulkAction === 'set_private' ? PostVisibility::Private : PostVisibility::Public);

            return AdminActionResult::redirect(admin_url('posts/all-posts') . $statusSuffix);
        }

        foreach ($editableIds as $id) {
            $existing = $this->posts->findById($id);

            if ($existing === null) {
                continue;
            }

            if ($bulkAction === 'trash' && $canDeletePosts) {
                $this->posts->trash($id);
            } elseif ($bulkAction === 'restore' && $canDeletePosts) {
                $this->posts->restore($id);
            } elseif ($bulkAction === 'delete_permanently' && $canDeletePosts && $existing->status === PostStatus::Trashed) {
                $this->revisions->deleteAllFor(RevisionableType::Post, $id);
                $this->posts->delete($id);
            } elseif ($bulkAction === 'draft' && $canPublish) {
                $this->posts->setStatus($id, PostStatus::Draft);
            } elseif ($bulkAction === 'publish' && $canPublish) {
                $this->posts->setStatus($id, PostStatus::Published);
            }
        }

        return AdminActionResult::redirect(admin_url('posts/all-posts') . $statusSuffix);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function save(array $post, array $files, int $currentUserId, bool $canPublish, bool $canEditOthersPosts, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('post_save', $csrfToken)) {
            return $this->invalidRequest();
        }

        $id = (int) ($post['id'] ?? 0);
        $existing = $id > 0 ? $this->posts->findById($id) : null;

        if ($id > 0 && ($existing === null || !$this->canEditPost($existing, $currentUserId, $canEditOthersPosts))) {
            return AdminActionResult::redirect(admin_url('posts/all-posts') . '?error=forbidden');
        }

        $title = trim((string) ($post['title'] ?? ''));
        $content = (string) ($post['content'] ?? '');
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        $metaTitle = trim((string) ($post['meta_title'] ?? ''));
        $metaDescription = trim((string) ($post['meta_description'] ?? ''));
        $slug = trim((string) ($post['slug'] ?? ''));
        $requestedStatus = PostStatus::tryFrom((string) ($post['status'] ?? '')) ?? PostStatus::Draft;
        $commentsOpen = ($post['comments_open'] ?? null) !== null;
        $contentFormat = ContentFormat::tryFrom((string) ($post['content_format'] ?? '')) ?? get_active_editor($currentUserId);

        // Contributors and anyone else without publish_posts can save as a
        // Draft or submit for review (Pending Review, LP-008), but never
        // set Published/Scheduled/Trashed themselves.
        $status = $canPublish
            ? $requestedStatus
            : ($requestedStatus === PostStatus::PendingReview ? PostStatus::PendingReview : PostStatus::Draft);

        // Publish date: required to actually schedule a post (Scheduled),
        // optional as a planned date on a Draft (LP-018 "Draft scheduling")
        // that carries forward automatically if the post is later switched
        // to Scheduled. Gated by $canPublish, same as Visibility/Sticky/
        // Schedule unpublishing below.
        $publishedAt = null;

        if ($canPublish && ($status === PostStatus::Scheduled || $status === PostStatus::Draft)) {
            $rawPublishedAt = trim((string) ($post['published_at'] ?? ''));

            try {
                $publishedAt = $rawPublishedAt !== '' ? new DateTimeImmutable($rawPublishedAt) : null;
            } catch (\Exception) {
                $publishedAt = null;
            }
        }

        // Visibility/Sticky (LP-008) are publish-time decisions, same
        // gate as Status/Schedule above.
        $visibility = $canPublish
            ? (PostVisibility::tryFrom((string) ($post['visibility'] ?? '')) ?? PostVisibility::Public)
            : ($existing?->visibility ?? PostVisibility::Public);
        $isSticky = $canPublish ? ($post['is_sticky'] ?? null) !== null : ($existing?->isSticky ?? false);

        // Schedule unpublishing (LP-008) — same gate; a blank field means
        // "no scheduled unpublish", not "leave the existing one alone",
        // since the field always round-trips the current value back
        // through the form.
        $unpublishAt = null;
        $clearUnpublishAt = false;

        if ($canPublish) {
            $rawUnpublishAt = trim((string) ($post['unpublish_at'] ?? ''));

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
        // over just keeping the current value.
        $featuredImageId = $existing?->featuredImageId;

        if (($post['remove_featured_image'] ?? '') === '1') {
            $featuredImageId = null;
        }

        $selectedFeaturedImageId = (int) ($post['featured_image_id'] ?? 0);

        if ($selectedFeaturedImageId > 0) {
            $featuredImageId = $selectedFeaturedImageId;
        }

        if (isset($files['featured_image_upload']) && $files['featured_image_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedFeaturedImage = $this->media->upload($files['featured_image_upload'], $currentUserId);
                $this->thumbnails->generate($uploadedFeaturedImage);
                $featuredImageId = (int) $uploadedFeaturedImage['id'];
            } catch (Throwable $exception) {
                return AdminActionResult::error('Featured image upload failed: ' . $exception->getMessage());
            }
        }

        // Manual crop (LP-040): the hidden featured_image_crop_for_id field
        // records which media id the on-screen rectangle was drawn against.
        // If the featured image changed in this same request that rectangle
        // no longer applies to anything — it's silently dropped rather than
        // persisted against the wrong image.
        $featuredImageCrop = null;
        $cropForId = (int) ($post['featured_image_crop_for_id'] ?? 0);

        if ($cropForId > 0 && $cropForId === $featuredImageId) {
            $cropX = $post['featured_image_crop_x'] ?? '';
            $cropY = $post['featured_image_crop_y'] ?? '';
            $cropWidth = $post['featured_image_crop_width'] ?? '';
            $cropHeight = $post['featured_image_crop_height'] ?? '';

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

        try {
            // LP-017: snapshot the pre-update content as a revision before
            // it's overwritten. Nothing to snapshot on create — there is no
            // prior state yet.
            if ($existing !== null) {
                $this->revisions->save(
                    RevisionableType::Post,
                    $existing->id,
                    $existing->title,
                    $existing->content,
                    $existing->excerpt,
                    $existing->contentFormat,
                    $currentUserId,
                );
            }

            $savedPost = $existing === null
                ? $this->posts->create($title, $content, $excerpt, $currentUserId, $status, $publishedAt, $featuredImageId, slug: $slug !== '' ? $slug : null, commentsOpen: $commentsOpen, contentFormat: $contentFormat, featuredImageCrop: $featuredImageCrop, visibility: $visibility, isSticky: $isSticky, unpublishAt: $unpublishAt)
                : $this->posts->update($id, $title, $content, $excerpt, $status, $publishedAt, $featuredImageId, $slug !== '' ? $slug : null, $commentsOpen, $contentFormat, featuredImageCrop: $featuredImageCrop, visibility: $visibility, isSticky: $isSticky, unpublishAt: $unpublishAt, clearUnpublishAt: $clearUnpublishAt);

            $this->categories->assignToPost($savedPost->id, is_array($post['category_ids'] ?? null) ? $post['category_ids'] : []);
            $this->tags->assignToPost($savedPost->id, explode(',', (string) ($post['tags'] ?? '')));
            $this->posts->updateSeo($savedPost->id, $metaTitle, $metaDescription);

            // Author reassignment (LP-008) — Editor/Administrator only, same
            // edit_others_posts gate that already lets them edit another
            // author's post at all.
            if ($canEditOthersPosts) {
                $reassignAuthorId = (int) ($post['author_id'] ?? 0);

                if ($reassignAuthorId > 0) {
                    $this->posts->reassignAuthor($savedPost->id, $reassignAuthorId);
                }
            }

            // Custom fields (LP-008) — a repeatable key/value row editor;
            // meta_keys[]/meta_values[] are parallel arrays built by the
            // same index client-side.
            $metaKeys = is_array($post['meta_keys'] ?? null) ? $post['meta_keys'] : [];
            $metaValues = is_array($post['meta_values'] ?? null) ? $post['meta_values'] : [];
            $metaPairs = [];

            foreach ($metaKeys as $metaIndex => $metaKey) {
                $metaPairs[] = ['key' => (string) $metaKey, 'value' => (string) ($metaValues[$metaIndex] ?? '')];
            }

            $this->posts->replaceMetaForPost($savedPost->id, $metaPairs);

            return AdminActionResult::redirect(admin_url('posts/new') . '?id=' . $savedPost->id . '&saved=1');
        } catch (\InvalidArgumentException $exception) {
            return AdminActionResult::error($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    public function restoreRevision(array $post, int $currentUserId, bool $canEditOthersPosts, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);
        $revisionId = (int) ($post['revision_id'] ?? 0);

        if (!Csrf::verify('post_restore_revision_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/new') . '?id=' . $id);
        }

        $existing = $id > 0 ? $this->posts->findById($id) : null;
        $revision = $revisionId > 0 ? $this->revisions->find($revisionId) : null;

        if (
            $existing !== null
            && $this->canEditPost($existing, $currentUserId, $canEditOthersPosts)
            && $revision !== null
            && $revision->contentType === RevisionableType::Post
            && $revision->contentId === $existing->id
        ) {
            // Snapshot the current (pre-restore) state too, so restoring is
            // itself undoable — mirrors the snapshot-before-overwrite done
            // on every normal save above.
            $this->revisions->save(
                RevisionableType::Post,
                $existing->id,
                $existing->title,
                $existing->content,
                $existing->excerpt,
                $existing->contentFormat,
                $currentUserId,
            );

            $this->posts->update(
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

        return AdminActionResult::redirect(admin_url('posts/new') . '?id=' . $id . '&restored=1');
    }

    /**
     * The view previously left a failed CSRF check on quick_draft/
     * bulk_action as a silent no-op (the request just fell through to a
     * normal re-render with no feedback) — the same class of bug LP-081
     * found and LP-082's Themes pass fixed. Now that every branch always
     * executes and returns a result, a failed check gets an actual message
     * instead of vanishing silently. trash/restore_post/delete_permanently/
     * duplicate/restore_revision already redirected explicitly on CSRF
     * failure before this extraction, so that existing (silent-redirect)
     * behavior is preserved unchanged for them.
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }

    /**
     * Echoes a JSON body directly (never `exit`s) so PHPUnit can capture it
     * via output buffering, the same convention `ApiController`'s endpoints
     * already use (see `DECISIONS.md`'s LP-082 entry) — the response shape
     * here is the editor's own pre-existing JSON contract
     * (`content-editor.js` reads `data.filePath`/`url`/`csrfToken`/plain
     * `error` strings), deliberately not `ApiResponse`'s REST envelope,
     * since changing the shape would break the already-shipped client JS.
     *
     * @param array<string, mixed> $files
     */
    public function uploadEditorImage(array $files, int $currentUserId, bool $canUploadFiles, ?string $csrfToken): void
    {
        if (!$canUploadFiles || !Csrf::verify('editor_upload', $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not permitted.']);

            return;
        }

        if (!isset($files['file']) || $files['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(422);
            echo json_encode(['error' => 'Upload failed.', 'csrfToken' => Csrf::token('editor_upload')]);

            return;
        }

        try {
            $uploaded = $this->media->upload($files['file'], $currentUserId);
            // Matches admin/views/media/upload.php's own multi-upload flow
            // — without this, an image uploaded straight from the editor
            // (LP-115's "Upload New" picker step) would report no size
            // options at all in buildEditorPickerItem() below.
            $this->thumbnails->generate($uploaded);

            /*
             * Csrf::verify() is single-use — a second image upload without
             * a full page reload would otherwise fail CSRF verification
             * against the already-consumed token from the initial page
             * load. content-editor.js writes this fresh token back into
             * data-upload-csrf for the next call.
             */
            echo json_encode([
                'data' => ['filePath' => $this->media->url($uploaded)],
                'url' => $this->media->url($uploaded),
                // LP-115: lets a freshly uploaded image drop straight into
                // the picker's own Attachment Display Settings step,
                // same shape queryMediaForPicker() below returns per item.
                'item' => $this->buildEditorPickerItem($uploaded, $this->thumbnails->thumbnailsFor((int) $uploaded['id'])),
                'csrfToken' => Csrf::token('editor_upload'),
            ]);
        } catch (Throwable $exception) {
            http_response_code(422);
            echo json_encode(['error' => $exception->getMessage(), 'csrfToken' => Csrf::token('editor_upload')]);
        }
    }

    /**
     * Paginated, search/folder-filterable image query (LP-115) for the
     * "Insert Image" picker's grid — replaces the old single up-to-500-
     * item `data-media-library` payload embedded on every editor page
     * load with an on-demand AJAX query, so opening the picker on a
     * library of any real size doesn't require loading it in full first.
     *
     * @param array<string, mixed> $post
     */
    public function queryMediaForPicker(array $post, bool $canEditPosts, ?string $csrfToken): void
    {
        $this->queryImagesForPicker($post, $canEditPosts, $csrfToken, 'media_picker_query');
    }

    /**
     * Same paginated image query as queryMediaForPicker() above, reused by
     * the Featured Image sidebar box's grid picker (featured-image-
     * picker.js) — a distinct CSRF action name rather than sharing
     * 'media_picker_query' since both pickers can be open on the same
     * editor page, and Csrf::token() overwrites the single stored token
     * per action name; issuing both under one name would let opening
     * either picker silently invalidate the other's already-embedded token.
     *
     * @param array<string, mixed> $post
     */
    public function queryFeaturedImagePicker(array $post, bool $canEditPosts, ?string $csrfToken): void
    {
        $this->queryImagesForPicker($post, $canEditPosts, $csrfToken, 'featured_image_picker_query');
    }

    /**
     * @param array<string, mixed> $post
     */
    private function queryImagesForPicker(array $post, bool $canEditPosts, ?string $csrfToken, string $csrfAction): void
    {
        if (!$canEditPosts || !Csrf::verify($csrfAction, $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not permitted.']);

            return;
        }

        $term = trim((string) ($post['term'] ?? ''));
        $folderId = (int) ($post['folder_id'] ?? 0);
        $page = max(1, (int) ($post['page'] ?? 1));
        $perPage = 40;

        $filters = ['type' => 'image'];

        if ($term !== '') {
            $filters['term'] = $term;
        }

        if ($folderId > 0) {
            $filters['folderIds'] = [$folderId];
        }

        $result = $this->media->query($filters, $perPage, ($page - 1) * $perPage);
        // One batched query for every item's thumbnails rather than one
        // per item (LP-075's thumbnailsForMany() precedent) — a 40-item
        // page would otherwise mean 40 separate thumbnail lookups.
        $thumbnailsByMediaId = $this->thumbnails->thumbnailsForMany(
            array_map(static fn (array $item): int => (int) $item['id'], $result['items']),
        );

        echo json_encode([
            'items' => array_map(
                fn (array $item): array => $this->buildEditorPickerItem($item, $thumbnailsByMediaId[(int) $item['id']] ?? []),
                $result['items'],
            ),
            'total' => $result['total'],
            // Csrf::verify() is single-use — the picker fires this query
            // repeatedly (every search keystroke, folder change, and
            // "Load More" click) within one dialog session, so each
            // response must hand back a fresh token the same way
            // uploadEditorImage() already does for repeat uploads.
            'csrfToken' => Csrf::token($csrfAction),
        ]);
    }

    /**
     * Shared shape for one Media row in the "Insert Image" picker
     * (LP-115) — a `sizes` map of every size this image actually has a
     * generated thumbnail for (plus the original as "full"), so the
     * picker's Attachment Display Settings step (LP-075) can resolve the
     * size/link-to choice entirely client-side with no extra request.
     * Used by both uploadEditorImage() (one freshly uploaded item) and
     * queryMediaForPicker() (a page of existing items) so the two stay
     * in the same shape content-editor.js's showSettingsStep() expects.
     *
     * @param array<string, mixed> $item
     * @param array<int, array<string, mixed>> $thumbnailsForItem
     * @return array<string, mixed>
     */
    private function buildEditorPickerItem(array $item, array $thumbnailsForItem): array
    {
        $sizes = [
            'full' => [
                'url' => $this->media->url($item),
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
            ],
        ];

        foreach ($thumbnailsForItem as $thumbnail) {
            $sizes[(string) $thumbnail['size_name']] = [
                'url' => $this->thumbnails->url($item, (string) $thumbnail['size_name']),
                'width' => (int) $thumbnail['width'],
                'height' => (int) $thumbnail['height'],
            ];
        }

        return [
            'id' => (int) $item['id'],
            'url' => $this->media->url($item),
            'name' => (string) $item['file_name'],
            'alt' => (string) ($item['alt_text'] ?? ''),
            'folderId' => $item['folder_id'] !== null ? (int) $item['folder_id'] : null,
            'sizes' => $sizes,
        ];
    }

    /**
     * @param array<string, mixed> $post
     */
    public function convertContent(array $post, ?string $csrfToken): void
    {
        if (!Csrf::verify('convert_content', $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);

            return;
        }

        $from = ContentFormat::tryFrom((string) ($post['from'] ?? ''));
        $to = ContentFormat::tryFrom((string) ($post['to'] ?? ''));

        if ($from === null || $to === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Unknown format.']);

            return;
        }

        echo json_encode(['content' => $this->content->convertFormat((string) ($post['content'] ?? ''), $from, $to)]);
    }

    /**
     * @param array<string, mixed> $post
     */
    public function quickAddCategory(array $post, bool $canEditPosts, ?string $csrfToken): void
    {
        if (!$canEditPosts || !Csrf::verify('add_category', $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not permitted.']);

            return;
        }

        $name = trim((string) ($post['name'] ?? ''));

        if ($name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'A category name is required.']);

            return;
        }

        $category = $this->categories->findOrCreateByName($name);

        echo json_encode(['data' => ['id' => $category->id, 'name' => $category->name]]);
    }
}
