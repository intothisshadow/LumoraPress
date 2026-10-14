<?php

/**
 * Checks every GitHub-sourced download for upstream changes, a few at a time, and remembers the answers.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use InvalidArgumentException;
use LumoraPress\Core\PressConfig;

/**
 * Nothing runs on its own: a person starts a run, and each call checks one small batch so a request
 * stays short and GitHub's hourly allowance (60 calls without an access token) is spent visibly. A
 * run is identified by the time it started; a download already answered since then is skipped, so
 * the browser can simply call again until nothing is left, and a stopped run can be resumed.
 *
 * The answers live in one site option, so no migration is needed. An answer is only believed while
 * it is newer than the download's last sync, which makes the marker disappear by itself once an
 * update has been applied.
 */
final class GitHubUpdateScanner
{
    public const OPTION = 'downloads_github_check_results';

    public const BATCH_SIZE = 5;

    public const STATUS_CHANGES = 'changes';

    public const STATUS_CURRENT = 'current';

    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly GitHubImportService $importer,
        private readonly GitHubSourceStore $store,
        private readonly PressConfig $config,
    ) {
    }

    /**
     * @return array<int, array{status: string, message: string, checkedAt: int}> keyed by download id
     */
    public function results(): array
    {
        $stored = json_decode((string) $this->config->option(self::OPTION, ''), true);
        $sources = $this->store->all();
        $results = [];

        foreach (is_array($stored) ? $stored : [] as $id => $result) {
            $id = (int) $id;

            if (!isset($sources[$id]) || !is_array($result) || !is_int($result['checkedAt'] ?? null)) {
                continue;
            }

            // An update applied since this answer was given makes it stale.
            $syncedAt = strtotime($sources[$id]['syncedAt']);

            if ($syncedAt !== false && $result['checkedAt'] < $syncedAt) {
                continue;
            }

            $results[$id] = [
                'status' => (string) ($result['status'] ?? self::STATUS_CURRENT),
                'message' => (string) ($result['message'] ?? ''),
                'checkedAt' => $result['checkedAt'],
            ];
        }

        return $results;
    }

    /**
     * How many downloads a run started at $runStartedAt still has to look at.
     */
    public function remaining(int $runStartedAt): int
    {
        return count($this->pending($runStartedAt));
    }

    public function total(): int
    {
        return count($this->store->all());
    }

    /**
     * Checks up to $limit downloads that have not been answered since the run started.
     *
     * @return array{checked: int, remaining: int, stopped: ?string, done: bool, changes: int}
     *     `stopped` carries GitHub's explanation when the run could not continue (its limit, or no connection)
     */
    public function scanBatch(int $runStartedAt, int $limit = self::BATCH_SIZE): array
    {
        $checked = 0;
        $stopped = null;

        foreach (array_slice($this->pending($runStartedAt), 0, max(1, $limit), true) as $downloadId) {
            try {
                $status = $this->importer->hasChanges($downloadId) ? self::STATUS_CHANGES : self::STATUS_CURRENT;
                $this->remember($downloadId, $status, '');
            } catch (GitHubImportException $exception) {
                // Their limit or a lost connection affects every remaining download alike, so stop
                // and leave the rest for a later run; a problem with one repository is that
                // download's own answer.
                if ($exception->reason === GitHubImportException::RATE_LIMITED || $exception->reason === GitHubImportException::UNREACHABLE) {
                    $stopped = $exception->getMessage();

                    break;
                }

                $this->remember($downloadId, self::STATUS_ERROR, $exception->getMessage());
            } catch (InvalidArgumentException) {
                // Source removed since the run began: nothing to check.
            }

            ++$checked;
        }

        $remaining = $stopped === null ? $this->remaining($runStartedAt) : count($this->pending($runStartedAt));

        return [
            'checked' => $checked,
            'remaining' => $remaining,
            'stopped' => $stopped,
            'done' => $stopped === null && $remaining === 0,
            'changes' => count(array_filter($this->results(), static fn (array $result): bool => $result['status'] === self::STATUS_CHANGES)),
        ];
    }

    /**
     * Download ids not yet answered since the run began, oldest answer first so a resumed run
     * starts with the downloads checked longest ago.
     *
     * @return array<int, int>
     */
    private function pending(int $runStartedAt): array
    {
        $answered = $this->rawResults();
        $pending = [];

        foreach (array_keys($this->store->all()) as $downloadId) {
            if (($answered[$downloadId]['checkedAt'] ?? 0) < $runStartedAt) {
                $pending[$downloadId] = $answered[$downloadId]['checkedAt'] ?? 0;
            }
        }

        asort($pending);

        return array_keys($pending);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rawResults(): array
    {
        $stored = json_decode((string) $this->config->option(self::OPTION, ''), true);

        return is_array($stored) ? array_filter($stored, 'is_array') : [];
    }

    private function remember(int $downloadId, string $status, string $message): void
    {
        $all = $this->rawResults();
        $all[$downloadId] = ['status' => $status, 'message' => mb_substr($message, 0, 300), 'checkedAt' => time()];

        // Answers for downloads that no longer have a source are dropped whenever one is saved.
        $all = array_intersect_key($all, $this->store->all());

        $this->config->setOption(self::OPTION, (string) json_encode($all));
    }
}
