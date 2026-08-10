<?php

/**
 * Tracks per-file SHA-256 checksums of the core application as of the last successful install/restore, for the update pipeline's modified-core-file check.
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

namespace LumoraPress\Services;

/**
 * Records a SHA-256 checksum for every file under `$corePaths` as it existed
 * immediately after the last successful install/restore — the "pristine"
 * baseline UpdateService::modifiedCoreFileProblems() compares the live
 * filesystem against before the *next* update overwrites those same files,
 * so an administrator gets a heads-up if they (or a plugin) hand-edited a
 * core file since then.
 *
 * Same on-disk-JSON convention as UpdateManifest: a private path-suffix
 * constant, read/write helpers that fail safe (a missing/corrupt file reads
 * back as "nothing recorded yet", never as license to report every file as
 * modified) rather than throwing.
 */
final class UpdateChecksumManifest
{
    private const PATH_SUFFIX = '/storage/updates/checksums.json';

    public function __construct(private readonly string $installRoot)
    {
    }

    /**
     * @return array<string, string> relative path => sha256 hex digest
     */
    public function read(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return [];
        }

        $checksums = [];

        foreach ($decoded as $relativePath => $hash) {
            if (is_string($relativePath) && is_string($hash)) {
                $checksums[$relativePath] = $hash;
            }
        }

        return $checksums;
    }

    /**
     * @param array<string, string> $checksums relative path => sha256 hex digest
     */
    public function write(array $checksums): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents($path, json_encode($checksums));
    }

    /**
     * Discards the recorded baseline — used after restoring an arbitrary
     * (possibly much older) backup, where the live files no longer
     * correspond to the most recent install's checksums at all. A missing
     * manifest reads back as "nothing recorded yet" (see read()), so this
     * only ever suppresses a stale comparison; the next successful install
     * simply starts recording again from scratch.
     */
    public function clear(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Computes a fresh checksum map for every file currently on disk under
     * $corePaths (relative to $installRoot) — used right after install()
     * overlays a release package, while those files are guaranteed to
     * still match exactly what the package shipped.
     *
     * @param array<int, string> $corePaths
     * @return array<string, string> relative path => sha256 hex digest
     */
    public function computeForCorePaths(array $corePaths): array
    {
        $checksums = [];

        foreach ($corePaths as $corePath) {
            $this->collect(rtrim($this->installRoot, '/') . '/' . $corePath, $corePath, $checksums);
        }

        return $checksums;
    }

    /**
     * @param array<string, string> $checksums
     */
    private function collect(string $absolute, string $relative, array &$checksums): void
    {
        if (is_file($absolute)) {
            $hash = hash_file('sha256', $absolute);

            if ($hash !== false) {
                $checksums[$relative] = $hash;
            }

            return;
        }

        if (!is_dir($absolute)) {
            return;
        }

        foreach (array_diff(scandir($absolute) ?: [], ['.', '..']) as $item) {
            $this->collect($absolute . '/' . $item, $relative . '/' . $item, $checksums);
        }
    }

    private function path(): string
    {
        return rtrim($this->installRoot, '/') . self::PATH_SUFFIX;
    }
}
