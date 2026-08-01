<?php

declare(strict_types=1);

namespace LumoraPress\Core\Database;

/**
 * Applies pending .sql migration files from a directory in filename order,
 * tracking what has already run in a dedicated migrations table.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $database,
        private readonly string $migrationsPath,
        private readonly string $tablePrefix,
    ) {
    }

    public function ensureMigrationsTable(): void
    {
        $table = $this->migrationsTable();

        $this->database->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(191) NOT NULL,
                executed_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * @return array<int, string>
     */
    public function pending(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($files);

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !in_array(basename($file), $applied, true),
        ));
    }

    /**
     * @return array{applied: int, total: int, up_to_date: bool}
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $applied = count($this->appliedMigrations());
        $total = count(glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: []);

        return ['applied' => $applied, 'total' => $total, 'up_to_date' => $applied >= $total];
    }

    /**
     * @return array<int, string> names of migrations that were executed
     */
    public function migrate(): array
    {
        $executed = [];

        foreach ($this->pending() as $file) {
            $sql = file_get_contents($file);

            if ($sql === false || trim($sql) === '') {
                continue;
            }

            $sql = str_replace('{prefix}', $this->tablePrefix, $sql);

            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $this->database->pdo()->exec($statement);
            }

            $this->recordMigration(basename($file));
            $executed[] = basename($file);
        }

        return $executed;
    }

    /**
     * @return array<int, string>
     */
    private function appliedMigrations(): array
    {
        $rows = $this->database->fetchAll('SELECT migration FROM ' . $this->migrationsTable());

        return array_column($rows, 'migration');
    }

    private function recordMigration(string $migration): void
    {
        $this->database->execute(
            'INSERT INTO ' . $this->migrationsTable() . ' (migration, executed_at) VALUES (:migration, :executed_at)',
            ['migration' => $migration, 'executed_at' => date('Y-m-d H:i:s')],
        );
    }

    private function migrationsTable(): string
    {
        return $this->tablePrefix . 'migrations';
    }
}
