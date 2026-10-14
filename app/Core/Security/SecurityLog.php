<?php

/**
 * Append-only log of spam- and abuse-related security events, shown on Maintenance > Logs.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

/**
 * One JSON object per line in storage/logs/security.log (a directory the
 * web server is configured never to serve).
 *
 * Deliberately records only an event name, an IP address, and a short
 * sanitized detail: never submitted form content, tokens, passwords, or
 * usernames, so the log can't itself become a store of sensitive data.
 * Control characters are stripped from every value so a hostile request
 * can't forge extra log lines. The file rotates at a fixed size so it can
 * never grow without bound, and every method fails quietly: a logging
 * problem must never break the request being logged.
 */
final class SecurityLog
{
    public const EVENT_CSRF_FAILURE = 'csrf_failure';
    public const EVENT_HONEYPOT = 'honeypot_triggered';
    public const EVENT_TOO_FAST = 'form_too_fast';
    public const EVENT_FORM_EXPIRED = 'form_expired';
    public const EVENT_COMMENT_RATE_LIMITED = 'comment_rate_limited';
    public const EVENT_LOGIN_LOCKOUT = 'login_lockout';
    public const EVENT_REGISTRATION_BLOCKED = 'registration_blocked';
    public const EVENT_REGISTRATION_RATE_LIMITED = 'registration_rate_limited';
    public const EVENT_REGISTRATION_PAUSED = 'registration_paused';

    private const FILE_NAME = 'security.log';
    private const MAX_BYTES = 524288;
    private const MAX_DETAIL_LENGTH = 200;

    public function __construct(
        private readonly string $logDirectory,
        private readonly bool $anonymizeIps = false,
    ) {
    }

    public function record(string $event, string $ipAddress, string $detail = ''): void
    {
        if (!is_dir($this->logDirectory) || !is_writable($this->logDirectory)) {
            return;
        }

        $entry = json_encode([
            'time' => date('Y-m-d H:i:s'),
            'event' => $this->sanitize($event, 40),
            'ip' => $this->sanitize($this->anonymizeIps ? IpAnonymizer::anonymize($ipAddress) : $ipAddress, 45),
            'detail' => $this->sanitize($detail, self::MAX_DETAIL_LENGTH),
        ]);

        if ($entry === false) {
            return;
        }

        $this->rotateIfNeeded();
        file_put_contents($this->path(), $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array{time: string, event: string, ip: string, detail: string}> newest first
     */
    public function recent(int $limit = 50): array
    {
        if (!is_file($this->path())) {
            return [];
        }

        // Bounded by MAX_BYTES, so reading the whole file is cheap.
        $lines = file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                continue;
            }

            $entries[] = [
                'time' => (string) ($decoded['time'] ?? ''),
                'event' => (string) ($decoded['event'] ?? ''),
                'ip' => (string) ($decoded['ip'] ?? ''),
                'detail' => (string) ($decoded['detail'] ?? ''),
            ];

            if (count($entries) >= max(1, $limit)) {
                break;
            }
        }

        return $entries;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function clear(): bool
    {
        $removed = !is_file($this->path()) || unlink($this->path());

        if (is_file($this->path() . '.1')) {
            $removed = unlink($this->path() . '.1') && $removed;
        }

        return $removed;
    }

    private function rotateIfNeeded(): void
    {
        clearstatcache(true, $this->path());

        if (is_file($this->path()) && filesize($this->path()) >= self::MAX_BYTES) {
            rename($this->path(), $this->path() . '.1');
        }
    }

    private function sanitize(string $value, int $maxLength): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';

        return mb_substr(trim($clean), 0, $maxLength);
    }

    private function path(): string
    {
        return rtrim($this->logDirectory, '/') . '/' . self::FILE_NAME;
    }
}
