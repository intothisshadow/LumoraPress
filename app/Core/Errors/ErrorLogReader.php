<?php

/**
 * Reads and parses storage/logs/error.log entries for the Maintenance > Logs admin screen.
 *
 * @package LumoraPress
 * @subpackage Errors
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Errors;

/**
 * Reads ErrorHandler::log()'s own on-disk format
 * ("[timestamp] ExceptionClass: message in file:line\ntrace\n\n") back into
 * structured rows, newest first.
 *
 * Reads from the end of the file in fixed-size chunks rather than loading
 * it entirely into memory — an unrotated error.log has no size cap and can
 * grow large over a site's lifetime (see storage/sessions/'s own
 * unbounded-growth precedent noted in this project's housekeeping rules).
 */
final class ErrorLogReader
{
    private const CHUNK_SIZE = 65536;

    public function __construct(
        private readonly string $logDirectory,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function fileSize(): int
    {
        return $this->exists() ? (int) filesize($this->path()) : 0;
    }

    /**
     * Approximate — each entry contributes exactly one "\n\n" terminator,
     * so this counts delimiters via bounded-memory chunked reads rather
     * than parsing every entry just to count them.
     */
    public function totalEntries(): int
    {
        if (!$this->exists()) {
            return 0;
        }

        $handle = fopen($this->path(), 'rb');

        if ($handle === false) {
            return 0;
        }

        try {
            $count = 0;
            $carry = '';

            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false) {
                    break;
                }

                $combined = $carry . $chunk;
                $count += substr_count($combined, "\n\n");
                // A "\n\n" delimiter can straddle a chunk boundary; keep
                // the trailing byte so the next iteration can still see it.
                $carry = substr($combined, -1);
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int, array{
     *     timestamp: ?string,
     *     exception_class: ?string,
     *     message: string,
     *     file: ?string,
     *     line: ?int,
     *     trace: string,
     *     raw: string,
     * }>
     */
    public function read(int $limit = 50, int $offset = 0): array
    {
        if (!$this->exists()) {
            return [];
        }

        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $needed = $offset + $limit;

        $handle = fopen($this->path(), 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $position = filesize($this->path()) ?: 0;
            $buffer = '';
            $entries = [];

            while ($position > 0 && count($entries) < $needed) {
                $readSize = min(self::CHUNK_SIZE, $position);
                $position -= $readSize;
                fseek($handle, $position);
                $buffer = fread($handle, $readSize) . $buffer;

                $parts = explode("\n\n", $buffer);

                // The first part may be a partial entry cut off at this
                // chunk's leading edge — hold it in $buffer so the next
                // (earlier) chunk can complete it, unless we've now read
                // all the way back to the start of the file, in which
                // case it's genuinely the oldest entry.
                $buffer = $position > 0 ? array_shift($parts) : '';

                foreach (array_reverse($parts) as $part) {
                    $part = trim($part, "\n");

                    if ($part === '') {
                        continue;
                    }

                    $entries[] = $part;

                    if (count($entries) >= $needed) {
                        break;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $entries = array_slice($entries, $offset, $limit);

        return array_map($this->parseEntry(...), $entries);
    }

    /**
     * CSRF-protected and manage_options-gated at the call site (the admin
     * view). No rotation/archival on purpose — matches this project's
     * "lightweight" philosophy; a site that wants retention can configure
     * that at the server level.
     */
    public function clear(): bool
    {
        if (!$this->exists()) {
            return true;
        }

        if (!is_writable($this->path())) {
            return false;
        }

        return file_put_contents($this->path(), '') !== false;
    }

    private function path(): string
    {
        return rtrim($this->logDirectory, '/') . '/error.log';
    }

    /**
     * @return array{
     *     timestamp: ?string,
     *     exception_class: ?string,
     *     message: string,
     *     file: ?string,
     *     line: ?int,
     *     trace: string,
     *     raw: string,
     * }
     */
    private function parseEntry(string $raw): array
    {
        $raw = trim($raw, "\n");
        [$header, $trace] = array_pad(explode("\n", $raw, 2), 2, '');

        $timestamp = null;
        $exceptionClass = null;
        $message = $header;
        $file = null;
        $line = null;

        if (preg_match('/^\[(?<timestamp>[^\]]+)]\s+(?<class>[^:]+):\s+(?<rest>.*)$/', $header, $match)) {
            $timestamp = $match['timestamp'];
            $exceptionClass = $match['class'];
            $message = $match['rest'];

            if (preg_match('/^(?<message>.*) in (?<file>.+):(?<line>\d+)$/', $match['rest'], $locationMatch)) {
                $message = $locationMatch['message'];
                $file = $locationMatch['file'];
                $line = (int) $locationMatch['line'];
            }
        }

        return [
            'timestamp' => $timestamp,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'trace' => $trace,
            'raw' => $raw,
        ];
    }
}
