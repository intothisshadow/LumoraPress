<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Database\Migrator;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\InstallerCleanup;
use LumoraPress\Models\UpdateStatus;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the manual ZIP update pipeline: validate + stage an upload,
 * show the administrator a summary, and — once confirmed — back up, apply,
 * migrate, verify, and log the result, rolling back files and the database
 * automatically if anything after the backup fails.
 */
final class UpdateService
{
    private const PENDING_FILE = 'pending.json';

    private const PENDING_TTL_SECONDS = 1800;

    private const LOCK_STALE_SECONDS = 600;

    /**
     * @param array<int, string> $corePaths Paths (relative to $installRoot) the
     *     updater overlays from the staged package onto the installation.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly HookManager $hooks,
        private readonly UpdatePackageValidator $validator,
        private readonly UpdateBackupService $backups,
        private readonly string $installRoot,
        private readonly string $migrationsPath,
        private readonly string $versionFilePath,
        private readonly string $stagingRoot,
        private readonly string $lockFilePath,
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
     * }
     */
    public function checkUpload(string $uploadedZipPath, bool $allowDowngrade, string $source = 'manual'): array
    {
        $installedVersion = $this->installedVersion();

        $result = $this->validator->validateAndStage($uploadedZipPath, $installedVersion, $allowDowngrade);

        if ($result['blocking'] === []) {
            $pendingPath = rtrim($result['staging_path'], '/') . '/' . self::PENDING_FILE;

            file_put_contents($pendingPath, json_encode([
                'from_version' => $result['from_version'],
                'to_version' => $result['to_version'],
                'root_prefix' => $result['root_prefix'],
                'source' => $source,
                'created_at' => time(),
            ], JSON_THROW_ON_ERROR));
        }

        unset($result['staging_path']);

        return $result;
    }

    public function cancel(string $token): void
    {
        $this->validateToken($token);
        $this->removeDirectory($this->stagingPathFor($token));
    }

    /**
     * @return array{status: UpdateStatus, message: string, from_version: string, to_version: string}
     */
    public function install(string $token, ?int $performedByUserId): array
    {
        $this->validateToken($token);

        $stagingPath = $this->stagingPathFor($token);
        $pending = $this->readPending($stagingPath);

        $fromVersion = $pending['from_version'];
        $toVersion = $pending['to_version'];
        $rootPrefix = $pending['root_prefix'];
        $source = $pending['source'];

        $this->acquireLock();

        $filesBackupPath = null;
        $databaseBackupPath = null;

        try {
            $this->hooks->doAction('lumora_press_before_update', $fromVersion, $toVersion);

            $filesBackupPath = $this->backups->backupFiles($fromVersion);
            $databaseBackupPath = $this->backups->backupDatabase($fromVersion);

            $effectiveRoot = rtrim($stagingPath . '/' . $rootPrefix, '/');

            foreach ($this->corePaths as $corePath) {
                $this->overlayPath($effectiveRoot . '/' . $corePath, rtrim($this->installRoot, '/') . '/' . $corePath);
            }

            (new Migrator($this->database, $this->migrationsPath, $this->tablePrefix))->migrate();

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            $this->clearCache();

            $installedVersion = $this->installedVersion();

            if ($installedVersion !== $toVersion) {
                throw new RuntimeException('The installed version did not match the update package after applying it.');
            }

            /*
             * install/ is one of $corePaths, so a release package that
             * ships it just re-extracted it onto the installation above —
             * resurrecting it even on a site where the administrator had
             * already deleted it after their original install. Best-effort
             * only, same as the installer's own cleanup: a locked-down host
             * that won't let PHP delete its own files is an unremarkable
             * outcome here too, and the Dashboard's leftover-install-
             * directory alert (LP-030) still catches it either way.
             */
            (new InstallerCleanup())->remove(rtrim($this->installRoot, '/') . '/install');

            $this->removeDirectory($stagingPath);
            $this->logAttempt($fromVersion, $toVersion, $source, UpdateStatus::Success, 'Update applied successfully.', $filesBackupPath, $databaseBackupPath, $performedByUserId);
            $this->hooks->doAction('lumora_press_after_update', $fromVersion, $toVersion, UpdateStatus::Success);

            return [
                'status' => UpdateStatus::Success,
                'message' => "Successfully updated from {$fromVersion} to {$toVersion}.",
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
            ];
        } catch (Throwable $exception) {
            error_log('[updates] Update failed: ' . $exception->getMessage());

            $status = UpdateStatus::Failed;
            $message = 'The update failed and no changes were made.';

            if ($filesBackupPath !== null) {
                try {
                    $this->backups->restoreFiles($filesBackupPath);

                    if ($databaseBackupPath !== null) {
                        $this->backups->restoreDatabase($databaseBackupPath);
                    }

                    $status = UpdateStatus::RolledBack;
                    $message = 'The update failed and was automatically rolled back to the previous version.';
                } catch (Throwable $restoreException) {
                    error_log('[updates] Rollback failed: ' . $restoreException->getMessage());
                    $message = 'The update failed and automatic rollback also failed. Restore manually from storage/backups/ immediately.';
                }
            }

            $this->removeDirectory($stagingPath);
            $this->logAttempt($fromVersion, $toVersion, $source, $status, $message, $filesBackupPath, $databaseBackupPath, $performedByUserId);
            $this->hooks->doAction('lumora_press_after_update', $fromVersion, $toVersion, $status);

            return [
                'status' => $status,
                'message' => $message,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
            ];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentLog(int $limit = 10): array
    {
        $limit = max(1, $limit);

        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->tablePrefix . "update_log ORDER BY created_at DESC LIMIT {$limit}",
        );
    }

    /**
     * @return array<int, array{
     *     version: string,
     *     created_at: int,
     *     files_filename: ?string,
     *     files_size: ?int,
     *     database_filename: ?string,
     *     database_size: ?int,
     * }>
     */
    public function listBackups(): array
    {
        return $this->backups->listBackups();
    }

    /**
     * Restores a backup pair, taking the same update lock install() does
     * so a restore can never run concurrently with an in-progress update.
     * $filesFilename/$databaseFilename are basenames only (see
     * UpdateBackupService::restoreFilesByFilename()'s docblock) — never
     * full paths an admin form could tamper with.
     */
    public function restoreBackup(string $filesFilename, ?string $databaseFilename): void
    {
        $this->acquireLock();

        try {
            $this->backups->restoreFilesByFilename($filesFilename);

            if ($databaseFilename !== null) {
                $this->backups->restoreDatabaseByFilename($databaseFilename);
            }

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            $this->clearCache();
        } finally {
            $this->releaseLock();
        }
    }

    public function installedVersion(): string
    {
        $manifest = require $this->versionFilePath;

        return is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';
    }

    /**
     * Empties storage/cache/ after a successful update so nothing served
     * from before the update lingers alongside the new version's code.
     */
    private function clearCache(): void
    {
        $cachePath = rtrim($this->installRoot, '/') . '/storage/cache';

        if (!is_dir($cachePath)) {
            return;
        }

        foreach (array_diff(scandir($cachePath) ?: [], ['.', '..']) as $item) {
            $this->removeDirectory($cachePath . '/' . $item);
        }
    }

    private function overlayPath(string $source, string $target): void
    {
        if (!file_exists($source)) {
            return;
        }

        if (file_exists($target)) {
            if (is_dir($target) && !is_link($target)) {
                $this->removeDirectory($target);
            } else {
                unlink($target);
            }
        }

        $parent = dirname($target);

        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException("Unable to prepare the destination directory for {$target}.");
        }

        if (!rename($source, $target)) {
            $this->copyRecursive($source, $target);
            $this->removeDirectory($source);
        }
    }

