<?php

/**
 * Validates and installs a plugin ZIP directly into content/plugins/{slug} (LP-045).
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

use LumoraPress\Core\Plugin\PluginInfo;
use LumoraPress\Core\Plugin\PluginRegistry;
use RuntimeException;
use ZipArchive;

/**
 * Validates and installs a plugin ZIP directly into
 * content/plugins/{slug} (LP-045). Mirrors ThemeInstaller closely — same
 * path-traversal/size/entry-count safety checks, same "extract to a temp
 * directory, only rename into place once everything succeeded" rollback
 * shape — with two differences a plugin's shape requires:
 *
 * 1. A plugin's header lives in its main PHP file, named identically to
 *    its own directory (content/plugins/{slug}/{slug}.php — see
 *    PluginManager::load()), not in a fixed-name file like style.css, so
 *    the slug has to be derived from the archive's directory structure
 *    itself rather than parsed out of a filename constant.
 * 2. Unlike a theme (LP-034's ThemeInstaller, which always installs
 *    immediately since only one theme is ever "in the way" at a time —
 *    the currently active one, guarded separately by isActive), a
 *    plugin install can collide with an already-installed plugin of the
 *    same slug, and LP-045 requires showing the admin what's being
 *    installed *before* committing, with an explicit Replace/Cancel
 *    choice on collision. That needs a two-request flow: stage() moves
 *    the uploaded ZIP to a short-lived holding directory and returns a
 *    token, inspectStaged() reads it back for the confirmation screen,
 *    and finalize() performs the real install/replace once confirmed.
 *    A stale stage is never a security problem (it can only be replayed
 *    into the same guarded install() path a direct upload would already
 *    reach), so no expiry beyond "next install or explicit cancel
 *    deletes it" is implemented.
 */
final class PluginInstaller
{
    private const MAX_ENTRIES = 4000;

    private const MAX_UNCOMPRESSED_SIZE = 100 * 1024 * 1024;

    public function __construct(
        private readonly string $pluginsPath,
        private readonly string $stagingPath,
        private readonly PluginRegistry $plugins,
    ) {
    }

    /**
     * Removes an installed plugin's directory entirely. Whether the
     * plugin is currently active is the caller's concern — this class
     * only knows about the filesystem, not which plugins are active —
     * so callers must check PluginInfo::$isActive (and deactivate first)
     * before calling this.
     */
    public function delete(string $slug): void
    {
        $destination = rtrim($this->pluginsPath, '/') . '/' . $slug;

        if ($slug === '' || !is_dir($destination)) {
            throw new RuntimeException('That plugin could not be found.');
        }

        $this->removeDirectory($destination);
    }

    /**
     * Moves an uploaded ZIP out of PHP's request-scoped tmp location
     * into a holding directory that survives to the next request, so
     * the confirmation step doesn't require re-uploading. Returns an
     * opaque token identifying the staged file.
     */
    public function stage(string $uploadedTmpPath): string
    {
        if (!is_dir($this->stagingPath) && !mkdir($this->stagingPath, 0755, true) && !is_dir($this->stagingPath)) {
            throw new RuntimeException('Unable to create the plugin staging directory.');
        }

        $token = bin2hex(random_bytes(16));
        $stagedFile = rtrim($this->stagingPath, '/') . '/' . $token . '.zip';

        if (!move_uploaded_file($uploadedTmpPath, $stagedFile) && !rename($uploadedTmpPath, $stagedFile)) {
            throw new RuntimeException('Unable to store the uploaded file for review.');
        }

        return $token;
    }

