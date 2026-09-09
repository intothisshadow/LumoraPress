<?php

/**
 * Backs up and restores the core application files and database before/after a manual update.
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
use RuntimeException;
use ZipArchive;

/**
 * Backs up (and restores) the core application files and the database
 * before/after a manual update, using only ZipArchive and PDO — no
 * mysqldump or shell_exec, since either may be unavailable on shared
 * hosting.
 *
 * Restore is purely additive: restoreFiles() only ever extracts a backup
 * archive over the installation, re-adding/overwriting files — it never
 * deletes anything first or after. Only UpdateService's install() ever
 * removes files (see its removeObsoleteCorePaths()).
 */
final class UpdateBackupService
{
    private const STATEMENT_MARKER = "\n-- @@LUMORA_UPDATE_STMT_END@@\n";

    private const ROW_BATCH_SIZE = 500;

    /** Rows written per backupDatabaseBatch() call — sized for one HTTP request rather than one query. */
    private const BATCH_ROW_COUNT = 5000;

    /**
     * @param array<int, string> $corePaths Paths (relative to $installRoot) that
     *     make up the core application and are safe to back up/restore.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly string $installRoot,
        private readonly string $backupsPath,
        private readonly array $corePaths,
        private readonly UpdateManifest $manifest,
        private readonly ?UpdateChecksumManifest $checksums = null,
        /**
         * Overrides gzip auto-detection. Null (the only value real
         * callers pass) auto-detects via function_exists('gzopen'); tests
         * pass an explicit bool to exercise the plain-.sql fallback path
         * deterministically regardless of the host's zlib extension.
         */
        private readonly ?bool $useGzip = null,
    ) {
    }

    /**
     * Exposes the configured backup destination for UpdateService's
     * pre-update writable/free-space check.
     */
    public function backupsPath(): string
    {
        return $this->backupsPath;
    }

    public function backupFiles(string $version): string
    {
        // corePaths covers only the app's own code (never user data), so this is bounded by app size, not site content — costs nothing to have anyway.
        set_time_limit(0);

        $this->ensureBackupsDirectory();

        $path = rtrim($this->backupsPath, '/') . '/files-' . $this->safeVersion($version) . '-' . date('Ymd-His') . '.zip';

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the file backup archive.');
        }

        foreach ($this->corePaths as $corePath) {
            $absolute = rtrim($this->installRoot, '/') . '/' . $corePath;

            if (is_file($absolute)) {
                $zip->addFile($absolute, $corePath);
            } elseif (is_dir($absolute)) {
                $this->addDirectoryToZip($zip, $absolute, $corePath);
            }
        }

        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize the file backup archive.');
        }

        $this->verifyFilesBackup($path);
        $this->pruneOldBackups('files-*.zip');

        return $path;
    }

    /**
     * Reopens a just-created archive with ZipArchive::CHECKCONS to
     * validate its central directory, rather than trusting that a
     * successful write() implies a readable archive. Deliberately
     * doesn't re-read every entry's bytes — CHECKCONS already catches
     * the failure mode that matters (a truncated write or full disk).
     */
    private function verifyFilesBackup(string $path): void
    {
        $verify = new ZipArchive();

        if ($verify->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The file backup archive failed integrity verification after being created.');
        }

        $verify->close();
    }

    /**
     * A non-empty dump must end with the statement marker every
     * completed fwrite() appends, so a dump truncated by a crash or full
     * disk is caught immediately rather than discovered during a
     * restore. An empty dump (no prefixed tables yet) is not flagged.
     */
    private function verifyDatabaseBackup(string $path): void
    {
        $markerLength = strlen(self::STATEMENT_MARKER);

        // gzseek() can't seek from SEEK_END on a compressed stream, so a gzip
        // dump is decompressed in full to check its tail rather than seeked.
        if (str_ends_with($path, '.gz')) {
            // An empty dump's gzip container still carries header/trailer
            // overhead, so "empty" is judged by decompressed length here,
            // not the raw file size the plain-.sql branch below uses.
            $contents = $this->readGzipContents($path);

            if ($contents === '') {
                return;
            }

            $tail = substr($contents, -$markerLength);
        } else {
            $size = @filesize($path);

            if ($size === false || $size === 0) {
                return;
            }

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('The database backup file could not be reopened for verification.');
            }

            $tail = fseek($handle, -$markerLength, SEEK_END) === 0 ? fread($handle, $markerLength) : false;
            fclose($handle);
        }

        if ($tail !== self::STATEMENT_MARKER) {
            throw new RuntimeException('The database backup file appears to be truncated or corrupted.');
        }
    }

    private function readGzipContents(string $path): string
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the compressed database backup file.');
        }

        $contents = '';

        while (!gzeof($handle)) {
            $contents .= gzread($handle, 8192);
        }

        gzclose($handle);

        return $contents;
    }

    /**
     * Lists every backup pair on disk (a files-*.zip and/or its matching
     * db-*.sql), newest first, for the admin Backups panel. Pairs are
     * matched by their shared "{version}-{Ymd-His}" filename suffix, not
     * by reading file contents.
     *
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
        $entries = [];

        foreach (glob(rtrim($this->backupsPath, '/') . '/files-*.zip') ?: [] as $path) {
            $key = $this->backupKey($path, 'files-', '.zip');

            if ($key === null) {
                continue;
            }

            $entries[$key]['version'] ??= $this->versionFromKey($key);
            $entries[$key]['created_at'] ??= filemtime($path) ?: 0;
            $entries[$key]['files_filename'] = basename($path);
            $entries[$key]['files_size'] = filesize($path) ?: 0;
        }

        foreach (glob(rtrim($this->backupsPath, '/') . '/db-*.sql.gz') ?: [] as $path) {
            $key = $this->backupKey($path, 'db-', '.sql.gz');

            if ($key === null) {
                continue;
            }

            $entries[$key]['version'] ??= $this->versionFromKey($key);
            $entries[$key]['created_at'] ??= filemtime($path) ?: 0;
            $entries[$key]['database_filename'] = basename($path);
            $entries[$key]['database_size'] = filesize($path) ?: 0;
        }

        // glob('*.sql') only matches names literally ending ".sql", so a
        // "*.sql.gz" file from the loop above is never double-counted here.
        foreach (glob(rtrim($this->backupsPath, '/') . '/db-*.sql') ?: [] as $path) {
            $key = $this->backupKey($path, 'db-', '.sql');

            if ($key === null) {
                continue;
            }

            $entries[$key]['version'] ??= $this->versionFromKey($key);
            $entries[$key]['created_at'] ??= filemtime($path) ?: 0;
            $entries[$key]['database_filename'] ??= basename($path);
            $entries[$key]['database_size'] ??= filesize($path) ?: 0;
        }

        $result = [];

        foreach ($entries as $entry) {
            $result[] = [
                'version' => $entry['version'],
                'created_at' => $entry['created_at'],
                'files_filename' => $entry['files_filename'] ?? null,
                'files_size' => $entry['files_size'] ?? null,
                'database_filename' => $entry['database_filename'] ?? null,
                'database_size' => $entry['database_size'] ?? null,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $result;
    }

    /**
     * Restores a files backup identified only by its basename (never a
     * full path an admin form could tamper with into an arbitrary
     * filesystem location) — resolved against $backupsPath and validated
     * to actually live there before restoreFiles() ever sees it.
     */
    public function restoreFilesByFilename(string $filename): void
    {
        $this->restoreFiles($this->validatedBackupPath($filename, 'files-', ['.zip']));
    }

    /**
     * Resolves a backup filename (files- or db- half of a pair) to its
     * on-disk path for download, with the same filename-only safety as
     * the restore-by-filename methods.
     */
    public function backupFilePath(string $filename): string
    {
        $basename = basename($filename);

        if (str_starts_with($basename, 'files-')) {
            return $this->validatedBackupPath($filename, 'files-', ['.zip']);
        }

        if (str_starts_with($basename, 'db-')) {
            return $this->validatedBackupPath($filename, 'db-', ['.sql.gz', '.sql']);
        }

        throw new RuntimeException('Invalid backup filename.');
    }

    /**
     * Same filename-only safety as restoreFilesByFilename(), for the
     * database backup half of a pair.
     */
    public function restoreDatabaseByFilename(string $filename): void
    {
        $this->restoreDatabase($this->validatedBackupPath($filename, 'db-', ['.sql.gz', '.sql']));
    }

    /**
     * Deletes a backup pair (or just its files half, if $databaseFilename
     * is null) from disk, with the same filename-only safety as the
     * restore-by-filename methods.
     */
    public function deleteBackup(?string $filesFilename, ?string $databaseFilename): void
    {
        if ($filesFilename !== null) {
            unlink($this->validatedBackupPath($filesFilename, 'files-', ['.zip']));
        }

        if ($databaseFilename !== null) {
            unlink($this->validatedBackupPath($databaseFilename, 'db-', ['.sql.gz', '.sql']));
        }
    }

    public function restoreFiles(string $backupZipPath): void
    {
        if (!is_file($backupZipPath)) {
            throw new RuntimeException('Backup archive not found for file restore.');
        }

        $zip = new ZipArchive();

        if ($zip->open($backupZipPath) !== true) {
            throw new RuntimeException('Unable to open the backup archive for restore.');
        }

        if (!$zip->extractTo($this->installRoot)) {
            $zip->close();

            throw new RuntimeException('Unable to extract the backup archive during restore.');
        }

        $zip->close();

        // Restore never deletes anything, so rewriting the manifest here can only cause under-removal later, never over-removal.
        $this->manifest->write($this->corePaths);

        // An arbitrary (possibly much older) backup can be restored, so the checksum baseline can no longer be trusted — clear it rather than recompute.
        $this->checksums?->clear();
    }

    /**
     * Dumps the whole database in one call — a thin wrapper looping
     * backupDatabaseBatch() to completion, kept for every existing
     * caller (createBackupNow()'s synchronous fallback callers, tests)
     * that doesn't need the multi-request staging itself.
     */
    public function backupDatabase(string $version): string
    {
        $path = null;
        $tableIndex = 0;
        $rowOffset = 0;

        do {
            $result = $this->backupDatabaseBatch($version, $path, $tableIndex, $rowOffset);
            $path = $result['path'];
            $tableIndex = $result['tableIndex'];
            $rowOffset = $result['rowOffset'];
        } while (!$result['done']);

        return $path;
    }

    /**
     * Writes up to BATCH_ROW_COUNT rows of the database dump per call, sized for one HTTP
     * request since a large synchronous dump can be killed by the webserver/proxy layer.
     * Append-only, so (tableIndex, rowOffset) alone is enough to resume. $path is null on the
     * first call; subsequent calls must pass back the exact path/tableIndex/rowOffset last
     * returned.
     *
     * @return array{path: string, tableIndex: int, rowOffset: int, done: bool}
     */
    public function backupDatabaseBatch(
        string $version,
        ?string $path,
        int $tableIndex,
        int $rowOffset,
        int $batchSize = self::BATCH_ROW_COUNT,
    ): array {
        // A safety margin — each batch is already capped to finish well within a shared host's execution-time limit.
        set_time_limit(0);

        $isFirstCall = $path === null;

        // A dump's extension (and so its compression) is fixed by its first
        // call; every later call reopens the same path, so it reads the
        // extension back rather than re-deciding.
        $useGzip = $isFirstCall
            ? ($this->useGzip ?? function_exists('gzopen'))
            : str_ends_with($path, '.gz');

        if ($isFirstCall) {
            $this->ensureBackupsDirectory();
            $extension = $useGzip ? '.sql.gz' : '.sql';
            $path = rtrim($this->backupsPath, '/') . '/db-' . $this->safeVersion($version) . '-' . date('Ymd-His') . $extension;
        }

        $tables = $this->prefixedTables();
        $mode = $isFirstCall ? 'wb' : 'ab';
        $handle = $useGzip ? gzopen($path, $mode) : fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException('Unable to create the database backup file.');
        }

        try {
            $remaining = $batchSize;

            while ($remaining > 0 && $tableIndex < count($tables)) {
                $table = $tables[$tableIndex];

                if ($rowOffset === 0 && !$this->writeCreateStatements($handle, $table)) {
                    // SHOW CREATE TABLE came back empty (table vanished mid-backup) — skip its data entirely.
                    $tableIndex++;

                    continue;
                }

                $result = $this->writeTableDataBatch($handle, $table, $rowOffset, $remaining);
                $remaining -= $result['written'];
                $rowOffset += $result['written'];

                if ($result['exhausted']) {
                    $tableIndex++;
                    $rowOffset = 0;
                }
            }

            $done = $tableIndex >= count($tables);
        } finally {
            fclose($handle);
        }

        if ($done) {
            $this->verifyDatabaseBackup($path);
            $this->pruneOldBackups($useGzip ? 'db-*.sql.gz' : 'db-*.sql');
        }

        return [
            'path' => $path,
            'tableIndex' => $tableIndex,
            'rowOffset' => $rowOffset,
            'done' => $done,
        ];
    }

    public function restoreDatabase(string $backupSqlPath): void
    {
        if (!is_file($backupSqlPath)) {
            throw new RuntimeException('Backup file not found for database restore.');
        }

        $contents = str_ends_with($backupSqlPath, '.gz')
            ? $this->readGzipContents($backupSqlPath)
            : file_get_contents($backupSqlPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the database backup file.');
        }

        $statements = array_filter(
            array_map('trim', explode(self::STATEMENT_MARKER, $contents)),
            static fn (string $statement): bool => $statement !== '',
        );

        $pdo = $this->database->pdo();

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    public function pruneOldBackups(string $pattern, int $keep = 5): void
    {
        $files = glob(rtrim($this->backupsPath, '/') . '/' . $pattern) ?: [];

        if (count($files) <= $keep) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $keep) as $stale) {
            unlink($stale);
        }
    }

    /**
     * @return array<int, string>
     */
    private function prefixedTables(): array
    {
        $rows = $this->database->fetchAll("SHOW TABLES LIKE '" . str_replace(['%', '_'], ['\\%', '\\_'], $this->tablePrefix) . "%'");

        $tables = [];

        foreach ($rows as $row) {
            $tables[] = (string) reset($row);
        }

        return $tables;
    }

    /**
     * Writes a table's DROP/CREATE pair, or returns false (writing
     * nothing) if the table no longer exists — the caller then skips its
     * data entirely, the same behavior the original single-shot
     * backupDatabase() had for this edge case.
     *
     * @param resource $handle
     */
    private function writeCreateStatements($handle, string $table): bool
    {
        $createRow = $this->database->fetchOne("SHOW CREATE TABLE `{$table}`");
        $createSql = (string) ($createRow['Create Table'] ?? '');

        if ($createSql === '') {
            return false;
        }

        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;" . self::STATEMENT_MARKER);
        fwrite($handle, $createSql . self::STATEMENT_MARKER);

        return true;
    }

    /**
     * Writes up to $maxRows of $table's data from $offset, in internal chunks of
     * ROW_BATCH_SIZE, bounded by $maxRows so a caller can cap work per request.
     *
     * @param resource $handle
     * @return array{written: int, exhausted: bool} exhausted is false when $maxRows was hit
     *     first and more of this table remains for a later call.
     */
    private function writeTableDataBatch($handle, string $table, int $offset, int $maxRows): array
    {
        $written = 0;
        $exhausted = false;

        while ($written < $maxRows) {
            $limit = min(self::ROW_BATCH_SIZE, $maxRows - $written);
            $rows = $this->database->fetchAll("SELECT * FROM `{$table}` LIMIT {$limit} OFFSET " . ($offset + $written));

            if ($rows === []) {
                $exhausted = true;

                break;
            }

            $this->writeInsertStatement($handle, $table, $rows);
            $written += count($rows);

            if (count($rows) < $limit) {
                $exhausted = true;

                break;
            }
        }

        return ['written' => $written, 'exhausted' => $exhausted];
    }

    /**
     * @param resource $handle
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeInsertStatement($handle, string $table, array $rows): void
    {
        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));

        $valueGroups = [];

        foreach ($rows as $row) {
            $values = array_map(
                fn (mixed $value): string => $value === null ? 'NULL' : $this->database->pdo()->quote((string) $value),
                array_values($row),
            );

            $valueGroups[] = '(' . implode(', ', $values) . ')';
        }

        $insert = "INSERT INTO `{$table}` ({$columnList}) VALUES " . implode(', ', $valueGroups) . ';';
        fwrite($handle, $insert . self::STATEMENT_MARKER);
    }

    private function addDirectoryToZip(ZipArchive $zip, string $absolutePath, string $relativePath): void
    {
        $zip->addEmptyDir($relativePath);

        $items = scandir($absolutePath) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemAbsolute = $absolutePath . '/' . $item;
            $itemRelative = $relativePath . '/' . $item;

            if (is_dir($itemAbsolute)) {
                $this->addDirectoryToZip($zip, $itemAbsolute, $itemRelative);
            } else {
                $zip->addFile($itemAbsolute, $itemRelative);
            }
        }
    }

    private function ensureBackupsDirectory(): void
    {
        if (!is_dir($this->backupsPath) && !mkdir($this->backupsPath, 0755, true) && !is_dir($this->backupsPath)) {
            throw new RuntimeException('Unable to create the backups directory.');
        }
    }

    private function safeVersion(string $version): string
    {
        return preg_replace('/[^a-zA-Z0-9.\-]+/', '-', $version) ?? 'unknown';
    }

    /**
     * Extracts the "{version}-{Ymd-His}" key shared by a files/db backup
     * pair from a full path, or null if it doesn't match the expected
     * prefix/suffix at all.
     */
    private function backupKey(string $path, string $prefix, string $suffix): ?string
    {
        $basename = basename($path);

        if (!str_starts_with($basename, $prefix) || !str_ends_with($basename, $suffix)) {
            return null;
        }

        return substr($basename, strlen($prefix), -strlen($suffix));
    }

    /**
     * The timestamp suffix backupFiles()/backupDatabase() append is always
     * exactly 15 characters ("Ymd-His"), so it can be split off the end of
     * the key regardless of dashes already present in the version string
     * itself (e.g. "1.2.0-beta").
     */
    private function versionFromKey(string $key): string
    {
        $timestampLength = 15;

        if (strlen($key) <= $timestampLength) {
            return $key;
        }

        return rtrim(substr($key, 0, -$timestampLength), '-');
    }

    /**
     * Resolves a backup filename (never a full path) against
     * $backupsPath, rejecting anything that isn't a plain basename
     * matching the expected files-/db- naming convention (any one of
     * $suffixes — the db- half accepts both .sql.gz and .sql, since a
     * host without zlib produces the latter) or that doesn't actually
     * exist there.
     *
     * @param array<int, string> $suffixes
     */
    private function validatedBackupPath(string $filename, string $prefix, array $suffixes): string
    {
        $basename = basename($filename);

        if ($basename !== $filename || !str_starts_with($basename, $prefix)) {
            throw new RuntimeException('Invalid backup filename.');
        }

        $matchesSuffix = false;

        foreach ($suffixes as $suffix) {
            if (str_ends_with($basename, $suffix)) {
                $matchesSuffix = true;

                break;
            }
        }

        if (!$matchesSuffix) {
            throw new RuntimeException('Invalid backup filename.');
        }

        $path = rtrim($this->backupsPath, '/') . '/' . $basename;

        if (!is_file($path)) {
            throw new RuntimeException('Backup file not found.');
        }

        return $path;
    }
}
