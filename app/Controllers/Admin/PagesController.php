<?php

/**
 * POST-handling logic for the admin Pages screens (list + editor).
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use DateTimeImmutable;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PageVisibility;
use LumoraPress\Models\RevisionableType;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\RevisionService;
use LumoraPress\Services\ThumbnailService;
use Throwable;

/**
 * POST handling for the admin Pages screens, following the same
 * extract-to-controller pattern LP-082 established for Posts
 * (PostsController). Methods return an AdminActionResult instead of
 * calling header()/exit themselves, so the ownership/capability gate
 * every action here is built around is unit-testable outside a
 * template file. quickEdit() echoes JSON directly instead, since its
 * response shape is quick-edit.js's own pre-existing contract.
 *
 * The editor's other JSON sub-actions (image upload, format
 * conversion, media picker queries) stay inline in admin/views/pages/
 * new.php — they check only generic capabilities, never a specific
 * page's ownership, so they carry none of the untested logic this
 * extraction closes.
 */
final class PagesController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly RevisionService $revisions,
        private readonly MediaService $media,
        private readonly ThumbnailService $thumbnails,
    ) {
    }

    /**
     * An author/contributor may only touch their own pages unless they hold
     * edit_others_posts (Administrator/Editor) — Pages reuse the Posts
     * capabilities, since no dedicated page capabilities exist yet.
     */
    private function canEditPage(Page $page, int $currentUserId, bool $canEditOthersPages): bool
    {
        return $canEditOthersPages || $page->authorId === $currentUserId;
    }

    /**
     * Echoes a JSON body directly (never `exit`s) so PHPUnit can capture
     * it via output buffering, matching PostsController::
     * uploadEditorImage()'s convention. The admin list row stays on
     * screen and updates in place via quick-edit.js rather than a redirect.
     *
     * @param array<string, mixed> $post
     */
    public function quickEdit(array $post, int $currentUserId, bool $canPublish, bool $canEditOthersPages, ?string $csrfToken): void
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('page_quick_edit_' . $id, $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);

            return;
        }

        $existing = $id > 0 ? $this->pages->findById($id) : null;

        if ($existing === null || !$this->canEditPage($existing, $currentUserId, $canEditOthersPages)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not permitted.']);

            return;
        }

        $title = trim((string) ($post['title'] ?? ''));

        if ($title === '') {
            http_response_code(422);
            echo json_encode(['error' => 'A title is required.']);

            return;
        }

        $slug = trim((string) ($post['slug'] ?? ''));
        $parentId = (int) ($post['parent_id'] ?? 0);
        $requestedStatus = PageStatus::tryFrom((string) ($post['status'] ?? ''));
        // Quick Edit only ever offers Draft/Published (see PageService::
        // quickUpdate()'s docblock) — anything else submitted keeps the
        // page's current status, same as a contributor without
        // publish_posts always being forced to Draft in the full editor.
        $status = ($canPublish && in_array($requestedStatus, [PageStatus::Draft, PageStatus::Published], true))
            ? $requestedStatus
            : $existing->status;

        $updated = $this->pages->quickUpdate($id, $title, $status, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null);

        if ($updated === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Could not save that page.']);

            return;
        }

        echo json_encode([
            'data' => [
                'id' => $updated->id,
                'title' => $updated->title,
                'slug' => $updated->slug,
                'status' => $updated->status->value,
                'statusLabel' => $updated->status->label(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $post
     */
    public function trash(array $post, int $currentUserId, bool $canDeletePages, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('page_trash_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('pages/all-pages'));
        }

        $existing = $id > 0 ? $this->pages->findById($id) : null;

        if ($existing !== null && $canDeletePages && $this->canEditPage($existing, $currentUserId, $canEditOthersPages)) {
            $this->pages->trash($id);
        }

        return AdminActionResult::redirect(admin_url('pages/all-pages') . '?trashed=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function restorePage(array $post, int $currentUserId, bool $canDeletePages, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('page_restore_page_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('pages/all-pages'));
        }

        $existing = $id > 0 ? $this->pages->findById($id) : null;

        if ($existing !== null && $canDeletePages && $this->canEditPage($existing, $currentUserId, $canEditOthersPages)) {
            $this->pages->restore($id);
        }

        return AdminActionResult::redirect(admin_url('pages/all-pages') . '?status=' . PageStatus::Trashed->value . '&page_restored=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function deletePermanently(array $post, int $currentUserId, bool $canDeletePages, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('page_delete_permanently_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('pages/all-pages'));
        }

        $existing = $id > 0 ? $this->pages->findById($id) : null;

        // Permanent delete is only honoured for pages already in the Trash — trash first, always.
        if (
            $existing !== null
            && $existing->status === PageStatus::Trashed
            && $canDeletePages
            && $this->canEditPage($existing, $currentUserId, $canEditOthersPages)
        ) {
            $this->revisions->deleteAllFor(RevisionableType::Page, $id);
            $this->pages->delete($id);
        }

        return AdminActionResult::redirect(admin_url('pages/all-pages') . '?status=' . PageStatus::Trashed->value . '&page_deleted=1');
    }

    /**
     * Permanently deletes every trashed page the current user is allowed to touch, same guard as deletePermanently().
     */
    public function emptyTrash(int $currentUserId, bool $canDeletePages, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('pages_empty_trash', $csrfToken)) {
            return AdminActionResult::redirect(admin_url('pages/all-pages'));
        }

        if ($canDeletePages) {
            $trashedCount = $this->pages->countByStatus(PageStatus::Trashed);
            $trashedPages = $trashedCount > 0
                ? $this->pages->paginateForAdmin(1, $trashedCount, PageStatus::Trashed)['pages']
                : [];

            foreach ($trashedPages as $trashedPage) {
                if (!$this->canEditPage($trashedPage, $currentUserId, $canEditOthersPages)) {
                    continue;
                }

                $this->revisions->deleteAllFor(RevisionableType::Page, $trashedPage->id);
                $this->pages->delete($trashedPage->id);
            }
        }

        return AdminActionResult::redirect(admin_url('pages/all-pages') . '?status=' . PageStatus::Trashed->value . '&trash_emptied=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function duplicate(array $post, int $currentUserId, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('page_duplicate_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('pages/all-pages'));
        }

        $existing = $id > 0 ? $this->pages->findById($id) : null;

        if ($existing !== null && $this->canEditPage($existing, $currentUserId, $canEditOthersPages)) {
            $duplicate = $this->pages->duplicate($id, $currentUserId);

            if ($duplicate !== null) {
                return AdminActionResult::redirect(admin_url('pages/new') . '?id=' . $duplicate->id . '&duplicated=1');
            }
        }

        return AdminActionResult::redirect(admin_url('pages/all-pages'));
    }

    /**
     * Drag-and-drop reordering, tree view only — sortable.js fills these
     * fields and submits this one shared form on drop.
     *
     * @param array<string, mixed> $post
     */
    public function repositionPage(array $post, int $currentUserId, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        $draggedId = (int) ($post['dragged_id'] ?? 0);
        $targetId = (int) ($post['target_id'] ?? 0);
        $positionValue = (string) ($post['position'] ?? 'before');

        if (Csrf::verify('page_reposition', $csrfToken) && $draggedId > 0 && $targetId > 0) {
            $existing = $this->pages->findById($draggedId);

            if ($existing !== null && $this->canEditPage($existing, $currentUserId, $canEditOthersPages)) {
                $this->pages->reorder($draggedId, $targetId, $positionValue === 'after' ? 'after' : 'before');
            }
        }

        return AdminActionResult::redirect(admin_url('pages/all-pages') . '?saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function bulkAction(
        array $post,
        int $currentUserId,
        bool $canPublish,
        bool $canDeletePages,
        bool $canEditOthersPages,
        ?string $csrfToken,
    ): AdminActionResult {
        if (!Csrf::verify('pages_bulk_action', $csrfToken)) {
            return $this->invalidRequest();
        }

        $statusSuffix = isset($post['status']) ? '?status=' . urlencode((string) $post['status']) : '';
        $bulkAction = (string) ($post['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($post['page_ids'] ?? null) ? $post['page_ids'] : [])));
        $editableIds = array_values(array_filter($ids, function (int $id) use ($currentUserId, $canEditOthersPages): bool {
            $existing = $this->pages->findById($id);

            return $existing !== null && $this->canEditPage($existing, $currentUserId, $canEditOthersPages);
        }));

        // These three act on the whole editable selection in one call rather than per-id in the loop below.
        if ($bulkAction === 'change_parent' && $canEditOthersPages) {
            $targetParentId = (int) ($post['target_parent_id'] ?? 0);

            foreach ($editableIds as $id) {
                $this->pages->setParent($id, $targetParentId > 0 ? $targetParentId : null);
            }

            return AdminActionResult::redirect(admin_url('pages/all-pages') . $statusSuffix);
        }

        if ($bulkAction === 'change_author' && $canEditOthersPages) {
            $targetAuthorId = (int) ($post['target_author_id'] ?? 0);

            if ($targetAuthorId > 0) {
                $this->pages->bulkReassignAuthor($editableIds, $targetAuthorId);
            }

            return AdminActionResult::redirect(admin_url('pages/all-pages') . $statusSuffix);
        }

        if (($bulkAction === 'set_public' || $bulkAction === 'set_private') && $canPublish) {
            $this->pages->bulkSetVisibility($editableIds, $bulkAction === 'set_private' ? PageVisibility::Private : PageVisibility::Public);

            return AdminActionResult::redirect(admin_url('pages/all-pages') . $statusSuffix);
        }

        foreach ($editableIds as $id) {
            $existing = $this->pages->findById($id);

            if ($existing === null) {
                continue;
            }

            if ($bulkAction === 'trash' && $canDeletePages) {
                $this->pages->trash($id);
            } elseif ($bulkAction === 'restore' && $canDeletePages) {
                $this->pages->restore($id);
            } elseif ($bulkAction === 'delete_permanently' && $canDeletePages && $existing->status === PageStatus::Trashed) {
                $this->revisions->deleteAllFor(RevisionableType::Page, $id);
                $this->pages->delete($id);
            } elseif ($bulkAction === 'draft' && $canPublish) {
                $this->pages->setStatus($id, PageStatus::Draft);
            } elseif ($bulkAction === 'publish' && $canPublish) {
                $this->pages->setStatus($id, PageStatus::Published);
            }
        }

        return AdminActionResult::redirect(admin_url('pages/all-pages') . $statusSuffix);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function save(array $post, array $files, int $currentUserId, bool $canPublish, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('page_save', $csrfToken)) {
            return $this->invalidRequest();
        }

        $id = (int) ($post['id'] ?? 0);
        $existing = $id > 0 ? $this->pages->findById($id) : null;

        if ($id > 0 && ($existing === null || !$this->canEditPage($existing, $currentUserId, $canEditOthersPages))) {
            return AdminActionResult::redirect(admin_url('pages/all-pages') . '?error=forbidden');
        }

        $title = trim((string) ($post['title'] ?? ''));
        $content = (string) ($post['content'] ?? '');
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        $metaTitle = trim((string) ($post['meta_title'] ?? ''));
        $metaDescription = trim((string) ($post['meta_description'] ?? ''));
        $slug = trim((string) ($post['slug'] ?? ''));
        $requestedStatus = PageStatus::tryFrom((string) ($post['status'] ?? '')) ?? PageStatus::Draft;
        $parentId = (int) ($post['parent_id'] ?? 0);
        $contentFormat = ContentFormat::tryFrom((string) ($post['content_format'] ?? '')) ?? get_active_editor($currentUserId);
        $commentsOpen = ($post['comments_open'] ?? null) !== null;

        // Contributors and anyone else without publish_posts can save as a
        // Draft or submit for review (Pending Review), but never set
        // Published/Scheduled/Trashed themselves — mirrors
        // PostsController::save()'s identical gate.
        $status = $canPublish
            ? $requestedStatus
            : ($requestedStatus === PageStatus::PendingReview ? PageStatus::PendingReview : PageStatus::Draft);

        $publishedAt = null;

        if ($status === PageStatus::Scheduled) {
            $rawPublishedAt = trim((string) ($post['published_at'] ?? ''));

            try {
                $publishedAt = $rawPublishedAt !== '' ? new DateTimeImmutable($rawPublishedAt) : null;
            } catch (\Exception) {
                $publishedAt = null;
            }
        }

        // Visibility is a publish-time decision, same gate as Status above.
        $visibility = $canPublish
            ? (PageVisibility::tryFrom((string) ($post['visibility'] ?? '')) ?? PageVisibility::Public)
            : ($existing?->visibility ?? PageVisibility::Public);

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

        if ($title === '') {
            return AdminActionResult::error('A title is required.');
        }

        try {
            // Snapshot pre-update content as a revision before it's overwritten. Nothing to snapshot on create.
            if ($existing !== null) {
                $this->revisions->save(
                    RevisionableType::Page,
                    $existing->id,
                    $existing->title,
                    $existing->content,
                    $existing->excerpt,
                    $existing->contentFormat,
                    $currentUserId,
                );
            }

            $savedPage = $existing === null
                ? $this->pages->create($title, $content, $excerpt, $currentUserId, $status, $publishedAt, $parentId > 0 ? $parentId : null, $featuredImageId, $slug !== '' ? $slug : null, $contentFormat, featuredImageCrop: $featuredImageCrop, visibility: $visibility, commentsOpen: $commentsOpen)
                : $this->pages->update($id, $title, $content, $excerpt, $status, $publishedAt, $parentId > 0 ? $parentId : null, $featuredImageId, $slug !== '' ? $slug : null, $contentFormat, featuredImageCrop: $featuredImageCrop, visibility: $visibility, commentsOpen: $commentsOpen);

            $this->pages->updateSeo($savedPage->id, $metaTitle, $metaDescription);

            return AdminActionResult::redirect(admin_url('pages/new') . '?id=' . $savedPage->id . '&saved=1');
        } catch (\InvalidArgumentException $exception) {
            return AdminActionResult::error($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    public function restoreRevision(array $post, int $currentUserId, bool $canEditOthersPages, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);
        $revisionId = (int) ($post['revision_id'] ?? 0);

        if (!Csrf::verify('page_restore_revision_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('pages/new') . '?id=' . $id);
        }

        $existing = $id > 0 ? $this->pages->findById($id) : null;
        $revision = $revisionId > 0 ? $this->revisions->find($revisionId) : null;

        if (
            $existing !== null
            && $this->canEditPage($existing, $currentUserId, $canEditOthersPages)
            && $revision !== null
            && $revision->contentType === RevisionableType::Page
            && $revision->contentId === $existing->id
        ) {
            // Snapshot the current (pre-restore) state too, so restoring is itself undoable.
            $this->revisions->save(
                RevisionableType::Page,
                $existing->id,
                $existing->title,
                $existing->content,
                $existing->excerpt,
                $existing->contentFormat,
                $currentUserId,
            );

            $this->pages->update(
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
                visibility: $existing->visibility,
                commentsOpen: $existing->commentsOpen,
            );
        }

        return AdminActionResult::redirect(admin_url('pages/new') . '?id=' . $id . '&restored=1');
    }

    /**
     * A failed CSRF check on bulk_action gets an actual error message;
     * trash/restore_page/delete_permanently/duplicate/reposition_page/
     * restore_revision redirect silently instead — mirrors
     * PostsController::invalidRequest() exactly.
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
