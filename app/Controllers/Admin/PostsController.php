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
 * POST handling for the admin Posts screens. Methods return an
 * AdminActionResult instead of calling header()/exit themselves, so
 * ownership/capability logic is unit-testable outside a template file.
 *
 * The three AJAX-only JSON sub-actions (uploadEditorImage()/
 * convertContent()/quickAddCategory()) echo JSON directly instead,
 * since their response shape is the editor's own pre-existing contract.
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

        // Permanent delete is only honoured for posts already in the Trash — trash first, always.
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
     * Permanently deletes every trashed post the current user is allowed to touch, same guard as deletePermanently().
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

        // These three act on the whole editable selection in one call rather than per-id in the loop below.
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

        // Contributors without publish_posts can save as Draft or submit for review, never Published/Scheduled/Trashed.
        $status = $canPublish
            ? $requestedStatus
            : ($requestedStatus === PostStatus::PendingReview ? PostStatus::PendingReview : PostStatus::Draft);

        // Required to schedule a post, optional as a planned date on a Draft. Gated by $canPublish.
        $publishedAt = null;

        if ($canPublish && ($status === PostStatus::Scheduled || $status === PostStatus::Draft)) {
            $rawPublishedAt = trim((string) ($post['published_at'] ?? ''));

            try {
                $publishedAt = $rawPublishedAt !== '' ? new DateTimeImmutable($rawPublishedAt) : null;
            } catch (\Exception) {
                $publishedAt = null;
            }
        }

        // Publish-time decisions, same gate as Status/Schedule above.
        $visibility = $canPublish
            ? (PostVisibility::tryFrom((string) ($post['visibility'] ?? '')) ?? PostVisibility::Public)
            : ($existing?->visibility ?? PostVisibility::Public);
        $isSticky = $canPublish ? ($post['is_sticky'] ?? null) !== null : ($existing?->isSticky ?? false);

        // Same gate; a blank field means "no scheduled unpublish", not "leave the existing one alone".
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

        // Resolution order: upload wins over existing-image select, which wins over "remove", which wins over keeping current.
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

        // If the featured image changed in this same request, the crop rectangle no longer applies and is dropped.
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
            // Snapshot pre-update content as a revision before it's overwritten. Nothing to snapshot on create.
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

            // Editor/Administrator only, same gate that lets them edit another author's post at all.
            if ($canEditOthersPosts) {
                $reassignAuthorId = (int) ($post['author_id'] ?? 0);

                if ($reassignAuthorId > 0) {
                    $this->posts->reassignAuthor($savedPost->id, $reassignAuthorId);
                }
            }

            // meta_keys[]/meta_values[] are parallel arrays built by the same index client-side.
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
            // Snapshot the current state too, so restoring is itself undoable.
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
     * A failed CSRF check on quick_draft/bulk_action gets an actual
     * error message; trash/restore_post/delete_permanently/duplicate/
     * restore_revision redirect silently instead, unchanged from before.
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }

    /**
     * Echoes a JSON body directly (never `exit`s) so PHPUnit can capture
     * it via output buffering. Matches content-editor.js's own
     * pre-existing JSON contract.
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
            // Without this, an editor-uploaded image would report no size options in buildEditorPickerItem() below.
            $this->thumbnails->generate($uploaded);

            // Csrf::verify() is single-use — a fresh token lets a second upload succeed without a page reload.
            echo json_encode([
                'data' => ['filePath' => $this->media->url($uploaded)],
                'url' => $this->media->url($uploaded),
                // Same shape queryMediaForPicker() returns, so a fresh upload drops into the picker's display-settings step.
                'item' => $this->buildEditorPickerItem($uploaded, $this->thumbnails->thumbnailsFor((int) $uploaded['id'])),
                'csrfToken' => Csrf::token('editor_upload'),
            ]);
        } catch (Throwable $exception) {
            http_response_code(422);
            echo json_encode(['error' => $exception->getMessage(), 'csrfToken' => Csrf::token('editor_upload')]);
        }
    }

    /**
     * Paginated, search/folder-filterable image query for the "Insert
     * Image" picker's grid, fetched on demand rather than loading the
     * whole library on every editor page load.
     *
     * @param array<string, mixed> $post
     */
    public function queryMediaForPicker(array $post, bool $canEditPosts, ?string $csrfToken): void
    {
        $this->queryImagesForPicker($post, $canEditPosts, $csrfToken, 'media_picker_query');
    }

    /**
     * Same query as queryMediaForPicker(), reused by the Featured Image
     * sidebar picker — a distinct CSRF action name since both pickers
     * can be open on the same page and Csrf::token() overwrites a
     * shared name's token.
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
        // One batched query for every item's thumbnails rather than 40 separate lookups per page.
        $thumbnailsByMediaId = $this->thumbnails->thumbnailsForMany(
            array_map(static fn (array $item): int => (int) $item['id'], $result['items']),
        );

        echo json_encode([
            'items' => array_map(
                fn (array $item): array => $this->buildEditorPickerItem($item, $thumbnailsByMediaId[(int) $item['id']] ?? []),
                $result['items'],
            ),
            'total' => $result['total'],
            // Single-use tokens: the picker fires this query repeatedly, so each response hands back a fresh one.
            'csrfToken' => Csrf::token($csrfAction),
        ]);
    }

    /**
     * Shared shape for one Media row in the "Insert Image" picker — a
     * `sizes` map of every generated thumbnail size plus the original as
     * "full", so the Attachment Display Settings step resolves entirely
     * client-side. Used by both uploadEditorImage() and
     * queryMediaForPicker() to stay in the shape content-editor.js expects.
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

        try {
            $category = $this->categories->findOrCreateByName($name);
        } catch (\InvalidArgumentException $exception) {
            http_response_code(422);
            echo json_encode(['error' => $exception->getMessage()]);

            return;
        }

        echo json_encode(['data' => ['id' => $category->id, 'name' => $category->name]]);
    }
}
