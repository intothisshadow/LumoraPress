<?php

/**
 * Tracks live stage-by-stage progress for an in-flight WordPress import, polled by the admin Import page while a Start/Resume Import request is running.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\WordPressImporter;

/**
 * A near-identical mirror of the core `UpdateProgress` class, kept as its
 * own copy since this plugin constructs its dependencies directly from
 * `$kernel` rather than through a registered service.
 *
 * Only works because the request driving an import calls
 * `session_write_close()` first (see admin/views/maintenance/import.php) —
 * otherwise a concurrent polling request sharing the session would queue
 * behind the session lock and never observe progress until it was done.
 *
 * State is a single JSON file — transient, single-reader/single-writer
 * progress for whichever import is running. Real resumability after an
 * interrupted import is tracked separately via the database.
 */
final class ImportProgress
{
    private const PATH_SUFFIX = '/storage/wordpress-importer/progress.json';

    /** @var array{stages: array<int, array{key: string, label: string, status: string}>, done: bool, updated_at: int} */
    private const DEFAULT_STATE = [
        'stages' => [],
        'done' => true,
        'updated_at' => 0,
    ];

    public function __construct(private readonly string $installRoot)
    {
    }

    /**
     * @param array<int, array{key: string, label: string}> $stages
     */
    public function reset(array $stages): void
    {
        $this->write([
            'stages' => array_map(
                static fn (array $stage): array => ['key' => $stage['key'], 'label' => $stage['label'], 'status' => 'pending'],
                $stages,
            ),
            'done' => false,
            'updated_at' => time(),
        ]);
    }

    /**
     * Marks $key "active" and quietly marks any prior "active" stage "done" —
     * only ever needs to handle forward progress, since a resumed import's
     * completed stages are declared straight into "done" via reset().
     */
    public function stage(string $key): void
    {
        $state = $this->read();
        $matched = false;

        foreach ($state['stages'] as &$entry) {
            if ($entry['key'] === $key) {
                $entry['status'] = 'active';
                $matched = true;
            } elseif ($entry['status'] === 'active') {
                $entry['status'] = 'done';
            }
        }
        unset($entry);

        if (!$matched) {
            return;
        }

        $state['updated_at'] = time();
        $this->write($state);
    }

    public function complete(): void
    {
        $state = $this->read();

        foreach ($state['stages'] as &$entry) {
            if ($entry['status'] === 'active') {
                $entry['status'] = 'done';
            }
        }
        unset($entry);

        $state['done'] = true;
        $state['updated_at'] = time();
        $this->write($state);
    }

    /**
     * @return array{stages: array<int, array{key: string, label: string, status: string}>, done: bool, updated_at: int}
     */
    public function read(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return self::DEFAULT_STATE;
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return self::DEFAULT_STATE;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return self::DEFAULT_STATE;
        }

        return array_merge(self::DEFAULT_STATE, $decoded);
    }

    /**
     * @param array{stages: array<int, array{key: string, label: string, status: string}>, done: bool, updated_at: int} $state
     */
    private function write(array $state): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        $encoded = json_encode($state);

        if ($encoded === false) {
            return;
        }

        // Write-then-rename so a poller never sees a half-written JSON file — rename() is atomic.
        $tmpPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmpPath, $encoded) === false) {
            return;
        }

        rename($tmpPath, $path);
    }

    private function path(): string
    {
        return rtrim($this->installRoot, '/') . self::PATH_SUFFIX;
    }
}