    private function copyRecursive(string $source, string $target): void
    {
        if (is_dir($source)) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException("Unable to create directory {$target}.");
            }

            foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $item) {
                $this->copyRecursive($source . '/' . $item, $target . '/' . $item);
            }

            return;
        }

        if (!copy($source, $target)) {
            throw new RuntimeException("Unable to copy {$source} to {$target}.");
        }
    }

    private function removeDirectory(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $this->removeDirectory($path . '/' . $item);
        }

        rmdir($path);
    }

    private function validateToken(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new RuntimeException('Invalid update token.');
        }
    }

    private function stagingPathFor(string $token): string
    {
        return rtrim($this->stagingRoot, '/') . '/' . $token;
    }

    /**
     * @return array{from_version: string, to_version: string, root_prefix: string, source: string, created_at: int}
     */
    private function readPending(string $stagingPath): array
    {
        $pendingPath = rtrim($stagingPath, '/') . '/' . self::PENDING_FILE;

        if (!is_file($pendingPath)) {
            throw new RuntimeException('No pending update was found for this token. Please upload the package again.');
        }

        $contents = file_get_contents($pendingPath);
        $pending = $contents !== false ? json_decode($contents, true) : null;

        if (!is_array($pending) || !isset($pending['from_version'], $pending['to_version'], $pending['root_prefix'], $pending['created_at'])) {
            throw new RuntimeException('The pending update record is corrupt. Please upload the package again.');
        }

        if (time() - (int) $pending['created_at'] > self::PENDING_TTL_SECONDS) {
            $this->removeDirectory($stagingPath);

            throw new RuntimeException('This pending update has expired. Please upload the package again.');
        }

        return [
            'from_version' => (string) $pending['from_version'],
            'to_version' => (string) $pending['to_version'],
            'root_prefix' => (string) $pending['root_prefix'],
            'source' => is_string($pending['source'] ?? null) ? $pending['source'] : 'manual',
            'created_at' => (int) $pending['created_at'],
        ];
    }

    private function acquireLock(): void
    {
        if (is_file($this->lockFilePath)) {
            $lockedAt = (int) file_get_contents($this->lockFilePath);

            if (time() - $lockedAt < self::LOCK_STALE_SECONDS) {
                throw new RuntimeException('Another update is already in progress. Please wait for it to finish.');
            }
        }

        $parent = dirname($this->lockFilePath);

        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to prepare the update lock directory.');
        }

        file_put_contents($this->lockFilePath, (string) time());
    }

    private function releaseLock(): void
    {
        if (is_file($this->lockFilePath)) {
            unlink($this->lockFilePath);
        }
    }

    private function logAttempt(
        string $fromVersion,
        string $toVersion,
        string $source,
        UpdateStatus $status,
        string $message,
        ?string $filesBackupPath,
        ?string $databaseBackupPath,
        ?int $performedByUserId,
    ): void {
        $this->database->execute(
            'INSERT INTO ' . $this->tablePrefix . 'update_log
                (from_version, to_version, source, status, message, backup_files_path, backup_database_path, performed_by, created_at)
             VALUES (:from_version, :to_version, :source, :status, :message, :backup_files_path, :backup_database_path, :performed_by, :created_at)',
            [
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'source' => $source,
                'status' => $status->value,
                'message' => $message,
                'backup_files_path' => $filesBackupPath,
                'backup_database_path' => $databaseBackupPath,
                'performed_by' => $performedByUserId,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );
    }
}
