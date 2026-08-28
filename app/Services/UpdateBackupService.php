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

    /**
     * Rows written per backupDatabaseBatch() call — sized for one HTTP
     * request rather than one query (see that method's docblock for why
     * the database dump needs to span multiple requests at all).
     */
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
    ) {
    }

    /**
     * Exposes the configured backup destination — UpdateService's
     * "Verify backup location" pre-update check needs this to confirm the
     * directory is writable with enough free space before an update ever
     * gets as far as actually calling backupFiles()/backupDatabase().
     */
    public function backupsPath(): string
    {
        return $this->backupsPath;
    }

    public function backupFiles(string $version): string
    {
        // Not a hard cap on this host's own configuration — requests "no
        // limit" the same way the GeoLite2 import and WordPress Importer
        // scans already do for a comparably slow, one-time admin
        // operation. corePaths covers only the application's own code
        // (never content/uploads or other user data), so this is bounded
        // by the size of the app itself rather than by site content —
        // unlike backupDatabase(), it isn't expected to actually need
        // this, but it costs nothing to have it.
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
     * "Backup verification" — reopens a just-created archive with
     * ZipArchive::CHECKCONS, which validates its central directory and
     * local file headers are internally consistent, rather than trusting
     * that a successful write() implies a readable archive. Deliberately
     * does not re-read every entry's bytes (that would double the I/O cost
     * of every backup for large sites, contrary to CLAUDE.md's performance
     * goals) — a corrupt central directory is the failure mode that
     * actually matters here (a truncated write, a disk that filled up
     * mid-write), and CHECKCONS catches exactly that.
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
     * "Backup verification" for the database half of a pair — a
     * non-empty dump must end with the same statement marker every
     * completed fwrite() call in writeTableData()/backupDatabase() itself
     * appends, so a dump truncated by a crash or a full disk (ending
     * mid-statement, with no trailing marker) is caught immediately rather
     * than only discovered when a restore silently applies a partial SQL
     * script. An empty dump is valid on its own (no prefixed tables yet,
     * e.g. a fresh install) and is not flagged.
     */
    private function verifyDatabaseBackup(string $path): void
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return;
        }

        $markerLength = strlen(self::STATEMENT_MARKER);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The database backup file could not be reopened for verification.');
        }

        $tail = fseek($handle, -$markerLength, SEEK_END) === 0 ? fread($handle, $markerLength) : false;
        fclose($handle);

        if ($tail !== self::STATEMENT_MARKER) {
            throw new RuntimeException('The database backup file appears to be truncated or corrupted.');
        }
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

        foreach (glob(rtrim($this->backupsPath, '/') . '/db-*.sql') ?: [] as $path) {
            $key = $this->backupKey($path, 'db-', '.sql');

            if ($key === null) {
                continue;
            }

            $entries[$key]['version'] ??= $this->versionFromKey($key);
            $entries[$key]['created_at'] ??= filemtime($path) ?: 0;
            $entries[$key]['database_filename'] = basename($path);
            $entries[$key]['database_size'] = filesize($path) ?: 0;
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
        $this->restoreFiles($this->validatedBackupPath($filename, 'files-', '.zip'));
    }

    /**
     * Same filename-only safety as restoreFilesByFilename(), for the
     * database backup half of a pair.
     */
    public function restoreDatabaseByFilename(string $filename): void
    {
        $this->restoreDatabase($this->validatedBackupPath($filename, 'db-', '.sql'));
    }

    /**
     * Deletes a backup pair (or just its files half, if $databaseFilename
     * is null) from disk, with the same filename-only safety as the
     * restore-by-filename methods.
     */
    public function deleteBackup(?string $filesFilename, ?string $databaseFilename): void
    {
        if ($filesFilename !== null) {
            unlink($this->validatedBackupPath($filesFilename, 'files-', '.zip'));
        }

        if ($databaseFilename !== null) {
            unlink($this->validatedBackupPath($databaseFilename, 'db-', '.sql'));
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

        // Restore never deletes anything (see this class's docblock), so
        // rewriting the manifest here can only ever cause a later
        // Install to under-remove a stale leftover, never to remove
        // something it shouldn't — keeps the manifest from describing a
        // version that a restore just moved away from.
        $this->manifest->write($this->corePaths);

        // A restore can bring back an arbitrary (possibly much older)
        // backup — not necessarily the one taken immediately before the
        // most recent install() — so the checksum baseline can no longer
        // be trusted to describe what's now live. Clearing it (rather than
        // recomputing) is the safe choice: the restored files may
        // themselves have carried admin modifications before the backup
        // was taken, and a fresh install() run will simply repopulate a
        // correct baseline the next time one succeeds.
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
     * Writes up to BATCH_ROW_COUNT rows of the database dump per call —
     * sized for one HTTP request, since dumping the whole database in
     * one synchronous call (the original shape of this method) can
     * complete successfully server-side yet still have its HTTP response
     * killed by the webserver/proxy layer on a large site, the same
     * "PHP finishes, the response doesn't arrive" failure mode diagnosed
     * for the Visitor Stats plugin's GeoLite2 import (LPP-014). Unlike
     * that CSV-reading case, no fseek()/resume-by-byte-offset is needed
     * here — writing the dump is inherently append-only across calls, so
     * (tableIndex, rowOffset) alone is enough to resume.
     *
     * $path is null on the very first call (this method then picks the
     * real destination filename and opens it fresh); every subsequent
     * call must pass back the exact path this method returned before.
     * $tableIndex/$rowOffset must likewise be threaded straight through
     * from the previous call's return value — 0/0 to start.
     *
     * The table list is re-resolved (a cheap SHOW TABLES) on every call
     * rather than trusted from prior state, the same "recompute, don't
     * trust stale state" choice ViewStatsService::importGeoCsvBatch()
     * makes for its Locations CSV.
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
        // Each batch is capped specifically so it finishes well within a
        // shared host's default execution-time limit; set_time_limit(0)
        // here is a safety margin, not because a single batch is
        // expected to run long.
        set_time_limit(0);

        $isFirstCall = $path === null;

        if ($isFirstCall) {
            $this->ensureBackupsDirectory();
            $path = rtrim($this->backupsPath, '/') . '/db-' . $this->safeVersion($version) . '-' . date('Ymd-His') . '.sql';
        }

        $tables = $this->prefixedTables();
        $handle = fopen($path, $isFirstCall ? 'wb' : 'ab');

        if ($handle === false) {
            throw new RuntimeException('Unable to create the database backup file.');
        }

        try {
            $remaining = $batchSize;

            while ($remaining > 0 && $tableIndex < count($tables)) {
                $table = $tables[$tableIndex];

                if ($rowOffset === 0 && !$this->writeCreateStatements($handle, $table)) {
                    // SHOW CREATE TABLE came back empty (the table
                    // vanished mid-backup) — skip its data entirely,
                    // mirroring the original single-shot method's own
                    // "continue" for this same edge case.
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
            $this->pruneOldBackups('db-*.sql');
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

        $contents = file_get_contents($backupSqlPath);

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
     * Writes up to $maxRows of $table's data, starting at $offset, in
     * internal chunks of ROW_BATCH_SIZE (one query each, same as the
     * original single-shot method) — bounded by $maxRows so a caller can
     * cap how much work a single request does regardless of how large
     * the table actually is.
     *
     * @param resource $handle
     * @return array{written: int, exhausted: bool} exhausted is true once
     *     the table's real end was reached (a query returned fewer rows
     *     than asked for) — false means $maxRows was hit first and more
     *     of this same table remains for a later call.
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
     * matching the expected files-/db- naming convention or that doesn't
     * actually exist there.
     */
    private function validatedBackupPath(string $filename, string $prefix, string $suffix): string
    {
        $basename = basename($filename);

        if (
            $basename !== $filename
            || !str_starts_with($basename, $prefix)
            || !str_ends_with($basename, $suffix)
        ) {
            throw new RuntimeException('Invalid backup filename.');
        }

        $path = rtrim($this->backupsPath, '/') . '/' . $basename;

        if (!is_file($path)) {
            throw new RuntimeException('Backup file not found.');
        }

        return $path;
    }
}
