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
 * A near-identical mirror of the core `UpdateProgress` class (LP-086),
 * kept as its own copy here rather than reused directly: this plugin's
 * admin view constructs its own dependencies straight from `$kernel`
 * without going through a `Kernel`-registered service (see
 * `wordpress-importer.php`'s own docblock on why — a plugin main file
 * loads before `Kernel` exists), and `UpdateProgress` itself carries no
 * update-specific logic worth sharing beyond this shape, so a second
 * small class is simpler than adding a constructor parameter to a core
 * service for one plugin's use.
 *
 * This only works because the request driving an import calls
 * `session_write_close()` before starting the long operation (see
 * admin/views/maintenance/import.php) — PHP's default session handler
 * locks the session file for the request's entire duration, so without
 * releasing that lock early, a concurrent polling request sharing the
 * same session would simply queue behind it and never observe anything
 * until the import was already done.
 *
 * State is a single JSON file, like UpdateProgress's own — this is
 * transient, single-reader/single-writer progress for whichever import
 * is currently running (WordPressImportService::startOrResume()'s own
 * "one import at a time" guard already ensures only one can run), not
 * data worth persisting or querying. Real resumability after an
 * interrupted import is tracked separately, in the database, via
 * WordPressImportService's own 'progress_snap' state — this class only
 * ever drives the live progress bar for a request that's actively
 * running right now.
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
     * Marks $key "active" and, if some other stage was still "active" or
     * "pending" before it in the declared list, quietly marks it "done"
     * first — a resumed import's already-completed stages are declared
     * straight into "done" via reset() rather than replayed through
     * here, so this only ever needs to handle forward progress.
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

        // Write-then-rename rather than a direct file_put_contents(): a
        // poller reading mid-write would otherwise sometimes see a
        // truncated/partial JSON document, since this file is rewritten
        // on every single stage transition while another request may be
        // reading it at any moment. rename() on the same filesystem is
        // atomic, so a reader only ever sees a complete previous or new
        // version, never a half-written one.
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
