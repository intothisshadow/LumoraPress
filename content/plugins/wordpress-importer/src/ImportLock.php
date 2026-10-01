<?php

/**
 * Keeps two requests from running the same WordPress import at once.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.20.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\WordPressImporter;

/**
 * An exclusive, non-blocking file lock held for the length of one import request. The
 * operating system drops it when the process ends, so a crashed or timed-out request
 * can never leave the import stuck behind a stale lock.
 *
 * Fails open when the lock file can't be created (a read-only storage folder): losing
 * the guard is better than refusing to import at all.
 */
final class ImportLock
{
    private const PATH_SUFFIX = '/storage/wordpress-importer/import.lock';

    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $installRoot)
    {
    }

    /**
     * @return bool false only when another request currently holds the lock
     */
    public function acquire(): bool
    {
        $path = rtrim($this->installRoot, '/') . self::PATH_SUFFIX;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            // mkdir() warns when an ancestor is a file or unwritable, so check the nearest real folder first.
            $existing = dirname($directory);

            while (!file_exists($existing) && dirname($existing) !== $existing) {
                $existing = dirname($existing);
            }

            if (!is_dir($existing) || !is_writable($existing) || (!mkdir($directory, 0755, true) && !is_dir($directory))) {
                return true;
            }
        }

        if (!is_writable($directory) || (is_file($path) && !is_writable($path))) {
            return true;
        }

        $handle = fopen($path, 'c');

        if ($handle === false) {
            return true;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
