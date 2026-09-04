<?php

/**
 * Best-effort recursive directory removal, used by the installer to delete itself (install/) after a successful install (LP-004).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core;

use ErrorException;

/**
 * Best-effort recursive directory removal, used by the installer to
 * remove itself (install/) after a successful install. Never throws: a
 * locked-down shared host that won't let PHP delete its own files is an
 * expected, unremarkable outcome, not an error condition — the caller is
 * responsible for telling the administrator to remove the directory by
 * hand when remove() returns false.
 */
final class InstallerCleanup
{
    /**
     * Recursively removes $directory and everything inside it, including
     * the file currently executing this code — safe on Unix-like
     * filesystems, where unlinking an open file only removes its
     * directory entry and the process keeps running against the
     * already-open inode.
     *
     * Returns true only if the entire tree was removed; stops at the
     * first failure rather than leaving a partial, inconsistent subset.
     */
    public function remove(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }

        $items = scandir($directory);

        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_link($path)) {
                if (!$this->unlinkSafely($path)) {
                    return false;
                }
            } elseif (is_dir($path)) {
                if (!$this->remove($path)) {
                    return false;
                }
            } elseif (!$this->unlinkSafely($path)) {
                return false;
            }
        }

        return $this->rmdirSafely($directory);
    }

    private function unlinkSafely(string $path): bool
    {
        try {
            return unlink($path);
        } catch (ErrorException) {
            return false;
        }
    }

    private function rmdirSafely(string $directory): bool
    {
        try {
            return rmdir($directory);
        } catch (ErrorException) {
            return false;
        }
    }
}
