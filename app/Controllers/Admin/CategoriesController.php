<?php

/**
 * POST-handling logic for the admin Categories screen (list + editor, combined).
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

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\ThumbnailService;
use Throwable;

/**
 * POST handling for the admin Categories screen, following the same
 * extract-to-controller pattern LP-082/LP-009 established for Posts/
 * Pages (PostsController/PagesController). Methods return an
 * AdminActionResult instead of calling header()/exit themselves, so the
 * capability gate every destructive action here is built around is
 * unit-testable outside a template file.
 *
 * Categories have no author/ownership concept — unlike Pages/Posts,
 * every method here only ever checks a flat delete_posts capability
 * (passed in, not looked up), never a specific category's "owner."
 * Access to the Categories screen at all is already gated behind
 * edit_posts at the admin menu/router level (admin/index.php), which is
 * why save() has no capability check of its own beyond CSRF.
 *
 * The "Category Image" picker's grid query (featured-image-picker.js)
 * stays inline in admin/views/posts/categories.php, mirroring how
 * PagesController leaves the Page editor's equivalent JSON sub-actions
 * inline — it checks only a generic capability, never a specific
 * category, so it carries none of the untested logic this extraction
 * closes.
 */
final class CategoriesController
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly MediaService $media,
        private readonly ThumbnailService $thumbnails,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function save(array $post, array $files, int $currentUserId, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('category_save', $csrfToken)) {
            return $this->invalidRequest();
        }

        $id = (int) ($post['id'] ?? 0);
        $existing = $id > 0 ? $this->categories->findById($id) : null;

        if ($id > 0 && $existing === null) {
            return AdminActionResult::redirect(admin_url('posts/categories') . '?error=forbidden');
        }

        $name = trim((string) ($post['name'] ?? ''));
        $slug = trim((string) ($post['slug'] ?? ''));
        $description = trim((string) ($post['description'] ?? ''));
        $parentId = (int) ($post['parent_id'] ?? 0);

        // Resolution order: an uploaded file wins over the picker's own
        // selected/cleared value, mirroring PostsController::save()'s
        // featured-image resolution (minus crop — see category_image_url()'s
        // docblock for why categories don't need one).
        $imageId = (int) ($post['image_id'] ?? 0) > 0 ? (int) $post['image_id'] : null;

        $archiveDisplayMode = is_string($post['archive_display_mode'] ?? null) ? $post['archive_display_mode'] : '';
        $archiveDisplayMode = in_array($archiveDisplayMode, ['excerpt', 'full'], true) ? $archiveDisplayMode : null;

        if (isset($files['image_upload']) && $files['image_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedImage = $this->media->upload($files['image_upload'], $currentUserId);
                $this->thumbnails->generate($uploadedImage);
                $imageId = (int) $uploadedImage['id'];
            } catch (Throwable $exception) {
                return AdminActionResult::error('Category image upload failed: ' . $exception->getMessage());
            }
        }

        try {
            $category = $existing === null
                ? $this->categories->create($name, $description, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null, $imageId, $archiveDisplayMode)
                : $this->categories->update($id, $name, $description, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null, $imageId, $archiveDisplayMode);

            return AdminActionResult::redirect(admin_url('posts/categories') . '?action=edit&id=' . $category->id . '&saved=1');
        } catch (\InvalidArgumentException $exception) {
            return AdminActionResult::error($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    public function trash(array $post, bool $canDeleteCategories, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('category_trash_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/categories'));
        }

        if ($canDeleteCategories && $id > 0) {
            $this->categories->trash($id);
        }

        return AdminActionResult::redirect(admin_url('posts/categories') . '?trashed=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function restoreCategory(array $post, bool $canDeleteCategories, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('category_restore_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/categories'));
        }

        if ($canDeleteCategories && $id > 0) {
            $this->categories->restore($id);
        }

        return AdminActionResult::redirect(admin_url('posts/categories') . '?status=trash&category_restored=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function deletePermanently(array $post, bool $canDeleteCategories, ?string $csrfToken): AdminActionResult
    {
        $id = (int) ($post['id'] ?? 0);

        if (!Csrf::verify('category_delete_permanently_' . $id, $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/categories'));
        }

        $existing = $id > 0 ? $this->categories->findById($id) : null;

        // Permanent delete is only offered for categories already in the
        // Trash — Move to Trash is the only reachable path to removing
        // one from the "All" view.
        if ($existing !== null && $existing->isTrashed() && $canDeleteCategories) {
            $this->categories->delete($id);
        }

        return AdminActionResult::redirect(admin_url('posts/categories') . '?status=trash&category_deleted=1');
    }

    /**
     * Drag-and-drop reordering, tree view only — sortable.js fills these
     * fields and submits this one shared form on drop. No capability
     * check beyond CSRF, matching the pre-extraction behavior: reordering
     * is non-destructive, and the Categories screen itself is already
     * gated behind edit_posts at the router level.
     *
     * @param array<string, mixed> $post
     */
    public function repositionCategory(array $post, ?string $csrfToken): AdminActionResult
    {
        $draggedId = (int) ($post['dragged_id'] ?? 0);
        $targetId = (int) ($post['target_id'] ?? 0);
        $positionValue = (string) ($post['position'] ?? 'before');

        if (Csrf::verify('category_reposition', $csrfToken) && $draggedId > 0 && $targetId > 0) {
            $this->categories->reorder($draggedId, $targetId, $positionValue === 'after' ? 'after' : 'before');
        }

        return AdminActionResult::redirect(admin_url('posts/categories'));
    }

    public function emptyTrash(bool $canDeleteCategories, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('categories_empty_trash', $csrfToken)) {
            return AdminActionResult::redirect(admin_url('posts/categories'));
        }

        if ($canDeleteCategories) {
            $this->categories->emptyTrash();
        }

        return AdminActionResult::redirect(admin_url('posts/categories') . '?status=trash&trash_emptied=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function bulkAction(array $post, bool $canDeleteCategories, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('categories_bulk_action', $csrfToken)) {
            return $this->invalidRequest();
        }

        $bulkAction = (string) ($post['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($post['category_ids'] ?? null) ? $post['category_ids'] : [])));
        $mergeTargetId = (int) ($post['merge_target_id'] ?? 0);
        $statusSuffix = isset($post['status']) ? '?status=' . urlencode((string) $post['status']) : '';

        if ($canDeleteCategories) {
            if ($bulkAction === 'change_parent') {
                // -1 means "no target chosen" (the placeholder option) — a no-op, distinct
                // from 0, which explicitly means "make top-level" (clear parent_id).
                $changeParentTargetId = (int) ($post['change_parent_target_id'] ?? -1);

                if ($changeParentTargetId >= 0) {
                    $this->categories->bulkChangeParent($ids, $changeParentTargetId > 0 ? $changeParentTargetId : null);
                }
            } else {
                foreach ($ids as $id) {
                    if ($bulkAction === 'trash') {
                        $this->categories->trash($id);
                    } elseif ($bulkAction === 'restore') {
                        $this->categories->restore($id);
                    } elseif ($bulkAction === 'delete_permanently') {
                        $existing = $this->categories->findById($id);

                        if ($existing !== null && $existing->isTrashed()) {
                            $this->categories->delete($id);
                        }
                    } elseif ($bulkAction === 'merge' && $mergeTargetId > 0 && $id !== $mergeTargetId) {
                        $this->categories->merge($id, $mergeTargetId);
                    }
                }
            }
        }

        return AdminActionResult::redirect(admin_url('posts/categories') . $statusSuffix);
    }

    /**
     * A failed CSRF check on save/bulk_action gets an actual error
     * message; trash/restore_category/delete_permanently/
     * reposition_category/empty_trash redirect silently instead —
     * mirrors PagesController::invalidRequest() exactly.
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