    /**
     * Reads a staged archive's contents back out without extracting
     * anything, for the "here's what you're about to install" review
     * screen. Also reports whether a plugin with the same slug is
     * already installed, so the caller can offer Replace/Update instead
     * of a plain Install action.
     *
     * @return array{slug: string, name: string, version: string, existing: ?PluginInfo}
     */
    public function inspectStaged(string $token): array
    {
        $path = $this->stagedPath($token);

        if ($path === null) {
            throw new RuntimeException('That upload could not be found. Please upload the file again.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            [$slug, $name, $version] = $this->readArchiveHeader($zip);
        } finally {
            $zip->close();
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'existing' => $this->plugins->infoFor($slug),
        ];
    }

    /**
     * Installs (or, with $replace, overwrites) a previously staged
     * upload, then deletes the staged file regardless of outcome — a
     * staged upload is only ever meant to be finalized once.
     */
    public function finalize(string $token, bool $replace): PluginInfo
    {
        $path = $this->stagedPath($token);

        if ($path === null) {
            throw new RuntimeException('That upload could not be found. Please upload the file again.');
        }

        try {
            return $this->install($path, $replace);
        } finally {
            $this->discardStaged($token);
        }
    }

    public function discardStaged(string $token): void
    {
        $path = $this->stagedPath($token);

        if ($path !== null) {
            unlink($path);
        }
    }

    private function stagedPath(string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        $path = rtrim($this->stagingPath, '/') . '/' . $token . '.zip';

        return is_file($path) ? $path : null;
    }

    private function install(string $zipPath, bool $replace): PluginInfo
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('The uploaded file could not be found.');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            return $this->installFromOpenArchive($zip, $replace);
        } finally {
            $zip->close();
        }
    }

    private function installFromOpenArchive(ZipArchive $zip, bool $replace): PluginInfo
    {
        $names = $this->validatedEntryNames($zip);
        [$slug, $rootPrefix] = $this->detectSlugAndRootPrefix($zip, $names);

        $destination = rtrim($this->pluginsPath, '/') . '/' . $slug;
        $alreadyExists = is_dir($destination);

        if ($alreadyExists && !$replace) {
            throw new RuntimeException("A plugin named \"{$slug}\" is already installed. Remove it first, or confirm replacing it.");
        }

        $tempDestination = $destination . '.installing-' . bin2hex(random_bytes(4));

        if (!mkdir($tempDestination, 0755, true)) {
            throw new RuntimeException('Unable to create the plugin directory.');
        }

        if (!$zip->extractTo($tempDestination)) {
            $this->removeDirectory($tempDestination);

            throw new RuntimeException('Failed to extract the plugin archive.');
        }

        $extractedRoot = rtrim($tempDestination . '/' . $rootPrefix, '/');

        // Only remove the previous installation once the new one has
        // extracted successfully — if extraction above had failed, the
        // existing plugin is left completely untouched (LP-045's
        // "roll back installation on failure").
        if ($alreadyExists) {
            $this->removeDirectory($destination);
        }

        if ($rootPrefix !== '') {
            // The plugin's files live one level down (e.g. a GitHub
            // export wrapping everything in "plugin-slug-1.0.0/") —
            // move them up to the real destination directly rather than
            // leaving an extra nested folder inside the installed
            // plugin.
            $moved = rename($extractedRoot, $destination);
            $this->removeDirectory($tempDestination);
        } else {
            $moved = rename($tempDestination, $destination);
        }

        if (!$moved) {
            $this->removeDirectory($tempDestination);
            $this->removeDirectory($destination);

            throw new RuntimeException('Failed to finalize the plugin installation.');
        }

        $info = $this->plugins->infoFor($slug);

        if ($info === null) {
            throw new RuntimeException('The plugin was installed but could not be read back.');
        }

        return $info;
    }

    /**
     * @return array<int, string>
     */
    private function validatedEntryNames(ZipArchive $zip): array
    {
        $numFiles = $zip->numFiles;

        if ($numFiles === 0) {
            throw new RuntimeException('The uploaded archive is empty.');
        }

        if ($numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('The archive contains too many files to be a valid plugin package.');
        }

        $names = [];
        $totalUncompressed = 0;

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];

            if ($this->isUnsafeEntryName($name)) {
                throw new RuntimeException('The archive contains an unsafe file path and was rejected.');
            }

            $names[] = $name;
            $totalUncompressed += (int) $stat['size'];
        }

        if ($totalUncompressed > self::MAX_UNCOMPRESSED_SIZE) {
            throw new RuntimeException('The archive is too large to be processed safely.');
        }

        return $names;
    }

    /**
     * A plugin's main file is named identically to its own directory
     * (content/plugins/{slug}/{slug}.php — see PluginManager::load()),
     * so the slug has to be derived by finding that pattern in the
     * archive rather than reading a fixed filename. Requires exactly one
     * top-level directory containing a PHP file of the same name with a
     * parseable "Plugin Name:" header.
     *
     * @param array<int, string> $names
     * @return array{0: string, 1: string} slug, rootPrefix
     */
    private function detectSlugAndRootPrefix(ZipArchive $zip, array $names): array
    {
        $topLevelDirs = [];

        foreach ($names as $name) {
            if (substr_count(rtrim($name, '/'), '/') === 0 && !str_ends_with($name, '/')) {
                // A file sitting directly at the archive root — not the
                // "one wrapping directory" shape this installer expects.
                continue;
            }

            $firstSegment = strstr($name, '/', true);

            if ($firstSegment !== false && $firstSegment !== '') {
                $topLevelDirs[$firstSegment] = true;
            }
        }

        if (count($topLevelDirs) !== 1) {
            throw new RuntimeException('The archive must contain exactly one top-level plugin folder.');
        }

        $rootPrefix = array_key_first($topLevelDirs) . '/';
        $candidateSlug = rtrim($rootPrefix, '/');
        $mainFileEntry = $rootPrefix . $candidateSlug . '.php';

        if (!in_array($mainFileEntry, $names, true)) {
            throw new RuntimeException("The archive does not contain a \"{$candidateSlug}.php\" main file matching its folder name.");
        }

        $mainFileContents = $zip->getFromName($mainFileEntry);

        if ($mainFileContents === false) {
            throw new RuntimeException('Unable to read the plugin\'s main file from the archive.');
        }

        if ($this->parsePluginName($mainFileContents) === '') {
            throw new RuntimeException('The archive\'s main file does not have a valid "Plugin Name:" header.');
        }

        return [$this->slugify($candidateSlug), $rootPrefix];
    }

    /**
     * @return array{0: string, 1: string, 2: string} slug, name, version
     */
    private function readArchiveHeader(ZipArchive $zip): array
    {
        $names = $this->validatedEntryNames($zip);
        [$slug, $rootPrefix] = $this->detectSlugAndRootPrefix($zip, $names);

        $mainFileEntry = $rootPrefix . rtrim($rootPrefix, '/') . '.php';
        $contents = (string) $zip->getFromName($mainFileEntry);

        $name = $this->parsePluginName($contents);
        $version = '';

        if (preg_match('/^[\s*\/]*Version:\s*(.+)$/mi', $contents, $matches) === 1) {
            $version = trim($matches[1]);
        }

        return [$slug, $name !== '' ? $name : $this->titleCaseSlug($slug), $version];
    }

    private function parsePluginName(string $mainFileContents): string
    {
        if (preg_match('/^[\s*\/]*Plugin Name:\s*(.+)$/mi', $mainFileContents, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    private function isUnsafeEntryName(string $name): bool
    {
        if ($name === '') {
            return true;
        }

        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return true;
        }

        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            return true;
        }

        return str_contains($name, '..');
    }

    private function slugify(string $value): string
    {
        // Lowercase first — the character class below only keeps
        // a-z0-9, so applying it before lowercasing would treat every
        // uppercase letter as a separator and shred the name.
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }

    private function titleCaseSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
