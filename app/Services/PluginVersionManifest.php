<?php

/**
 * Records the installed version of each bundled plugin, so a plugin can be updated independently of a full core release.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

/**
 * A `slug => version` JSON record of every bundled plugin's installed
 * version. version.php only tracks core, so without this a plugin-scoped
 * update would have nothing to compare against or log as its "from"
 * version.
 *
 * Same fail-safe convention as UpdateManifest: a missing or corrupt file
 * reads back as empty, never throws. Each plugin's own `Version:` header
 * stays authoritative — sync() folds it back in, which is also how the
 * record is seeded the first time with no migration step.
 */
final class PluginVersionManifest
{
    private const PATH_SUFFIX = '/storage/updates/plugin-versions.json';

    public function __construct(private readonly string $installRoot)
    {
    }

    /**
     * @return array<string, string>
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

        $versions = [];

        foreach ($decoded as $slug => $version) {
            if (is_string($slug) && is_string($version) && $version !== '') {
                $versions[$slug] = $version;
            }
        }

        return $versions;
    }

    public function versionFor(string $slug): ?string
    {
        return $this->read()[$slug] ?? null;
    }

    public function record(string $slug, string $version): void
    {
        $versions = $this->read();
        $versions[$slug] = $version;

        $this->write($versions);
    }

    /**
     * Folds in the versions read from each plugin's own header, adding
     * missing entries and correcting stale ones (e.g. after a core update
     * overlaid a newer bundled copy). Writes only when something changed,
     * since this runs on ordinary admin page loads.
     *
     * @param array<string, string> $headerVersions
     *
     * @return array<string, string> the record after syncing
     */
    public function sync(array $headerVersions): array
    {
        $versions = $this->read();
        $changed = false;

        foreach ($headerVersions as $slug => $version) {
            if ($version === '' || ($versions[$slug] ?? null) === $version) {
                continue;
            }

            $versions[$slug] = $version;
            $changed = true;
        }

        if ($changed) {
            $this->write($versions);
        }

        return $versions;
    }

    /**
     * @param array<string, string> $versions
     */
    private function write(array $versions): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        ksort($versions);

        // Atomic write — this file is read on every Plugins screen load.
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, json_encode($versions, JSON_PRETTY_PRINT)) === false) {
            return;
        }

        rename($tmp, $path);
    }

    private function path(): string
    {
        return rtrim($this->installRoot, '/') . self::PATH_SUFFIX;
    }
}
