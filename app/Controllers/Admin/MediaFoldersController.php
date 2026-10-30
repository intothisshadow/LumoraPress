<?php

/**
 * Media Manager folder actions that change per-user or bulk state: pinning folders and moving files into one.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.26.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\UserService;

final class MediaFoldersController
{
    public const PIN_CSRF_ACTION = 'pin_media_folder';

    public function __construct(
        private readonly UserService $users,
        private readonly FolderService $folders,
        private readonly MediaService $media,
    ) {
    }

    /**
     * Pins or unpins a folder for one user. Pins are a personal preference, so
     * the only gate beyond CSRF is being able to open the Media Manager.
     *
     * @param array<string, mixed> $post
     */
    public function setPinned(array $post, int $userId, bool $canUploadFiles, ?string $csrfToken, string $backUrl): AdminActionResult
    {
        if (!$canUploadFiles || !Csrf::verify(self::PIN_CSRF_ACTION, $csrfToken)) {
            return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
        }

        $folderId = (int) ($post['id'] ?? 0);

        if ($folderId <= 0 || $this->folders->findById($folderId) === null) {
            return AdminActionResult::error('That folder no longer exists.');
        }

        $this->users->setMediaFolderPinned($userId, $folderId, ($post['pin'] ?? '') === '1', $this->existingFolderIds());

        return AdminActionResult::redirect($backUrl);
    }

    /**
     * The ids of every folder, for pruning a stored pin list.
     *
     * @return array<int, int>
     */
    public function existingFolderIds(): array
    {
        return array_map(static fn ($folder): int => $folder->id, $this->folders->listAll());
    }

    /**
     * Moves files into a folder, or back to General Uploads for a target of 0. The
     * target is checked here because the bulk-move form and the drag-and-drop move
     * both reach this, and a stale or forged id would otherwise orphan the files.
     *
     * @param array<int, int> $ids
     * @return int|string The number of files moved, or an error message.
     */
    public function moveFiles(array $ids, int $targetFolderId): int|string
    {
        if ($targetFolderId < 0 || ($targetFolderId > 0 && $this->folders->findById($targetFolderId) === null)) {
            return 'That folder no longer exists, so nothing was moved.';
        }

        return $this->media->moveMany($ids, $targetFolderId > 0 ? $targetFolderId : null);
    }
}
