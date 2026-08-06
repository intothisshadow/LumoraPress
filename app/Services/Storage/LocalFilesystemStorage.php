<?php

/**
 * The local-disk MediaStorageInterface implementation, storing uploads under a base path/URL.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Storage;

use Closure;

/**
 * The only MediaStorageInterface implementation today — local disk under
 * a base path/URL, matching Lumora Press's original (pre-abstraction)
 * upload()/replace()/delete()/url() behavior exactly. See
 * MediaStorageInterface's docblock for the bigger picture.
 */
final class LocalFilesystemStorage implements MediaStorageInterface
{
    private readonly Closure $moveFile;

    /**
     * @param Closure(string, string): bool|null $moveFile Overrides the
     *     file-move operation — defaults to move_uploaded_file(). See
     *     MediaService's own constructor docblock for why this needs to
     *     be overridable in tests (PHP's is_uploaded_file(), which
     *     move_uploaded_file() depends on, can only ever pass for a file
     *     that arrived via a real HTTP upload, never from a CLI test
     *     process).
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $baseUrl,
        ?Closure $moveFile = null,
    ) {
        $this->moveFile = $moveFile ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
    }

    public function put(string $sourcePath, string $relativePath): bool
    {
        $destination = $this->absolutePath($relativePath);
        $directory = dirname($destination);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        return ($this->moveFile)($sourcePath, $destination);
    }

    public function delete(string $relativePath): bool
    {
        $path = $this->absolutePath($relativePath);

        if (!is_file($path)) {
            return false;
        }

        return unlink($path);
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->absolutePath($relativePath));
    }

    public function url(string $relativePath): string
    {
        return rtrim($this->baseUrl, '/') . '/' . $relativePath;
    }

    public function absolutePath(string $relativePath): string
    {
        return rtrim($this->basePath, '/') . '/' . $relativePath;
    }
}
