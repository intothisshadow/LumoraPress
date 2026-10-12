<?php

/**
 * Validates and installs a plugin ZIP directly into content/plugins/{slug}.
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
 * Validates and installs a plugin ZIP into content/plugins/{slug}. Mirrors ThemeInstaller
 * closely — same safety checks, same extract-then-rename rollback shape — with two
 * differences: a plugin's header lives in its main PHP file (named after its own directory),
 * not a fixed-name file like style.css, so the slug is derived from directory structure; and
 * an install can collide with an existing plugin of the same slug, requiring a two-request
 * confirm flow (stage() holds the upload, inspectStaged() previews it, finalize() applies
 * it). A stale stage is never a security problem, so no expiry beyond next-install-or-cancel.
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
     * 'versionRelation' compares the uploaded version with the installed one: 'newer', 'same',
     * 'older', or 'unknown' when there is no installed copy or either version is missing.
     *
     * @return array{slug: string, name: string, version: string, existing: ?PluginInfo, versionRelation: string}
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

        $existing = $this->plugins->infoFor($slug);

        return [
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'existing' => $existing,
            'versionRelation' => $this->versionRelation($existing !== null ? $existing->version : '', $version),
        ];
    }

    private function versionRelation(string $installed, string $uploaded): string
    {
        if ($installed === '' || $uploaded === '') {
            return 'unknown';
        }

        return match (version_compare($uploaded, $installed)) {
            1 => 'newer',
            -1 => 'older',
            default => 'same',
        };
    }

    /**
     * Installs (or, with $replace, overwrites) a previously staged
     * upload, then deletes the staged file regardless of outcome — a
     * staged upload is only ever meant to be finalized once. With $updateSlug the archive must
     * be for that already-installed plugin.
     */
    public function finalize(string $token, bool $replace, ?string $updateSlug = null): PluginInfo
    {
        $path = $this->stagedPath($token);

        if ($path === null) {
            throw new RuntimeException('That upload could not be found. Please upload the file again.');
        }

        try {
            return $updateSlug !== null ? $this->update($path, $updateSlug) : $this->install($path, $replace);
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

        $this->extractAndSwap($zip, $rootPrefix, $destination);

        $info = $this->plugins->infoFor($slug);

        if ($info === null) {
            throw new RuntimeException('The plugin was installed but could not be read back.');
        }

        return $info;
    }

    /**
     * Replaces an installed plugin's files with a ZIP's contents. The ZIP must be for the same
     * plugin folder, so uploading the wrong plugin can't overwrite another one. Active state and
     * saved settings live in the database, so they are untouched.
     */
    public function update(string $zipPath, string $slug): PluginInfo
    {
        $destination = rtrim($this->pluginsPath, '/') . '/' . $slug;

        if ($slug === '' || !is_dir($destination)) {
            throw new RuntimeException('That plugin could not be found.');
        }

        if (!is_file($zipPath)) {
            throw new RuntimeException('The uploaded file could not be found.');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            $names = $this->validatedEntryNames($zip);
            [$archiveSlug, $rootPrefix] = $this->detectSlugAndRootPrefix($zip, $names);

            $this->assertMatchesSlug($archiveSlug, $slug);
            $this->extractAndSwap($zip, $rootPrefix, $destination);
        } finally {
            $zip->close();
        }

        $info = $this->plugins->infoFor($slug);

        if ($info === null) {
            throw new RuntimeException('The plugin was updated but could not be read back.');
        }

        return $info;
    }

    /**
     * Stages an upload meant to update $slug, rejecting a ZIP for a different plugin before the
     * review screen is ever shown.
     */
    public function stageUpdate(string $uploadedTmpPath, string $slug): string
    {
        $token = $this->stage($uploadedTmpPath);

        try {
            $archive = $this->inspectStaged($token);
            $this->assertMatchesSlug($archive['slug'], $slug);
        } catch (RuntimeException $exception) {
            $this->discardStaged($token);

            throw $exception;
        }

        return $token;
    }

    private function assertMatchesSlug(string $archiveSlug, string $slug): void
    {
        if ($archiveSlug !== $slug) {
            throw new RuntimeException("That ZIP is for the plugin \"{$archiveSlug}\", not \"{$slug}\". Nothing was changed.");
        }
    }

    /**
     * Extracts into a staging folder beside the destination, then swaps it in. An existing
     * destination is renamed aside first and renamed back if the new folder can't take its
     * place, so a failure never leaves the plugin missing.
     */
    private function extractAndSwap(ZipArchive $zip, string $rootPrefix, string $destination): void
    {
        $staging = $destination . '.installing-' . bin2hex(random_bytes(4));

        if (!mkdir($staging, 0755, true)) {
            throw new RuntimeException('Unable to create the plugin directory.');
        }

        if (!$zip->extractTo($staging)) {
            $this->removeDirectory($staging);

            throw new RuntimeException('Failed to extract the plugin archive.');
        }

        // GitHub-style exports wrap everything in "plugin-slug-1.0.0/"; the swap moves that
        // inner folder so it doesn't end up nested inside the installed plugin.
        $extractedRoot = rtrim($staging . '/' . $rootPrefix, '/');

        $this->swapDirectories($extractedRoot, $destination);
        $this->removeDirectory($staging);
    }

    private function swapDirectories(string $newDirectory, string $destination): void
    {
        if (!is_dir($newDirectory)) {
            throw new RuntimeException('The extracted plugin files could not be found. Nothing was changed.');
        }

        if (!is_dir($destination)) {
            if (!rename($newDirectory, $destination)) {
                throw new RuntimeException('Failed to finalize the plugin installation.');
            }

            return;
        }

        $displaced = $destination . '.replaced-' . bin2hex(random_bytes(4));

        if (!rename($destination, $displaced)) {
            throw new RuntimeException('Could not move the existing plugin files aside. Nothing was changed.');
        }

        if (!rename($newDirectory, $destination)) {
            rename($displaced, $destination);

            throw new RuntimeException('Could not install the new plugin files; the previous version was restored.');
        }

        $this->removeDirectory($displaced);
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
