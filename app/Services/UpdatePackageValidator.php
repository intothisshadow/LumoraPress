<?php

/**
 * Validates an uploaded update ZIP and stages it into a private directory for review before installation.
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

use RuntimeException;
use ZipArchive;

/**
 * Validates an uploaded update ZIP and, once it passes structural checks,
 * extracts it into a private staging directory for review before
 * UpdateService installs it. Recoverable, business-rule problems (a
 * missing PHP extension, a downgrade, low disk space) are collected into
 * the returned result's "blocking"/"warnings" lists rather than thrown, so
 * an administrator sees every problem at once; only truly exceptional
 * conditions (a corrupt archive, an unsafe path, a missing version.php)
 * throw.
 */
final class UpdatePackageValidator
{
    private const MAX_ENTRIES = 20000;

    private const MAX_UNCOMPRESSED_SIZE = 500 * 1024 * 1024;

    private const REQUIRED_ENTRIES = [
        'version.php',
        'app/Core/Kernel.php',
        'admin/index.php',
        'include/bootstrap.php',
    ];

    private const DEFAULT_REQUIRES_PHP = '8.2';

    /**
     * @param array<int, string> $corePaths Paths (relative to $installRoot) the
     *     updater is allowed to overwrite — used only to check writability here.
     */
    public function __construct(
        private readonly string $installRoot,
        private readonly string $stagingRoot,
        private readonly array $corePaths,
    ) {
    }

    /**
     * @return array{
     *     blocking: array<int, string>,
     *     warnings: array<int, string>,
     *     from_version: string,
     *     to_version: string,
     *     token: string,
     *     staging_path: string,
     *     root_prefix: string,
     * }
     */
    public function validateAndStage(string $uploadedZipPath, string $installedVersion, bool $allowDowngrade): array
    {
        if (!is_file($uploadedZipPath)) {
            throw new RuntimeException('The uploaded file could not be found.');
        }

        $zip = new ZipArchive();

        if ($zip->open($uploadedZipPath) !== true) {
            throw new RuntimeException('The uploaded file is not a valid ZIP archive.');
        }

        $numFiles = $zip->numFiles;

        if ($numFiles === 0) {
            $zip->close();

            throw new RuntimeException('The uploaded archive is empty.');
        }

        if ($numFiles > self::MAX_ENTRIES) {
            $zip->close();

            throw new RuntimeException('The archive contains too many files to be a valid Lumora Press package.');
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
                $zip->close();

                throw new RuntimeException('The archive contains an unsafe file path and was rejected.');
            }

            $names[] = $name;
            $totalUncompressed += (int) $stat['size'];
        }

        if ($totalUncompressed > self::MAX_UNCOMPRESSED_SIZE) {
            $zip->close();

            throw new RuntimeException('The archive is too large to be processed safely.');
        }

        $rootPrefix = $this->detectRootPrefix($names);

        foreach (self::REQUIRED_ENTRIES as $required) {
            if (!in_array($rootPrefix . $required, $names, true)) {
                $zip->close();

                throw new RuntimeException(
                    "The archive does not look like a valid Lumora Press update package (missing {$required}).",
                );
            }
        }

        $freeSpace = disk_free_space($this->installRoot);

        if ($freeSpace !== false && $freeSpace < $totalUncompressed * 3) {
            $zip->close();

            throw new RuntimeException(
                'There is not enough free disk space to safely apply this update (need roughly 3x the package size).',
            );
        }

        $token = bin2hex(random_bytes(16));
        $stagingPath = rtrim($this->stagingRoot, '/') . '/' . $token;

        if (!is_dir($stagingPath) && !mkdir($stagingPath, 0755, true) && !is_dir($stagingPath)) {
            $zip->close();

            throw new RuntimeException('Unable to create a staging directory for the update.');
        }

        if (!$zip->extractTo($stagingPath)) {
            $zip->close();
            $this->removeDirectory($stagingPath);

            throw new RuntimeException('Failed to extract the update archive.');
        }

        $zip->close();

        $effectiveRoot = rtrim($stagingPath . '/' . $rootPrefix, '/');
        $versionFile = $effectiveRoot . '/version.php';

        if (!is_file($versionFile)) {
            $this->removeDirectory($stagingPath);

            throw new RuntimeException('The archive does not contain a readable version.php.');
        }

        $manifest = require $versionFile;

        if (!is_array($manifest) || !isset($manifest['version']) || !is_string($manifest['version']) || $manifest['version'] === '') {
            $this->removeDirectory($stagingPath);

            throw new RuntimeException('The package version.php did not return valid version information.');
        }

        $toVersion = $manifest['version'];

        if (preg_match('/^\d+\.\d+\.\d+/', $toVersion) !== 1) {
            $this->removeDirectory($stagingPath);

            throw new RuntimeException('The package reports an invalid version number.');
        }

        $blocking = [];
        $warnings = [];

        $comparison = version_compare($toVersion, $installedVersion);

        if ($comparison < 0 && !$allowDowngrade) {
            $blocking[] = sprintf(
                'This package (version %s) is older than the installed version (%s). Confirm "allow downgrade" to proceed anyway.',
                $toVersion,
                $installedVersion,
            );
        } elseif ($comparison === 0) {
            $warnings[] = sprintf('This package is the same version (%s) that is already installed.', $toVersion);
        }

        $requiresPhp = is_string($manifest['requires_php'] ?? null) ? $manifest['requires_php'] : self::DEFAULT_REQUIRES_PHP;

        if (version_compare(PHP_VERSION, $requiresPhp, '<')) {
            $blocking[] = sprintf(
                'This package requires PHP %s or higher; the server is running PHP %s.',
                $requiresPhp,
                PHP_VERSION,
            );
        }

        $requiredExtensions = is_array($manifest['requires_extensions'] ?? null) ? $manifest['requires_extensions'] : [];

        foreach ($requiredExtensions as $extension) {
            if (is_string($extension) && !extension_loaded($extension)) {
                $blocking[] = "The required PHP extension \"{$extension}\" is not loaded.";
            }
        }

        if (!is_writable($this->installRoot)) {
            $blocking[] = 'The installation directory is not writable by the web server.';
        }

        foreach ($this->corePaths as $corePath) {
            $target = rtrim($this->installRoot, '/') . '/' . $corePath;

            if (is_file($target) || is_dir($target)) {
                if (!is_writable($target)) {
                    $blocking[] = "\"{$corePath}\" is not writable by the web server.";
                }
            }
        }

        if ($blocking !== []) {
            $this->removeDirectory($stagingPath);
        }

        return [
            'blocking' => $blocking,
            'warnings' => $warnings,
            'from_version' => $installedVersion,
            'to_version' => $toVersion,
            'token' => $token,
            'staging_path' => $stagingPath,
            'root_prefix' => $rootPrefix,
        ];
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

    /**
     * Detects a single wrapping top-level directory, e.g. GitHub's
     * "LumoraPress-1.2.0/" export folder, so its contents are still
     * recognised as a valid package.
     *
     * @param array<int, string> $names
     */
    private function detectRootPrefix(array $names): string
    {
        if (in_array('version.php', $names, true)) {
            return '';
        }

        foreach ($names as $name) {
            if (str_ends_with($name, '/version.php') && substr_count($name, '/') === 1) {
                return substr($name, 0, -strlen('version.php'));
            }
        }

        return '';
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
