<?php

/**
 * Creates 301 redirects from every published post's old URL to its new one when the permalink structure changes.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.18.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;

/**
 * Redirects live in the ordinary admin-managed redirects table, so the
 * admin can review, edit, or delete them on Settings > Redirects exactly
 * like hand-made ones — nothing here is a hidden parallel mechanism.
 */
final class PermalinkRedirectService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Database $database,
        private readonly PostService $posts,
        private readonly PermalinkService $permalinks,
        private readonly RedirectService $redirects,
    ) {
    }

    /**
     * Returns the number of posts whose URL actually changed (and so got a
     * redirect). Runs in one transaction so a failure part-way through
     * never leaves only some posts redirected.
     */
    public function redirectPostsForStructureChange(string $oldStructure, string $newStructure): int
    {
        if ($oldStructure === $newStructure) {
            return 0;
        }

        return (int) $this->database->transaction(function () use ($oldStructure, $newStructure): int {
            $count = 0;
            $page = 1;

            do {
                $batch = $this->posts->paginatePublished($page, self::BATCH_SIZE);

                foreach ($batch['posts'] as $post) {
                    $oldPath = $this->permalinks->postPathForStructure($post, $oldStructure);
                    $newPath = $this->permalinks->postPathForStructure($post, $newStructure);

                    if ($oldPath === $newPath) {
                        continue;
                    }

                    $this->redirectPath($oldPath, $newPath);
                    $count++;
                }

                $page++;
            } while ($page <= $batch['totalPages']);

            return $count;
        });
    }

    private function redirectPath(string $oldPath, string $newPath): void
    {
        $oldUrl = home_url($oldPath);
        $newUrl = home_url($newPath);

        // Switching back to an earlier structure: the redirect created by
        // the first switch now has a live URL as its source and would turn
        // into a self-redirect once retargeted below.
        $reverse = $this->redirects->findBySourcePath($newPath);

        if ($reverse !== null && (string) $reverse['target_url'] === $oldUrl) {
            $this->redirects->delete((int) $reverse['id']);
        }

        $this->redirects->retarget($oldUrl, $newUrl);

        $existing = $this->redirects->findBySourcePath($oldPath);

        if ($existing === null) {
            $this->redirects->create($oldPath, $newUrl, 301);
        } elseif ((string) $existing['target_url'] !== $newUrl) {
            $this->redirects->update((int) $existing['id'], $oldPath, $newUrl, 301);
        }
    }
}
