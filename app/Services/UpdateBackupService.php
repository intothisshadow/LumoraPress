<?php

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
 */
final class UpdateBackupService
{
    private const STATEMENT_MARKER = "\n-- @@LUMORA_UPDATE_STMT_END@@\n";

    private const ROW_BATCH_SIZE = 500;

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
    ) {
    }

    public function backupFiles(string $version): string
    {
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

        $this->pruneOldBackups('files-*.zip');

        return $path;
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
    }

    public function backupDatabase(string $version): string
    {
        $this->ensureBackupsDirectory();

        $path = rtrim($this->backupsPath, '/') . '/db-' . $this->safeVersion($version) . '-' . date('Ymd-His') . '.sql';

        $tables = $this->prefixedTables();
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create the database backup file.');
        }

        try {
            foreach ($tables as $table) {
                $createRow = $this->database->fetchOne("SHOW CREATE TABLE `{$table}`");
                $createSql = (string) ($createRow['Create Table'] ?? '');

                if ($createSql === '') {
                    continue;
                }

                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;" . self::STATEMENT_MARKER);
                fwrite($handle, $createSql . self::STATEMENT_MARKER);

                $this->writeTableData($handle, $table);
            }
        } finally {
            fclose($handle);
        }

        $this->pruneOldBackups('db-*.sql');

        return $path;
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
     * @param resource $handle
     */
    private function writeTableData($handle, string $table): void
    {
        $offset = 0;

        while (true) {
            $rows = $this->database->fetchAll("SELECT * FROM `{$table}` LIMIT " . self::ROW_BATCH_SIZE . " OFFSET {$offset}");

            if ($rows === []) {
                break;
            }

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

            $offset += self::ROW_BATCH_SIZE;

            if (count($rows) < self::ROW_BATCH_SIZE) {
                break;
            }
        }
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
