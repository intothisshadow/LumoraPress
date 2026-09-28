<?php

/**
 * Upkeep and health checks for the search index: staged rebuild, scheduled refresh, and diagnostics.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Search;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use PDOException;
use RuntimeException;

/**
 * The database keeps the posts and pages FULLTEXT indexes current on every
 * save, so "indexing" here means upkeep: recreating an index that went
 * missing, compacting the ones deletions leave bloated (OPTIMIZE TABLE
 * rebuilds them online), and refreshing the word list.
 *
 * A rebuild is a fixed list of stages, each stateless, so the admin
 * screen can run them one request at a time with progress and a slow
 * table never hits a request time limit. The scheduled refresh runs the
 * same stages in one go, after the visitor's page has been sent.
 */
final class SearchIndex
{
    public const SCHEDULE_OPTION = 'search_index_schedule';

    public const LAST_RUN_OPTION = 'search_index_last_run';

    /** @var array<string, int> Seconds between scheduled refreshes; 0 is never. */
    public const SCHEDULES = ['off' => 0, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2592000];

    /** @var list<string> */
    public const STAGES = ['posts', 'pages', 'word_list', 'finish'];

    /** @var array<string, string> Stage => what the admin screen says while it runs. */
    public const STAGE_LABELS = [
        'posts' => 'Rebuilding the post index',
        'pages' => 'Rebuilding the page index',
        'word_list' => 'Rebuilding the search word list',
        'finish' => 'Clearing saved search results',
    ];

    /** @var array<string, string> Table suffix => label. */
    private const TABLES = ['posts' => 'Posts', 'pages' => 'Pages'];

    /** @var array<string, string> Index name => columns; MATCH() must name exactly these. */
    private const FULLTEXT_INDEXES = [
        'idx_fulltext_title_content' => 'title, content',
        'idx_fulltext_title' => 'title',
    ];

    /** A failed scheduled refresh waits this long before another try. */
    private const FAILED_RETRY_SECONDS = 21600;

