<?php

/**
 * Tracks live stage-by-stage progress for an in-flight update, polled by the admin Updates page while a download or install request is running.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

/**
 * Tracks live stage-by-stage progress for an in-flight update (download,
 * validation, or install), read by a lightweight polling endpoint
 * (admin/views/maintenance/updates.php's `?ajax=progress` branch) while the
 * long-running request itself is still executing on a separate connection.
 *
 * This only works because the request driving the update calls
 * `session_write_close()` before starting the long operation (see
 * updates.php) — PHP's default session handler locks the session file for
 * the request's entire duration, so without releasing that lock early, a
 * concurrent polling request sharing the same session would simply queue
 * behind it and never observe anything until the update was already done.
 *
 * The caller that starts an operation (always the view, never a service —
 * see UpdateService::install()/checkUpload()'s own docblocks) declares the
 * full stage list up front via reset(), so the UI can render every step
 * immediately with "pending" placeholders rather than only learning about
 * a step once it starts. Individual stages are then reported via stage()
 * as the operation actually reaches each one, and complete() closes out
 * the run — marking whatever stage was still "active" as either "done" or
 * "error" depending on outcome, and leaving any stage never reached at
 * "pending" so a failure's real point of failure stays visible.
 *
 * State is a single JSON file (like UpdateManifest/UpdateChecksumManifest)
 * rather than the database — this is transient, single-reader/single-writer
 * progress for whichever update is currently running (the existing
 * update.lock file already ensures only one can run at a time), not data
 * worth persisting or querying.
 */
final class UpdateProgress
{
    private const PATH_SUFFIX = '/storage/updates/progress.json';

    /** @var array{operation: ?string, stages: array<int, array{key: string, label: string, status: string}>, done: bool, success: ?bool, message: ?string, updated_at: int} */
    private const DEFAULT_STATE = [
        'operation' => null,
        'stages' => [],
        'done' => true,
        'success' => null,
        'message' => null,
        'updated_at' => 0,
    ];

    public function __construct(private readonly string $installRoot)
    {
    }

    /**
     * @param array<int, array{key: string, label: string}> $stages
     */
    public function reset(string $operation, array $stages): void
    {
        $this->write([
            'operation' => $operation,
            'stages' => array_map(
                static fn (array $stage): array => ['key' => $stage['key'], 'label' => $stage['label'], 'status' => 'pending'],
                $stages,
            ),
            'done' => false,
            'success' => null,
            'message' => null,
            'updated_at' => time(),
        ]);
    }

    /**
     * Marks $key "active" and, if some other stage was still "active" (the
     * caller moved on without explicitly finishing it), quietly marks that
     * one "done" first — callers report progress by starting the next
     * stage, not by explicitly closing the previous one.
     */
    public function stage(string $key): void
    {
        $state = $this->read();
        $matched = false;

        foreach ($state['stages'] as &$stage) {
            if ($stage['key'] === $key) {
                $stage['status'] = 'active';
                $matched = true;
            } elseif ($stage['status'] === 'active') {
                $stage['status'] = 'done';
            }
        }
        unset($stage);

        if (!$matched) {
            // Unknown key (a caller reporting a stage the view never
            // declared via reset()) — nothing sensible to render, so
            // leave the state untouched rather than writing a phantom row.
            return;
        }

        $state['updated_at'] = time();
        $this->write($state);
    }

    public function complete(bool $success, string $message): void
    {
        $state = $this->read();

        foreach ($state['stages'] as &$stage) {
            if ($stage['status'] === 'active') {
                $stage['status'] = $success ? 'done' : 'error';
            }
        }
        unset($stage);

        $state['done'] = true;
        $state['success'] = $success;
        $state['message'] = $message;
        $state['updated_at'] = time();
        $this->write($state);
    }

    /**
     * @return array{operation: ?string, stages: array<int, array{key: string, label: string, status: string}>, done: bool, success: ?bool, message: ?string, updated_at: int}
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
     * @param array{operation: ?string, stages: array<int, array{key: string, label: string, status: string}>, done: bool, success: ?bool, message: ?string, updated_at: int} $state
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
