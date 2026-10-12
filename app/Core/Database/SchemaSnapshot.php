<?php

/**
 * Builds and applies a single-file snapshot of the schema the migrations produce.
 *
 * @package LumoraPress
 * @subpackage Database
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Database;

use Throwable;

/**
 * Lets a brand-new install create its tables from one generated file instead of replaying every
 * migration ever written. Updates never use it: they keep applying the individual migration files
 * they are missing. The file records the last migration it covers, so a snapshot that is out of
 * date only costs speed, because the migrator still applies anything newer afterwards.
 */
final class SchemaSnapshot
{
    /**
     * A line of its own between statements. A plain ";" can't be used because seeded values may
     * contain one, and the migrator's naive splitting would cut them in half.
     */
    public const STATEMENT_SEPARATOR = '-- >>>';

    private const THROUGH_PATTERN = '/^-- Through: (\S+\.sql)\s*$/m';

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly string $snapshotPath,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * Creates the schema from the snapshot and records the migrations it covers, but only on a
     * database with no table under this prefix. Anything else, or any failure, leaves the
     * database as it was found and returns false so the caller runs the normal migration chain.
     */
    public function applyIfClean(Migrator $migrator): bool
    {
        $covered = $this->coveredMigrations();
        $statements = $this->statements();

        if ($covered === [] || $statements === [] || $this->prefixedTables() !== []) {
            return false;
        }

        $pdo = $this->database->pdo();

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($statements as $statement) {
                $pdo->exec(str_replace('{prefix}', $this->tablePrefix, $statement));
            }

            $migrator->markApplied($covered);

            return true;
        } catch (Throwable) {
            // The prefix held no table before this ran, so everything under it is ours to remove.
            foreach ($this->prefixedTables() as $table) {
                $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }

            return false;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The migration files the snapshot already includes, in apply order.
     *
     * @return array<int, string>
     */
    public function coveredMigrations(): array
    {
        if (!is_file($this->snapshotPath)) {
            return [];
        }

        $contents = (string) file_get_contents($this->snapshotPath);

        if (preg_match(self::THROUGH_PATTERN, $contents, $matches) !== 1) {
            return [];
        }

        return array_values(array_filter(
            $this->migrationFiles(),
            static fn (string $name): bool => strcmp($name, $matches[1]) <= 0,
        ));
    }

    /**
     * Builds the snapshot text from the tables currently under this prefix, which must have been
     * created by running every migration against an empty database.
     */
    public function generate(): string
    {
        $files = $this->migrationFiles();

        if ($files === []) {
            throw new \RuntimeException('There are no migration files to build a snapshot from.');
        }

        $migrationsTable = $this->tablePrefix . 'migrations';
        $statements = [];

        foreach ($this->prefixedTables() as $table) {
            if ($table === $migrationsTable) {
                continue;
            }

            $row = $this->database->fetchOne('SHOW CREATE TABLE `' . $table . '`');
            $ddl = (string) array_values($row ?? [])[1];
            $statements[] = self::normalizeCreateTable($ddl, $this->tablePrefix);

            $insert = $this->insertStatement($table);

            if ($insert !== null) {
                $statements[] = $insert;
            }
        }

        $header = implode("\n", [
            '-- Lumora Press schema snapshot. Generated; do not edit by hand.',
            '-- Regenerate with "PHP Test Suite/generate-schema-snapshot.sh" whenever a migration is added.',
            '-- Through: ' . end($files),
        ]);

        return $header . "\n" . self::STATEMENT_SEPARATOR . "\n"
            . implode("\n" . self::STATEMENT_SEPARATOR . "\n", $statements) . "\n";
    }

    /**
     * Makes a SHOW CREATE TABLE statement portable between MySQL and MariaDB and independent of
     * the database it was dumped from: the prefix becomes a placeholder, and the auto-increment
     * counter and the table's default collation are dropped so the target server's default
     * applies, as it does for the hand-written migrations. A column's own COLLATE is kept: a
     * server only prints one for a column that asked for it.
     */
    public static function normalizeCreateTable(string $ddl, string $tablePrefix): string
    {
        $ddl = str_replace($tablePrefix, '{prefix}', $ddl);
        $ddl = (string) preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $ddl);
        $ddl = (string) preg_replace('/\s+COLLATE=\w+/i', '', $ddl);

        return trim($ddl);
    }

    /**
     * @return array<int, string> the executable statements, in file order
     */
    private function statements(): array
    {
        if (!is_file($this->snapshotPath)) {
            return [];
        }

        $chunks = explode("\n" . self::STATEMENT_SEPARATOR . "\n", (string) file_get_contents($this->snapshotPath));
        $statements = [];

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);

            if ($chunk !== '' && !str_starts_with($chunk, '--')) {
                $statements[] = $chunk;
            }
        }

        return $statements;
    }

    private function insertStatement(string $table): ?string
    {
        $rows = $this->database->fetchAll('SELECT * FROM `' . $table . '` ORDER BY 1');

        if ($rows === []) {
            return null;
        }

        $timeColumns = array_column($this->database->fetchAll(
            "SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND data_type IN ('datetime', 'timestamp')",
            ['table' => $table],
        ), 'name');

        $pdo = $this->database->pdo();
        $columns = array_keys($rows[0]);
        $values = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row as $column => $value) {
                // Seeded timestamps record when a migration ran, which a snapshot must not freeze.
                $cells[] = $value === null ? 'NULL' : (in_array($column, $timeColumns, true) ? 'NOW()' : $pdo->quote((string) $value));
            }

            $values[] = '(' . implode(', ', $cells) . ')';
        }

        return str_replace($this->tablePrefix, '{prefix}', 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ' . "\n" . implode(",\n", $values));
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $names = array_map('basename', glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: []);
        sort($names);

        return $names;
    }

    /**
     * Reads live database state, so two calls can differ.
     *
     * @phpstan-impure
     * @return array<int, string>
     */
    private function prefixedTables(): array
    {
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->tablePrefix) . '%';
        $rows = $this->database->fetchAll(
            'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :pattern ORDER BY table_name',
            ['pattern' => $pattern],
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }
}