    /** Stops two simultaneous requests both starting a scheduled refresh. */
    private const LOCK_SECONDS = 300;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
        private readonly SearchVocabulary $vocabulary,
        private readonly ?SearchResultCache $cache = null,
        private readonly string $lockPath = '',
    ) {
    }

    public function schedule(): string
    {
        $schedule = (string) $this->config->option(self::SCHEDULE_OPTION, 'off');

        return isset(self::SCHEDULES[$schedule]) ? $schedule : 'off';
    }

    /**
     * Sending the page before the refresh starts needs PHP-FPM or LiteSpeed;
     * elsewhere it would make one visitor wait, so it never runs unattended.
     */
    public static function backgroundRunSupported(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /**
     * @return array{at: DateTimeImmutable, ok: bool, words: int}|null
     */
    public function lastRun(): ?array
    {
        $decoded = json_decode((string) $this->config->option(self::LAST_RUN_OPTION, ''), true);

        if (!is_array($decoded) || !is_string($decoded['at'] ?? null)) {
            return null;
        }

        return [
            'at' => new DateTimeImmutable($decoded['at']),
            'ok' => ($decoded['ok'] ?? false) === true,
            'words' => (int) ($decoded['words'] ?? 0),
        ];
    }

    public function isDue(?DateTimeImmutable $now = null): bool
    {
        $interval = self::SCHEDULES[$this->schedule()];

        if ($interval === 0) {
            return false;
        }

        $last = $this->lastRun();

        if ($last === null) {
            return true;
        }

        $wait = $last['ok'] ? $interval : self::FAILED_RETRY_SECONDS;

        return (($now ?? new DateTimeImmutable())->getTimestamp() - $last['at']->getTimestamp()) >= $wait;
    }

    /**
     * Runs the whole rebuild when the schedule says it is time. Meant for
     * the end of a request whose response has already been sent.
     */
    public function runIfDue(): bool
    {
        if (!$this->isDue() || $this->lockPath === '') {
            return false;
        }

        $handle = fopen($this->lockPath, 'c+');

        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            $startedAt = (int) stream_get_contents($handle);

            if (time() - $startedAt < self::LOCK_SECONDS) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) time());
            fflush($handle);

            return $this->rebuildAll();
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return bool Whether every stage succeeded.
     */
    public function rebuildAll(): bool
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $stage = self::STAGES[0];

        while ($stage !== null) {
            $result = $this->runStage($stage);

            if ($result['error'] !== null) {
                return false;
            }

            $stage = $result['next'];
        }

        return true;
    }

    /**
     * @return array{next: ?string, error: ?string} The stage to run next (null once finished), or a message safe to show an administrator.
     */
    public function runStage(string $stage): array
    {
        if (!in_array($stage, self::STAGES, true)) {
            return ['next' => null, 'error' => 'Unknown step.'];
        }

        try {
            match ($stage) {
                'posts' => $this->rebuildTable('posts'),
                'pages' => $this->rebuildTable('pages'),
                'word_list' => $this->vocabulary->rebuild(),
                'finish' => $this->finish(),
            };
        } catch (PDOException | RuntimeException $exception) {
            error_log('[search-index] ' . $stage . ' failed: ' . $exception->getMessage());
            $this->recordRun(false);

            return ['next' => null, 'error' => 'The database could not rebuild this step. Details were written to the server error log.'];
        }

        $position = array_search($stage, self::STAGES, true);

        return ['next' => self::STAGES[$position + 1] ?? null, 'error' => null];
    }

    /**
     * What is wrong with the search index, if anything, and the facts
     * needed to judge it. MySQL/MariaDB only; elsewhere `supported` is
     * false and only the parts that don't depend on it are filled in.
     *
     * @return array{supported: bool, tables: list<array{label: string, rows: int, engine: string, indexes: array<string, array{present: bool, working: bool}>}>, minTokenSize: ?int, stopwords: ?bool, problems: list<string>}
     */
    public function diagnostics(): array
    {
        $report = ['supported' => true, 'tables' => [], 'minTokenSize' => null, 'stopwords' => null, 'problems' => []];

        try {
            foreach (self::TABLES as $suffix => $label) {
                $table = $this->tableDiagnostics($suffix, $label);
                $report['tables'][] = $table;

                if (!in_array($table['engine'], ['InnoDB', 'MyISAM', 'Aria'], true)) {
                    $report['problems'][] = $label . ' are stored in a table type (' . ($table['engine'] !== '' ? $table['engine'] : 'unknown') . ') that cannot be searched this way.';
                }

                foreach ($table['indexes'] as $name => $state) {
                    if (!$state['present']) {
                        $report['problems'][] = 'The ' . strtolower($label) . ' search index "' . $name . '" is missing. Rebuilding the index recreates it.';
                    } elseif (!$state['working']) {
                        $report['problems'][] = 'The ' . strtolower($label) . ' search index "' . $name . '" exists but could not be used for a test search.';
                    }
                }
            }

            foreach ($this->database->fetchAll(
                "SHOW VARIABLES WHERE Variable_name IN ('innodb_ft_min_token_size', 'ft_min_word_len', 'innodb_ft_enable_stopword')",
            ) as $row) {
                $value = (string) $row['Value'];

                match ((string) $row['Variable_name']) {
                    'innodb_ft_min_token_size' => $report['minTokenSize'] = (int) $value,
                    'ft_min_word_len' => $report['minTokenSize'] ??= (int) $value,
                    default => $report['stopwords'] = in_array(strtolower($value), ['on', '1'], true),
                };
            }
        } catch (PDOException) {
            $report['supported'] = false;
            $report['problems'][] = 'Index details could not be read from this database. Search needs MySQL or MariaDB.';
        }

        return $report;
    }

    /**
     * @return array{label: string, rows: int, engine: string, indexes: array<string, array{present: bool, working: bool}>}
     */
    private function tableDiagnostics(string $suffix, string $label): array
    {
        $table = $this->tablePrefix . $suffix;
        $present = $this->fulltextIndexNames($table);
        $indexes = [];

        foreach (self::FULLTEXT_INDEXES as $name => $columns) {
            $isPresent = in_array($name, $present, true);
            $indexes[$name] = ['present' => $isPresent, 'working' => $isPresent && $this->canSearch($table, $columns)];
        }

        return [
            'label' => $label,
            'rows' => (int) $this->database->fetchColumn("SELECT COUNT(*) FROM {$table}"),
            'engine' => (string) $this->database->fetchColumn(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table],
            ),
            'indexes' => $indexes,
        ];
    }

    /**
     * A real query, because an index can be listed yet unusable (a table
     * left half-converted, say) and MATCH() fails loudly when it is.
     */
    private function canSearch(string $table, string $columns): bool
    {
        try {
            $this->database->fetchColumn("SELECT COUNT(*) FROM {$table} WHERE MATCH({$columns}) AGAINST(:probe IN BOOLEAN MODE)", ['probe' => 'lumora*']);

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function fulltextIndexNames(string $table): array
    {
        $names = [];

        foreach ($this->database->fetchAll("SHOW INDEX FROM {$table}") as $row) {
            if (strtoupper((string) $row['Index_type']) === 'FULLTEXT') {
                $names[] = (string) $row['Key_name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Recreates any missing FULLTEXT index, then has the database rebuild
     * the table, which also rebuilds its indexes without blocking reads.
     */
    private function rebuildTable(string $suffix): void
    {
        $table = $this->tablePrefix . $suffix;
        $present = $this->fulltextIndexNames($table);

        foreach (self::FULLTEXT_INDEXES as $name => $columns) {
            if (!in_array($name, $present, true)) {
                $this->database->execute("ALTER TABLE {$table} ADD FULLTEXT KEY {$name} ({$columns})");
            }
        }

        // OPTIMIZE TABLE reports failure as a result row, not an exception.
        foreach ($this->database->fetchAll("OPTIMIZE TABLE {$table}") as $row) {
            if (strtolower((string) ($row['Msg_type'] ?? '')) === 'error') {
                throw new RuntimeException('OPTIMIZE TABLE ' . $table . ': ' . (string) ($row['Msg_text'] ?? ''));
            }
        }
    }

    private function finish(): void
    {
        $this->cache?->clear();
        $this->recordRun(true);
    }

    private function recordRun(bool $ok): void
    {
        $this->config->setOption(self::LAST_RUN_OPTION, json_encode([
            'at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'ok' => $ok,
            'words' => $this->vocabulary->size(),
        ]) ?: '');
    }
}
