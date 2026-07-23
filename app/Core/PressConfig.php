<?php

declare(strict_types=1);

namespace LumoraPress\Core;

use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * Central configuration service.
 *
 * Two layers are exposed:
 *  - File config: bootstrap-critical values (database credentials, table
 *    prefix, secret key, debug flag) loaded once from config/config.php.
 *  - Options: editable site settings (site name, timezone, language, ...)
 *    persisted in the database and cached in memory after first read.
 */
final class PressConfig
{
    /** @var array<string, mixed> */
    private array $fileConfig = [];

    private bool $fileLoaded = false;

    /** @var array<string, string|null> */
    private array $options = [];

    private bool $optionsLoaded = false;

    private ?Database $database = null;

    public function __construct(private readonly string $configFile)
    {
    }

    public function bindDatabase(Database $database): void
    {
        $this->database = $database;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureFileLoaded();

        return $this->fileConfig[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureFileLoaded();
        $this->fileConfig[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->ensureFileLoaded();

        return $this->fileConfig;
    }

    public function has(string $key): bool
    {
        $this->ensureFileLoaded();

        return array_key_exists($key, $this->fileConfig);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        $this->ensureOptionsLoaded();

        return $this->options[$key] ?? $default;
    }

    public function setOption(string $key, mixed $value): void
    {
        $this->ensureOptionsLoaded();

        $serialized = match (true) {
            $value === null, is_scalar($value) => $value === null ? null : (string) $value,
            default => serialize($value),
        };

        $this->options[$key] = $serialized;

        if ($this->database === null) {
            return;
        }

        $table = $this->optionsTable();

        $this->database->execute(
            "INSERT INTO {$table} (option_name, option_value) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            ['name' => $key, 'value' => $serialized],
        );
    }

    /**
     * Regenerates the config/config.php file. Used by the installer.
     *
     * @param array<string, mixed> $data
     */
    public static function generate(string $path, array $data): void
    {
        $export = var_export($data, true);
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n";

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the configuration file.');
        }
    }

    private function ensureFileLoaded(): void
    {
        if ($this->fileLoaded) {
            return;
        }

        if (!is_file($this->configFile)) {
            throw new RuntimeException('Configuration file not found. Run the installer first.');
        }

        $data = require $this->configFile;

        if (!is_array($data)) {
            throw new RuntimeException('Configuration file must return an array.');
        }

        $this->fileConfig = $data;
        $this->fileLoaded = true;
    }

    private function ensureOptionsLoaded(): void
    {
        if ($this->optionsLoaded || $this->database === null) {
            return;
        }

        $rows = $this->database->fetchAll('SELECT option_name, option_value FROM ' . $this->optionsTable());

        foreach ($rows as $row) {
            $this->options[$row['option_name']] = $row['option_value'];
        }

        $this->optionsLoaded = true;
    }

    private function optionsTable(): string
    {
        return $this->get('table_prefix', 'lp_') . 'options';
    }
}
