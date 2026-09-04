<?php

/**
 * The storage-driver interface MediaService goes through instead of touching the filesystem directly.
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

/**
 * "Future-Proof Storage" — the storage-driver seam MediaService goes
 * through instead of touching the filesystem directly, so a future
 * Amazon S3/Cloudflare R2/other S3-compatible driver can be a drop-in
 * implementation of this interface rather than a rewrite of
 * upload()/replace()/delete()'s internals. Only LocalFilesystemStorage
 * exists today — no remote driver, third-party SDK, or credential/config
 * UI has been built yet; that remains a substantially larger feature on
 * its own. ThumbnailService and MediaImportService still talk to the
 * local filesystem directly and are not yet routed through this
 * interface — out of scope for this pass.
 */
interface MediaStorageInterface
{
    /**
     * Moves the file at $sourcePath (a local path — typically PHP's own
     * upload tmp_name) into storage at $relativePath, creating whatever
     * intermediate directory structure $relativePath implies.
     */
    public function put(string $sourcePath, string $relativePath): bool;

    public function delete(string $relativePath): bool;

    public function exists(string $relativePath): bool;

    public function url(string $relativePath): string;

    /**
     * A real, local filesystem path for $relativePath — needed by
     * operations that must read the file's actual bytes on this server
     * (getimagesize(), hash_file()) rather than just reference it. A
     * remote driver can't satisfy this without first downloading the file
     * locally — see the interface docblock's note on why a remote driver
     * isn't a drop-in yet for those specific steps.
     */
    public function absolutePath(string $relativePath): string;
}
