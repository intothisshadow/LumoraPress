<?php

/**
 * Orchestrates the manual ZIP update pipeline: validate, stage, back up, and apply an uploaded update.
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

use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Database\Migrator;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\InstallerCleanup;
use LumoraPress\Core\PressConfig;
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

    private const MIN_MYSQL_VERSION = '5.6.4';

    private const MIN_MARIADB_VERSION = '10.0.5';

    /** @var array<int, string> */
    private const REQUIRED_CONFIG_KEYS = [
        'db_host', 'db_name', 'db_user', 'db_password', 'db_charset', 'table_prefix', 'secret_key',
    ];

    private const MIN_BACKUP_FREE_BYTES = 50 * 1024 * 1024;

    /**
     * "Warn about active users" — an admin is considered still active if
     * their last authenticated request (see UserService::touchLastActive(),
     * called once per admin page load) was within this window.
     */
    private const ACTIVE_USER_WINDOW_SECONDS = 300;

    /**
     * "Detect modified core files" — a wall of dozens of filenames would
     * bury the actionable part of the warning, so the list is capped and
     * the remainder summarized as a count.
     */
    private const MAX_MODIFIED_FILES_SHOWN = 10;

    /**
     * @param array<int, string> $corePaths Paths (relative to $installRoot) the
     *     updater overlays from the staged package onto the installation.
     * @param ?string $configFilePath config/config.php's absolute path —
     *     nullable so every existing `new UpdateService(...)` call site in
     *     the test suite keeps compiling unchanged (the same optional-DI
     *     pattern PostService's docblock uses for $hooks); the
     *     "Configuration compatibility" check below is simply skipped
     *     when this is null. include/bootstrap.php's real instance always
     *     passes one.
     * @param ?PressConfig $config Nullable for the same reason as
     *     $configFilePath — when absent, "Maintenance mode during update"
     *     is simply skipped (install() behaves exactly as it did before
     *     that feature existed) rather than every test call site needing
     *     an in-memory PressConfig just to construct a service.
     * @param ?UserService $users Nullable for the same reason — when
     *     absent, "Warn about active users" always reports no problems.
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
        private readonly UpdateManifest $manifest,
        private readonly ?string $configFilePath = null,
        private readonly ?PressConfig $config = null,
        private readonly ?UserService $users = null,
        private readonly ?UpdateChecksumManifest $checksums = null,
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
    public function checkUpload(string $uploadedZipPath, bool $allowDowngrade, string $source = 'manual', ?int $currentUserId = null): array
    {
        $installedVersion = $this->installedVersion();

        $result = $this->validator->validateAndStage($uploadedZipPath, $installedVersion, $allowDowngrade);

        // Compatibility Checks: PHP version/extensions/writability are
        // already validated above (they depend on the uploaded package
        // itself, so they live in UpdatePackageValidator); these three
        // depend only on this server's own current state, not anything in
        // the package, so they run here regardless of what validateAndStage()
        // found.
        array_push($result['blocking'], ...$this->databaseVersionProblems());
        array_push($result['blocking'], ...$this->configCompatibilityProblems());
        array_push($result['blocking'], ...$this->backupLocationProblems());

        // Neither of these is a reason to block the update — an
        // administrator may deliberately want to proceed despite another
        // active session, or despite overwriting a file they hand-edited —
        // so both are warnings, not blocking problems.
        array_push($result['warnings'], ...$this->activeUserProblems($currentUserId));
        array_push($result['warnings'], ...$this->modifiedCoreFileProblems());

        if ($result['blocking'] === []) {
            $pendingPath = rtrim($result['staging_path'], '/') . '/' . self::PENDING_FILE;

            file_put_contents($pendingPath, json_encode([
                'from_version' => $result['from_version'],
                'to_version' => $result['to_version'],
                'root_prefix' => $result['root_prefix'],
                'source' => $source,
                'created_at' => time(),
            ], JSON_THROW_ON_ERROR));
        } else {
            // A check added above (rather than validateAndStage() itself)
            // may be the only reason blocking is non-empty, in which case
            // the package is still sitting in a freshly extracted staging
            // directory — validateAndStage() only cleans up after its own
            // blocking reasons, never ours.
            $this->removeDirectory($result['staging_path']);
        }

        unset($result['staging_path']);

        return $result;
    }

    /**
     * "Database version" Compatibility Check — the connected MySQL/MariaDB
     * server's own version, not to be confused with migrationStatus()'s
     * "how many of this app's own migrations have run" (that's an
     * expected, normal, often-not-yet-complete state right up until
     * install() actually runs them as part of applying the update itself,
     * not a pre-update blocker). Mirrors README.md's stated minimum
     * (MySQL 5.6.4+ / MariaDB 10.0.5+ — InnoDB FULLTEXT support, used by
     * search) the same way UpdatePackageValidator checks the package's own
     * requires_php against PHP_VERSION.
     *
     * @return array<int, string>
     */
    public function databaseVersionProblems(): array
    {
        try {
            $rawVersion = (string) $this->database->fetchColumn('SELECT VERSION()');
        } catch (Throwable) {
            // Most likely a non-MySQL-protocol connection (e.g. a unit
            // test's SQLite fixture) — nothing meaningful to check.
            return [];
        }

        if ($rawVersion === '' || self::isDatabaseVersionSupported($rawVersion)) {
            return [];
        }

        return [sprintf(
            'The connected database server (%s) is older than the minimum supported version (MySQL %s+ or MariaDB %s+).',
            $rawVersion,
            self::MIN_MYSQL_VERSION,
            self::MIN_MARIADB_VERSION,
        )];
    }

    /**
     * Pure version-string logic split out from databaseVersionProblems()
     * so it's unit-testable without a real database connection — the same
     * "extract the comparison, keep the I/O thin" split
     * RequirementsCheck's constructor-injectable $extensionLoaded uses for
     * a different reason (testability without root/disabled extensions),
     * applied here for testability without a real MySQL/MariaDB server.
     */
    public static function isDatabaseVersionSupported(string $rawVersion): bool
    {
        if (preg_match('/^\d+\.\d+\.\d+/', $rawVersion, $matches) !== 1) {
            // Doesn't even look like a version string — fail open rather
            // than block an update over a server that reports its version
            // in an unrecognised format.
            return true;
        }

        $numericVersion = $matches[0];
        $isMariaDb = stripos($rawVersion, 'mariadb') !== false;
        $minimum = $isMariaDb ? self::MIN_MARIADB_VERSION : self::MIN_MYSQL_VERSION;

        return version_compare($numericVersion, $minimum, '>=');
    }

    /**
     * "Configuration compatibility" Compatibility Check — config/config.php
     * survives an update untouched (it's never one of $corePaths), so this
     * confirms it's actually healthy *before* an update runs, rather than
     * risking a config-related failure during/after the update being
     * misattributed to the update itself. Returns [] (nothing to check)
     * when $configFilePath wasn't provided — see the constructor docblock.
     *
     * @return array<int, string>
     */
    public function configCompatibilityProblems(): array
    {
        if ($this->configFilePath === null) {
            return [];
        }

        if (!is_file($this->configFilePath)) {
            return ["The configuration file ({$this->configFilePath}) could not be found."];
        }

        $data = require $this->configFilePath;

        if (!is_array($data)) {
            return ["The configuration file ({$this->configFilePath}) must return an array."];
        }

        $problems = [];

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                $problems[] = "The configuration file is missing the required \"{$key}\" setting.";
            }
        }

        foreach (['secret_key', 'table_prefix'] as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) === '') {
                $problems[] = "The configuration file's \"{$key}\" setting is empty.";
            }
        }

        return $problems;
    }

    /**
     * "Verify backup location" — confirms the configured backup
     * destination is writable with a sane minimum of free space *before*
     * an update proceeds, rather than only discovering a stuck permission
     * or full disk when backupFiles()/backupDatabase() itself throws deep
     * inside install(). The directory itself is created lazily on first
     * backup (see UpdateBackupService::ensureBackupsDirectory()), so a
     * fresh install checks the nearest existing ancestor instead —
     * mirrors systemStatus()'s "Temporary directory" check, one level up.
     *
     * @return array<int, string>
     */
    public function backupLocationProblems(): array
    {
        $backupsPath = $this->backups->backupsPath();
        $existingAncestor = is_dir($backupsPath) ? $backupsPath : $this->nearestExistingDirectory(dirname($backupsPath));

        if (!is_writable($existingAncestor)) {
            return ["The backup directory ({$backupsPath}) is not writable by the web server."];
        }

        $freeSpace = @disk_free_space($existingAncestor);

        if ($freeSpace !== false && $freeSpace < self::MIN_BACKUP_FREE_BYTES) {
            return [sprintf(
                'There is not enough free disk space at the backup location (%s) — only %s available.',
                $backupsPath,
                number_format($freeSpace / 1024 / 1024, 1) . ' MB',
            )];
        }

        return [];
    }

    /**
     * "Warn about active users" — surfaces a non-blocking heads-up when
     * another administrator/editor session has been active recently, since
     * an update briefly puts the site into maintenance mode (see
     * beginMaintenanceMode()) and overwrites core files out from under
     * anyone mid-edit. Returns [] when $currentUserId or $users wasn't
     * provided (an on-demand backup or a test double, e.g.).
     *
     * @return array<int, string>
     */
    public function activeUserProblems(?int $currentUserId): array
    {
        if ($this->users === null || $currentUserId === null) {
            return [];
        }

        $others = $this->users->activeUsernamesExcluding($currentUserId, self::ACTIVE_USER_WINDOW_SECONDS);

        if ($others === []) {
            return [];
        }

        return [sprintf(
            'Other users are currently active in the admin area (%s) — consider waiting until they are done, since this update will briefly enable maintenance mode and replace core files.',
            implode(', ', $others),
        )];
    }

    /**
     * "Detect modified core files" — compares the live filesystem against
     * the checksums recorded right after the last successful install()
     * (see UpdateChecksumManifest's docblock). A mismatch means a core file
     * was hand-edited (or deleted) since then; this update's overlay step
     * will silently replace or remove it, so it's worth a heads-up before
     * that happens. Returns [] when nothing was recorded yet (a fresh
     * install predating this feature, or $checksums wasn't provided) —
     * fail-safe, never a false "everything is modified" alarm.
     *
     * @return array<int, string>
     */
    public function modifiedCoreFileProblems(): array
    {
        if ($this->checksums === null) {
            return [];
        }

        $expected = $this->checksums->read();

        if ($expected === []) {
            return [];
        }

        $modified = [];

        foreach ($expected as $relativePath => $expectedHash) {
            $absolute = rtrim($this->installRoot, '/') . '/' . $relativePath;
            $actualHash = is_file($absolute) ? hash_file('sha256', $absolute) : false;

            if ($actualHash !== $expectedHash) {
                $modified[] = $relativePath;
            }
        }

        if ($modified === []) {
            return [];
        }

        sort($modified);
        $shown = array_slice($modified, 0, self::MAX_MODIFIED_FILES_SHOWN);
        $remaining = count($modified) - count($shown);

        return [sprintf(
            'The following core file(s) appear to have been modified since they were installed and will be overwritten by this update: %s%s.',
            implode(', ', $shown),
            $remaining > 0 ? sprintf(' (and %d more)', $remaining) : '',
        )];
    }

    private function nearestExistingDirectory(string $path): string
    {
        while (!is_dir($path)) {
            $parent = dirname($path);

            if ($parent === $path) {
                break;
            }

            $path = $parent;
        }

        return $path;
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
        $previousMaintenanceMode = $this->beginMaintenanceMode();

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

            $this->removeObsoleteCorePaths();
            $this->checksums?->write($this->checksums->computeForCorePaths($this->corePaths));

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
            $this->endMaintenanceMode($previousMaintenanceMode);
            $this->releaseLock();
        }
    }

    /**
     * "Maintenance mode during update" — auto-enables the existing
     * MaintenanceGate for the duration of install() (see its own docblock:
     * /admin/* stays exempt regardless, so an administrator can always
     * keep working through the update), and returns whatever the option
     * held before so endMaintenanceMode() can restore it exactly — an
     * administrator who had already turned maintenance mode on deliberately
     * must find it still on afterward, not toggled off. Returns null (and
     * does nothing) when $config wasn't provided.
     */
    private function beginMaintenanceMode(): ?string
    {
        if ($this->config === null) {
            return null;
        }

        $previous = (string) $this->config->option('maintenance_mode_enabled', '0');
        $this->config->setOption('maintenance_mode_enabled', '1');

        return $previous;
    }

    private function endMaintenanceMode(?string $previous): void
    {
        if ($this->config === null || $previous === null) {
            return;
        }

        $this->config->setOption('maintenance_mode_enabled', $previous);
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

    /**
     * Deletes a backup pair from disk (no lock needed — unlike restore,
     * this touches nothing the running application depends on).
     */
    public function deleteBackup(?string $filesFilename, ?string $databaseFilename): void
    {
        $this->backups->deleteBackup($filesFilename, $databaseFilename);
    }

    /**
     * On-demand backup, independent of the update pipeline — for an
     * administrator who wants a restore point before touching anything
     * else. Takes the same lock install()/restoreBackup() do, since a
     * database dump mid-migration would be inconsistent.
     *
     * @return array{files_path: string, database_path: string}
     */
    public function createBackupNow(): array
    {
        $this->acquireLock();

        try {
            $version = $this->installedVersion();

            return [
                'files_path' => $this->backups->backupFiles($version),
                'database_path' => $this->backups->backupDatabase($version),
            ];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @return array{applied: int, total: int, up_to_date: bool}
     */
    public function migrationStatus(): array
    {
        return (new Migrator($this->database, $this->migrationsPath, $this->tablePrefix))->status();
    }

    /**
     * Runtime environment checks shown on the Updates page — distinct
     * from UpdatePackageValidator's compatibility checks, which run
     * against a specific uploaded/downloaded package rather than the
     * server's general readiness to apply *some* future update.
     *
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    public function systemStatus(): array
    {
        $diskFree = @disk_free_space($this->installRoot);
        $hasZip = class_exists('ZipArchive');
        $hasCurl = function_exists('curl_init');
        $installRootWritable = is_writable($this->installRoot);
        $stagingParent = is_dir($this->stagingRoot) ? $this->stagingRoot : dirname($this->stagingRoot);
        $stagingWritable = is_writable($stagingParent);
        $diskOk = $diskFree === false || $diskFree > 100 * 1024 * 1024;

        return [
            [
                'label' => 'PHP version',
                'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'detail' => 'Running PHP ' . PHP_VERSION . ' (minimum 8.2.0).',
            ],
            [
                'label' => 'ZIP extension',
                'ok' => $hasZip,
                'detail' => $hasZip ? 'The PHP zip extension is loaded.' : 'The PHP zip extension is required but not loaded.',
            ],
            [
                'label' => 'cURL availability',
                'ok' => $hasCurl,
                'detail' => $hasCurl
                    ? 'The cURL extension is loaded.'
                    : 'cURL is not available — GitHub requests fall back to allow_url_fopen if enabled.',
            ],
            [
                'label' => 'File permissions',
                'ok' => $installRootWritable,
                'detail' => $installRootWritable
                    ? 'The installation directory is writable.'
                    : 'The installation directory is not writable by the web server.',
            ],
            [
                'label' => 'Available disk space',
                'ok' => $diskOk,
                'detail' => $diskFree !== false
                    ? number_format($diskFree / 1024 / 1024 / 1024, 2) . ' GB free.'
                    : 'Unable to determine free disk space.',
            ],
            [
                'label' => 'Temporary directory',
                'ok' => $stagingWritable,
                'detail' => $stagingWritable
                    ? 'The update staging directory is writable.'
                    : 'The update staging directory is not writable.',
            ],
        ];
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

    /**
     * Removes top-level `corePaths` entries that were part of a previous
     * install/restore but aren't part of the current `$corePaths`
     * configuration — the only case a corePath entry can go stale, since
     * $corePaths is a value only ever set by this codebase's own
     * bootstrap.php, never derived from an uploaded release ZIP or
     * touched by an admin, a plugin, or any file outside the fixed
     * corePaths list. Deliberately compares against the *configured*
     * corePaths, not "what this specific release happened to contain" —
     * see UpdateManifest's own docblock for why.
     *
     * A missing/never-written manifest reads back as [], so the very
     * first run after this exists is always a safe no-op: nothing is
     * removed, tracking simply begins from that point on.
     */
    private function removeObsoleteCorePaths(): void
    {
        $obsolete = array_diff($this->manifest->read(), $this->corePaths);

        foreach ($obsolete as $relativePath) {
            $this->deletePath(rtrim($this->installRoot, '/') . '/' . $relativePath);
        }

        $this->manifest->write($this->corePaths);
    }

    private function deletePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path) && !is_link($path)) {
            $this->removeDirectory($path);
        } else {
            unlink($path);
        }
    }

    private function overlayPath(string $source, string $target): void
    {
        if (!file_exists($source)) {
            return;
        }

        $this->deletePath($target);

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
