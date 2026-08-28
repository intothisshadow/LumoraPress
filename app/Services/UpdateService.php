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
     * @param ?UpdateProgress $progress Nullable for the same reason —
     *     when absent, checkUpload()/install() simply report no live
     *     stage progress (every `$this->progress?->` call below is a
     *     no-op), rather than every existing test call site needing a
     *     progress double just to construct a service. Unlike every other
     *     nullable dependency here, callers never `reset()` through this
     *     property — see this class's own stage-reporting docblocks for
     *     why that responsibility belongs to the view instead.
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
        private readonly ?UpdateProgress $progress = null,
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

        // reset() for this operation's stage list is the view's job, not
        // this method's — see the constructor docblock's $progress note.
        // A caller that never reset() first (or has no progress reporter
        // wired at all) simply gets a no-op here, same as every other
        // $this->progress?-> call in this class.
        $this->progress?->stage('validate');

        $result = $this->validator->validateAndStage($uploadedZipPath, $installedVersion, $allowDowngrade);

        $this->progress?->stage('compatibility');

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

        $this->progress?->complete(
            $result['blocking'] === [],
            $result['blocking'] === [] ? 'Ready to install.' : 'Please resolve the issues below and try again.',
        );

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

            $this->progress?->stage('backup_files');
            $filesBackupPath = $this->backups->backupFiles($fromVersion);

            $this->progress?->stage('backup_database');
            $databaseBackupPath = $this->backups->backupDatabase($fromVersion);

            $this->progress?->stage('apply_files');
            $effectiveCorePaths = $this->applyFilesStage($stagingPath, $rootPrefix);

            $this->progress?->stage('migrate');
            $this->migrateStage();

            $this->progress?->stage('clear_cache');
            $this->clearCacheAndVerifyStage($toVersion);

            $this->progress?->stage('cleanup');
            $this->cleanupStage($effectiveCorePaths, $stagingPath);

            $this->logAttempt($fromVersion, $toVersion, $source, UpdateStatus::Success, 'Update applied successfully.', $filesBackupPath, $databaseBackupPath, $performedByUserId);
            $this->hooks->doAction('lumora_press_after_update', $fromVersion, $toVersion, UpdateStatus::Success);
            $this->progress?->complete(true, "Successfully updated from {$fromVersion} to {$toVersion}.");

            return [
                'status' => UpdateStatus::Success,
                'message' => "Successfully updated from {$fromVersion} to {$toVersion}.",
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
            ];
        } catch (Throwable $exception) {
            ['status' => $status, 'message' => $message] = $this->rollbackAndFail($exception, $filesBackupPath, $databaseBackupPath);

            $this->removeDirectory($stagingPath);
            $this->logAttempt($fromVersion, $toVersion, $source, $status, $message, $filesBackupPath, $databaseBackupPath, $performedByUserId);
            $this->hooks->doAction('lumora_press_after_update', $fromVersion, $toVersion, $status);
            $this->progress?->complete(false, $message);

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
     * "Visible Update Progress" spans multiple HTTP requests for the two
     * backup stages (see UpdateBackupService::backupDatabaseBatch()'s
     * docblock for why) — beginInstall() does the one-time setup
     * (validate the token, acquire the lock, begin maintenance mode, fire
     * the "before" hook) and persists a small JSON state file;
     * continueInstall() is then called once per request until it reports
     * done, advancing exactly one pipeline stage per call. The lock and
     * maintenance mode are deliberately NOT released here — they persist
     * (the lock via its file, maintenance mode via the `PressConfig`
     * option, both naturally surviving across requests) until
     * continueInstall() reaches a terminal state.
     *
     * install() above is unchanged and still available as a single
     * synchronous call — every existing caller/test keeps working
     * exactly as before; the admin Updates page is the only caller
     * switched to this staged pair.
     *
     * @return array{token: string, stage: string, from_version: string, to_version: string}
     */
    public function beginInstall(string $token, ?int $performedByUserId): array
    {
        $this->validateToken($token);

        $stagingPath = $this->stagingPathFor($token);
        $pending = $this->readPending($stagingPath);

        $this->acquireLock();

        $previousMaintenanceMode = null;

        try {
            $previousMaintenanceMode = $this->beginMaintenanceMode();
            $this->hooks->doAction('lumora_press_before_update', $pending['from_version'], $pending['to_version']);

            $state = [
                'token' => $token,
                'from_version' => $pending['from_version'],
                'to_version' => $pending['to_version'],
                'root_prefix' => $pending['root_prefix'],
                'source' => $pending['source'],
                'performed_by_user_id' => $performedByUserId,
                'previous_maintenance_mode' => $previousMaintenanceMode,
                'stage' => 'backup_files',
                'files_backup_path' => null,
                'database_backup_path' => null,
                'database_table_index' => 0,
                'database_row_offset' => 0,
                'effective_core_paths' => null,
            ];

            $this->writeInstallState($state);
            $this->progress?->stage('backup_files');

            return [
                'token' => $token,
                'stage' => 'backup_files',
                'from_version' => $pending['from_version'],
                'to_version' => $pending['to_version'],
            ];
        } catch (Throwable $exception) {
            $this->endMaintenanceMode($previousMaintenanceMode);
            $this->releaseLock();

            throw $exception;
        }
    }

    /**
     * Advances one stage of an install() pipeline started by
     * beginInstall() — see that method's docblock. Call repeatedly until
     * the returned `done` is true.
     *
     * @return array{
     *     done: bool,
     *     stage?: string,
     *     status?: UpdateStatus,
     *     message?: string,
     *     from_version: string,
     *     to_version: string,
     *     database_progress?: array{table_index: int, row_offset: int},
     * }
     */
    public function continueInstall(string $token): array
    {
        $this->validateToken($token);
        $state = $this->readInstallState($token);
        $stagingPath = $this->stagingPathFor($token);

        try {
            switch ($state['stage']) {
                case 'backup_files':
                    $state['files_backup_path'] = $this->backups->backupFiles($state['from_version']);
                    $state['stage'] = 'backup_database';
                    $this->progress?->stage('backup_database');

                    break;

                case 'backup_database':
                    $result = $this->backups->backupDatabaseBatch(
                        $state['from_version'],
                        $state['database_backup_path'],
                        $state['database_table_index'],
                        $state['database_row_offset'],
                    );
                    $state['database_backup_path'] = $result['path'];
                    $state['database_table_index'] = $result['tableIndex'];
                    $state['database_row_offset'] = $result['rowOffset'];

                    if ($result['done']) {
                        $state['stage'] = 'apply_files';
                        $this->progress?->stage('apply_files');
                    }

                    break;

                case 'apply_files':
                    $state['effective_core_paths'] = $this->applyFilesStage($stagingPath, $state['root_prefix']);
                    $state['stage'] = 'migrate';
                    $this->progress?->stage('migrate');

                    break;

                case 'migrate':
                    $this->migrateStage();
                    $state['stage'] = 'clear_cache';
                    $this->progress?->stage('clear_cache');

                    break;

                case 'clear_cache':
                    $this->clearCacheAndVerifyStage($state['to_version']);
                    $state['stage'] = 'cleanup';
                    $this->progress?->stage('cleanup');

                    break;

                case 'cleanup':
                    $this->cleanupStage($state['effective_core_paths'] ?? [], $stagingPath);

                    $message = "Successfully updated from {$state['from_version']} to {$state['to_version']}.";
                    $this->logAttempt($state['from_version'], $state['to_version'], $state['source'], UpdateStatus::Success, $message, $state['files_backup_path'], $state['database_backup_path'], $state['performed_by_user_id']);
                    $this->hooks->doAction('lumora_press_after_update', $state['from_version'], $state['to_version'], UpdateStatus::Success);
                    $this->progress?->complete(true, $message);
                    $this->endMaintenanceMode($state['previous_maintenance_mode']);
                    $this->releaseLock();
                    $this->deleteInstallState($token);

                    return [
                        'done' => true,
                        'status' => UpdateStatus::Success,
                        'message' => $message,
                        'from_version' => $state['from_version'],
                        'to_version' => $state['to_version'],
                    ];
            }
        } catch (Throwable $exception) {
            ['status' => $status, 'message' => $message] = $this->rollbackAndFail($exception, $state['files_backup_path'], $state['database_backup_path']);

            $this->removeDirectory($stagingPath);
            $this->logAttempt($state['from_version'], $state['to_version'], $state['source'], $status, $message, $state['files_backup_path'], $state['database_backup_path'], $state['performed_by_user_id']);
            $this->hooks->doAction('lumora_press_after_update', $state['from_version'], $state['to_version'], $status);
            $this->progress?->complete(false, $message);
            $this->endMaintenanceMode($state['previous_maintenance_mode']);
            $this->releaseLock();
            $this->deleteInstallState($token);

            return [
                'done' => true,
                'status' => $status,
                'message' => $message,
                'from_version' => $state['from_version'],
                'to_version' => $state['to_version'],
            ];
        }

        $this->writeInstallState($state);

        return [
            'done' => false,
            'stage' => $state['stage'],
            'from_version' => $state['from_version'],
            'to_version' => $state['to_version'],
            'database_progress' => [
                'table_index' => $state['database_table_index'],
                'row_offset' => $state['database_row_offset'],
            ],
        ];
    }

    /**
     * Same staged shape as beginInstall()/continueInstall(), for the
     * on-demand "Backup Now" button — two stages instead of six
     * (backup_files, backup_database), and no maintenance-mode change
     * (createBackupNow(), the synchronous equivalent this replaces on the
     * admin Updates page, never touched it either).
     *
     * @return array{token: string, stage: string}
     */
    public function beginBackupNow(): array
    {
        $this->acquireLock();

        try {
            $state = [
                'token' => bin2hex(random_bytes(16)),
                'version' => $this->installedVersion(),
                'stage' => 'backup_files',
                'files_backup_path' => null,
                'database_backup_path' => null,
                'database_table_index' => 0,
                'database_row_offset' => 0,
            ];

            $this->writeBackupNowState($state);

            return ['token' => $state['token'], 'stage' => $state['stage']];
        } catch (Throwable $exception) {
            $this->releaseLock();

            throw $exception;
        }
    }

    /**
     * @return array{done: bool, stage?: string, files_path?: string, database_path?: string}
     */
    public function continueBackupNow(string $token): array
    {
        $this->validateToken($token);
        $state = $this->readBackupNowState($token);

        try {
            if ($state['stage'] === 'backup_files') {
                $state['files_backup_path'] = $this->backups->backupFiles($state['version']);
                $state['stage'] = 'backup_database';
            } else {
                $result = $this->backups->backupDatabaseBatch(
                    $state['version'],
                    $state['database_backup_path'],
                    $state['database_table_index'],
                    $state['database_row_offset'],
                );
                $state['database_backup_path'] = $result['path'];
                $state['database_table_index'] = $result['tableIndex'];
                $state['database_row_offset'] = $result['rowOffset'];

                if ($result['done']) {
                    $this->releaseLock();
                    $this->deleteBackupNowState($token);

                    return [
                        'done' => true,
                        'files_path' => $state['files_backup_path'],
                        'database_path' => $state['database_backup_path'],
                    ];
                }
            }
        } catch (Throwable $exception) {
            $this->releaseLock();
            $this->deleteBackupNowState($token);

            throw $exception;
        }

        $this->writeBackupNowState($state);

        return ['done' => false, 'stage' => $state['stage']];
    }

    /**
     * Read-only peek at an in-progress beginInstall()/continueInstall()
     * pipeline's current stage — for the admin view's GET render between
     * continueInstall() calls. Never advances anything.
     *
     * @return array{stage: string, from_version: string, to_version: string}
     */
    public function installProgress(string $token): array
    {
        $this->validateToken($token);
        $state = $this->readInstallState($token);

        return [
            'stage' => (string) $state['stage'],
            'from_version' => (string) $state['from_version'],
            'to_version' => (string) $state['to_version'],
        ];
    }

    /**
     * Same read-only peek as installProgress(), for an in-progress
     * beginBackupNow()/continueBackupNow() run.
     *
     * @return array{stage: string}
     */
    public function backupNowProgress(string $token): array
    {
        $this->validateToken($token);
        $state = $this->readBackupNowState($token);

        return ['stage' => (string) $state['stage']];
    }

    /**
     * Shared rollback logic for install()'s and continueInstall()'s
     * catch blocks — restores whatever backups exist so far and reports
     * the resulting status/message, but does not itself touch the lock,
     * maintenance mode, staging directory, logging, or hooks, since the
     * two callers close those out slightly differently (install() via
     * its own `finally`, continueInstall() explicitly per terminal path).
     *
     * @return array{status: UpdateStatus, message: string}
     */
    private function rollbackAndFail(Throwable $exception, ?string $filesBackupPath, ?string $databaseBackupPath): array
    {
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

        return ['status' => $status, 'message' => $message];
    }

    /**
     * @return array<int, string> the effective core paths just overlaid —
     *     cleanupStage() needs the same set to know what's now obsolete.
     */
    private function applyFilesStage(string $stagingPath, string $rootPrefix): array
    {
        $effectiveRoot = rtrim($stagingPath . '/' . $rootPrefix, '/');
        $effectiveCorePaths = $this->resolveEffectiveCorePaths($effectiveRoot);

        foreach ($effectiveCorePaths as $corePath) {
            $this->overlayPath($effectiveRoot . '/' . $corePath, rtrim($this->installRoot, '/') . '/' . $corePath);
        }

        return $effectiveCorePaths;
    }

    private function migrateStage(): void
    {
        (new Migrator($this->database, $this->migrationsPath, $this->tablePrefix))->migrate();
    }

    private function clearCacheAndVerifyStage(string $toVersion): void
    {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        $this->clearCache();

        $installedVersion = $this->installedVersion();

        if ($installedVersion !== $toVersion) {
            throw new RuntimeException('The installed version did not match the update package after applying it.');
        }
    }

    /**
     * @param array<int, string> $effectiveCorePaths
     */
    private function cleanupStage(array $effectiveCorePaths, string $stagingPath): void
    {
        /*
         * install/ is one of $corePaths, so a release package that ships
         * it just re-extracted it onto the installation above —
         * resurrecting it even on a site where the administrator had
         * already deleted it after their original install. Best-effort
         * only, same as the installer's own cleanup: a locked-down host
         * that won't let PHP delete its own files is an unremarkable
         * outcome here too, and the Dashboard's leftover-install-
         * directory alert (LP-030) still catches it either way.
         */
        (new InstallerCleanup())->remove(rtrim($this->installRoot, '/') . '/install');

        $this->removeObsoleteCorePaths($effectiveCorePaths);
        $this->checksums?->write($this->checksums->computeForCorePaths($effectiveCorePaths));

        $this->removeDirectory($stagingPath);
    }

    private function installStatePath(string $token): string
    {
        return dirname($this->lockFilePath) . '/install-state-' . $token . '.json';
    }

    private function backupNowStatePath(string $token): string
    {
        return dirname($this->lockFilePath) . '/backup-now-state-' . $token . '.json';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeInstallState(array $state): void
    {
        $this->writeStateFile($this->installStatePath((string) $state['token']), $state);
    }

    /**
     * @return array<string, mixed>
     */
    private function readInstallState(string $token): array
    {
        return $this->readStateFile($this->installStatePath($token), 'No in-progress update was found for this token. Please start the update again.');
    }

    private function deleteInstallState(string $token): void
    {
        $this->deleteStateFile($this->installStatePath($token));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeBackupNowState(array $state): void
    {
        $this->writeStateFile($this->backupNowStatePath((string) $state['token']), $state);
    }

    /**
     * @return array<string, mixed>
     */
    private function readBackupNowState(string $token): array
    {
        return $this->readStateFile($this->backupNowStatePath($token), 'No in-progress backup was found for this token. Please start the backup again.');
    }

    private function deleteBackupNowState(string $token): void
    {
        $this->deleteStateFile($this->backupNowStatePath($token));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeStateFile(string $path, array $state): void
    {
        $parent = dirname($path);

        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to prepare the update state directory.');
        }

        // Atomic write (tmp file + rename()), same as UpdateProgress's
        // own persistence — a request that crashes mid-write must never
        // leave a torn, half-written state file for the next request to
        // resume from.
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR));
        rename($tmp, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readStateFile(string $path, string $missingMessage): array
    {
        if (!is_file($path)) {
            throw new RuntimeException($missingMessage);
        }

        $contents = file_get_contents($path);
        $state = $contents !== false ? json_decode($contents, true) : null;

        if (!is_array($state)) {
            throw new RuntimeException('The saved progress record is corrupt. Please start again.');
        }

        return $state;
    }

    private function deleteStateFile(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
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
     * install/restore but aren't part of $corePaths (the *effective* set
     * just installed — see resolveEffectiveCorePaths()) — the only case a
     * corePath entry can go stale, since it's a value only ever set by
     * this codebase's own core-paths.php, never derived from an uploaded
     * release ZIP or touched by an admin, a plugin, or any file outside
     * the fixed corePaths list. Deliberately compares against the
     * *configured* corePaths, not "what this specific release happened to
     * contain" — see UpdateManifest's own docblock for why.
     *
     * A missing/never-written manifest reads back as [], so the very
     * first run after this exists is always a safe no-op: nothing is
     * removed, tracking simply begins from that point on.
     *
     * @param array<int, string> $corePaths
     */
    private function removeObsoleteCorePaths(array $corePaths): void
    {
        $obsolete = array_diff($this->manifest->read(), $corePaths);

        foreach ($obsolete as $relativePath) {
            $this->deletePath(rtrim($this->installRoot, '/') . '/' . $relativePath);
        }

        $this->manifest->write($corePaths);
    }

    /**
     * The corePaths list this install() run should actually overlay: the
     * currently-running (old) code's own $this->corePaths, unioned with
     * whatever the *staged, not-yet-installed* package's own
     * core-paths.php declares — see that file's docblock for the full
     * "chicken-and-egg" problem this closes. A package built before
     * core-paths.php existed (or one that's otherwise missing/malformed)
     * just falls back to $this->corePaths alone, exactly today's
     * pre-fix behavior — never an error, since a missing declaration here
     * is not itself a reason to fail an update.
     *
     * @return array<int, string>
     */
    private function resolveEffectiveCorePaths(string $effectiveRoot): array
    {
        $declaredPathsFile = $effectiveRoot . '/core-paths.php';

        if (!is_file($declaredPathsFile)) {
            return $this->corePaths;
        }

        $declared = require $declaredPathsFile;

        if (!is_array($declared)) {
            return $this->corePaths;
        }

        $declared = array_values(array_filter($declared, 'is_string'));

        return array_values(array_unique([...$this->corePaths, ...$declared]));
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
