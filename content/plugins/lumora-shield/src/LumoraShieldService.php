<?php

/**
 * Core logic for the bundled Lumora Shield plugin (LPP-001): settings, Stop User Enumeration, Monitoring, and Comment Analysis.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraShield;

use LumoraPress\Core\ActiveConfig;
use LumoraPress\Core\ActiveKernel;

/**
 * First modules built for the much larger LPP-001 Lumora Shield ticket:
 * Stop User Enumeration, Monitoring, and Comment Analysis.
 *
 * Stop User Enumeration: a 2026-08-28 re-audit of the ticket's own
 * "nothing to protect yet" note found one real gap since introduced —
 * `/author/{slug}` (LP-008) returned 200 for any real username (even one
 * with zero published posts) and 404 for a made-up one, a plain
 * username-existence oracle. The zero-published-posts half of that fix
 * has no real tradeoff, so it lives unconditionally in
 * `SiteController::author()` itself; this plugin's only remaining job
 * there is the one genuine optional tradeoff — hiding every author
 * archive outright, including real ones with published posts.
 *
 * Monitoring: records every 'lumora_shield_enumeration_blocked' event
 * `SiteController::author()` fires (a no-op unless something listens),
 * so an administrator can see who's probing for valid usernames. No
 * cron/scheduler exists anywhere in this codebase, so retention cleanup
 * runs probabilistically on write rather than on a schedule — the same
 * shape PHP's own session garbage collector uses.
 *
 * Comment Analysis: a set of independent content/behavioral heuristics
 * (see CommentAnalyzer) that push a comment toward Spam via the existing
 * 'comment_is_spam' filter, the same one Akismet already uses.
 *
 * Everything else in the ticket's User Enumeration Protection checklist
 * remains N/A per the 2026-08-28 audit (no XML-RPC, no REST user
 * endpoint, generic login/password-reset responses already exist in
 * core) — deliberately not built speculatively against surface area
 * that doesn't exist yet.
 */
final class LumoraShieldService
{
    private const OPTION_KEY = 'lumora_shield_settings';

    /** How old an enumeration_attempts row must be (in days) before cleanup() deletes it — enforced only when a cleanup roll actually fires, see recordEnumerationAttempt(). */
    private const CLEANUP_CHANCE = 20;

    /** Notify at most once per IP per this many seconds, even if the threshold keeps getting crossed. */
    private const NOTIFY_COOLDOWN_SECONDS = 3600;

    private static ?self $instance = null;

    /** @var array{hide_author_archives: bool, enable_logging: bool, log_retention_days: int, notify_on_repeated_attempts: bool, notify_threshold: int, enable_comment_analysis: bool}|null */
    private ?array $settingsCache = null;

    /** @var array<string, int> in-request-only, so a burst of attempts from one IP within a single process never emails more than once regardless of NOTIFY_COOLDOWN_SECONDS — the real cross-request cooldown is enforced by countEnumerationAttemptsForIp()'s own window query. */
    private array $notifiedIpsThisRequest = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array{hide_author_archives: bool, enable_logging: bool, log_retention_days: int, notify_on_repeated_attempts: bool, notify_threshold: int, enable_comment_analysis: bool}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        $defaults = [
            // Off by default: this removes a real public feature (browsing
            // everything by an author), unlike the zero-post case core
            // already handles unconditionally — an explicit opt-in, not a
            // secure-by-default posture, is the right call here.
            'hide_author_archives' => false,
            'enable_logging' => true,
            'log_retention_days' => 30,
            'notify_on_repeated_attempts' => false,
            'notify_threshold' => 10,
            'enable_comment_analysis' => true,
        ];

        $settings = array_merge($defaults, is_array($decoded) ? $decoded : []);

        $this->settingsCache = [
            'hide_author_archives' => (bool) $settings['hide_author_archives'],
            'enable_logging' => (bool) $settings['enable_logging'],
            'log_retention_days' => max(1, (int) $settings['log_retention_days']),
            'notify_on_repeated_attempts' => (bool) $settings['notify_on_repeated_attempts'],
            'notify_threshold' => max(1, (int) $settings['notify_threshold']),
            'enable_comment_analysis' => (bool) $settings['enable_comment_analysis'],
        ];

        return $this->settingsCache;
    }

    /**
     * @param array{hide_author_archives: bool, enable_logging: bool, log_retention_days: int, notify_on_repeated_attempts: bool, notify_threshold: int, enable_comment_analysis: bool} $settings
     */
    public function saveSettings(array $settings): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    /**
     * The `lumora_shield_author_archive_visible` filter listener
     * (registered in lumora-shield.php). $default is whatever core would
     * otherwise decide by the time this runs — SiteController::author()
     * has already 404'd a zero-published-post author unconditionally, so
     * $default is always true here — passed through unchanged unless
     * "hide author archives entirely" is on, so disabling the plugin
     * restores exact core-only behavior. $publishedPostCount isn't
     * needed by this module's own logic (core's own check already used
     * it), but stays part of the filter's contract for any other
     * listener that might want it.
     */
    public function authorArchiveVisible(bool $default, int $publishedPostCount): bool
    {
        if ($this->settings()['hide_author_archives']) {
            return false;
        }

        return $default;
    }

    /**
     * The `comment_is_spam` filter listener (registered in
     * lumora-shield.php). Can only push toward Spam, never un-spam a
     * comment another listener (Akismet) already flagged — matches that
     * filter's own contract. The filter's signature carries no user ID
     * (see CommentAnalyzer's own docblock), so behavioral checks that
     * would otherwise prefer a logged-in user's ID fall back to guest
     * email only, same as every other caller of this filter sees.
     */
    public function commentIsSpam(bool $default, string $guestName, string $guestEmail, ?string $guestUrl, string $content, string $ipAddress): bool
    {
        if ($default || !$this->settings()['enable_comment_analysis']) {
            return $default;
        }

        return (new CommentAnalyzer())->analyze($content, null, $guestEmail !== '' ? $guestEmail : null, $ipAddress)['isSpam'];
    }

    /**
     * The `lumora_shield_enumeration_blocked` action listener (registered
     * in lumora-shield.php). Best-effort throughout — a logging failure
     * must never break the public author-archive request that triggered
     * it, mirroring LoginThrottle::recordFailure()'s identical
     * fail-silent stance.
     */
    public function recordEnumerationAttempt(string $slug, string $reason, string $ipAddress): void
    {
        $settings = $this->settings();

        if (!$settings['enable_logging']) {
            return;
        }

        try {
            $kernel = ActiveKernel::instance();
            $kernel->database->execute(
                'INSERT INTO ' . $this->table() . ' (ip_address, requested_slug, reason, attempted_at) VALUES (:ip_address, :requested_slug, :reason, :attempted_at)',
                [
                    'ip_address' => $ipAddress,
                    'requested_slug' => $slug,
                    'reason' => $reason,
                    'attempted_at' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable) {
            return;
        }

        // No cron/scheduler exists anywhere in this codebase — retention
        // cleanup runs probabilistically on write instead, the same
        // shape PHP's own session garbage collector uses, rather than
        // paying a DELETE's cost on every single insert.
        if (random_int(1, self::CLEANUP_CHANCE) === 1) {
            $this->cleanupOldEnumerationAttempts($settings['log_retention_days']);
        }

        if ($settings['notify_on_repeated_attempts']) {
            $this->maybeNotifyRepeatedAttempts($ipAddress, $settings['notify_threshold']);
        }
    }

    /**
     * @return array<int, array{ipAddress: string, requestedSlug: string, reason: string, attemptedAt: string}>
     */
    public function recentEnumerationAttempts(int $limit = 50): array
    {
        try {
            $rows = ActiveKernel::instance()->database->fetchAll(
                'SELECT ip_address, requested_slug, reason, attempted_at FROM ' . $this->table()
                    . ' ORDER BY attempted_at DESC LIMIT ' . max(1, $limit),
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'ipAddress' => (string) $row['ip_address'],
            'requestedSlug' => (string) $row['requested_slug'],
            'reason' => (string) $row['reason'],
            'attemptedAt' => (string) $row['attempted_at'],
        ], $rows);
    }

    public function countEnumerationAttemptsForIp(string $ipAddress, int $windowSeconds): int
    {
        try {
            return (int) ActiveKernel::instance()->database->fetchColumn(
                'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE ip_address = :ip_address AND attempted_at >= :window_start',
                ['ip_address' => $ipAddress, 'window_start' => date('Y-m-d H:i:s', time() - $windowSeconds)],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function cleanupOldEnumerationAttempts(int $retentionDays): void
    {
        try {
            ActiveKernel::instance()->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE attempted_at < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - ($retentionDays * 86400))],
            );
        } catch (\Throwable) {
            // Best-effort — a failed cleanup just means rows accumulate
            // until the next probabilistic roll succeeds.
        }
    }

    private function maybeNotifyRepeatedAttempts(string $ipAddress, int $threshold): void
    {
        if (isset($this->notifiedIpsThisRequest[$ipAddress])) {
            return;
        }

        // Cross-request cooldown: only the *first* request in each
        // cooldown window that crosses the threshold actually emails —
        // countEnumerationAttemptsForIp() over NOTIFY_COOLDOWN_SECONDS
        // stays at/above the threshold for the rest of that window, so
        // checking "exactly at the threshold" (not "at least") is what
        // keeps this a one-time notification per burst rather than one
        // per attempt after the threshold is crossed.
        if ($this->countEnumerationAttemptsForIp($ipAddress, self::NOTIFY_COOLDOWN_SECONDS) !== $threshold) {
            return;
        }

        $this->notifiedIpsThisRequest[$ipAddress] = 1;

        try {
            $kernel = ActiveKernel::instance();
            $adminEmail = trim((string) $kernel->config->option('admin_email', ''));

            if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
                return;
            }

            $kernel->mailer->send(
                $adminEmail,
                'Repeated username-enumeration attempts detected',
                "IP address {$ipAddress} has triggered {$threshold} blocked author-archive requests in the past hour. "
                    . 'Review Lumora Shield → Settings for recent activity.',
            );
        } catch (\Throwable) {
            // Best-effort — a failed notification must never break the
            // request that triggered it.
        }
    }

    private function table(): string
    {
        $prefix = (string) ActiveKernel::instance()->config->get('table_prefix', 'lp_');

        return $prefix . 'enumeration_attempts';
    }
}
