<?php

/**
 * Opt-in, off-by-default anonymous install counter — sends a minimal, non-identifying ping to a Lumora-hosted endpoint.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\PressConfig;
use Throwable;

/**
 * When enabled (Settings > Privacy), sends install UUID, Lumora Press
 * version, and PHP version — nothing else — to a dedicated endpoint that
 * is completely separate from GitHubReleaseProvider's release-check
 * source, so enabling/disabling one never affects the other.
 *
 * Privacy: no domain, site title, admin email, content, or visitor data
 * is ever sent. The install UUID is a randomly generated identifier with
 * no relation to any other value this install stores; there is no way to
 * correlate it back to a specific site from the ping payload alone.
 *
 * Ping cadence: fires once immediately when the feature is enabled (see
 * admin/views/settings/privacy.php), then at most roughly monthly
 * thereafter. maybeSendPing() is called from every admin page load
 * (admin/index.php), but the network request itself is skipped unless
 * the feature is enabled AND the interval has actually elapsed — a cheap
 * option read plus a timestamp comparison on every other call.
 *
 * Failure handling: every failure mode (disabled, config write error,
 * network error) is swallowed silently by maybeSendPing() — this feature
 * must never produce a user-facing error or block any admin action. The
 * network transport is injectable so tests never make a real HTTP
 * request.
 */
final class InstallPingService
{
    /**
     * Dedicated anonymous-install-count endpoint — deliberately distinct
     * from GitHubReleaseProvider's release-check source (the GitHub
     * Releases API), so the two features stay fully independent.
     */
    private const ENDPOINT = 'https://coding.unloved-heart.net/lumorapress/install-tracking-server/ping.php';

    /** Minimum interval between pings once enabled (~30 days). */
    private const PING_INTERVAL = 2592000;

    /** HTTP request timeout in seconds. */
    private const FETCH_TIMEOUT = 5;

    /** Option key — opt-in toggle. Stored as '1'/'0'; default off. */
    private const OPT_ENABLED = 'install_ping_enabled';

    /** Option key — randomly generated install identifier. */
    private const OPT_UUID = 'install_uuid';

    /** Option key — Unix timestamp of the last ping attempt (sent or failed). */
    private const OPT_LAST_SENT_AT = 'install_ping_last_sent_at';

    /** @var callable(string, string): int */
    private $transport;

    /**
     * @param string $endpoint Overridable for tests; real callers always use the default.
     * @param null|callable(string, string): int $transport Network transport
     *     override for testing — receives the endpoint URL and the raw JSON
     *     payload, returns the HTTP status code (or throws on a
     *     transport-level failure). Defaults to a stream-context POST.
     */
    public function __construct(
        private readonly PressConfig $config,
        private readonly string $version,
        private readonly string $endpoint = self::ENDPOINT,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->defaultTransport(...);
    }

    // ── Public API ───────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return (string) $this->config->option(self::OPT_ENABLED, '0') === '1';
    }

    /**
     * Returns the persisted install UUID, generating and persisting a new
     * one on first call if none exists yet. Generated once and reused for
     * the lifetime of the installation, including across a later
     * disable/re-enable.
     */
    public function getOrCreateUuid(): string
    {
        $uuid = trim((string) $this->config->option(self::OPT_UUID, ''));

        if ($uuid !== '') {
            return $uuid;
        }

        $uuid = self::generateUuidV4();

        try {
            $this->config->setOption(self::OPT_UUID, $uuid);
        } catch (Throwable) {
            // Non-fatal — if the write failed to persist, the next call
            // simply generates (and attempts to persist) a new one.
        }

        return $uuid;
    }

    /**
     * Called on every admin page load (admin/index.php). A cheap no-op in
     * the overwhelming majority of calls: returns immediately when the
     * feature is disabled, and only performs the actual network request
     * once the ping interval has elapsed since the last attempt.
     */
    public function maybeSendPing(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $last = (int) $this->config->option(self::OPT_LAST_SENT_AT, '0');

        if ($last !== 0 && (time() - $last) < self::PING_INTERVAL) {
            return;
        }

        try {
            $this->sendPing();
        } catch (Throwable) {
            // Swallowed here — maybeSendPing() is the opportunistic,
            // every-page-load caller and must never surface a failure.
            // sendPing() itself is still called directly (and any
            // exception left to propagate) by the Settings > Privacy
            // "send a test ping now" action, which does want to know.
        }
    }

    /**
     * Performs the actual network request and records the attempt
     * timestamp regardless of outcome, so a persistently unreachable
     * endpoint is retried on the next monthly interval rather than on
     * every subsequent page load.
     *
     * Public (rather than folded into maybeSendPing()) so the Settings >
     * Privacy screen can trigger an immediate ping the moment the feature
     * is switched on, and offer a "send a test ping now" action, without
     * waiting for the next admin page load to notice the interval has
     * elapsed.
     *
     * @throws Throwable on a transport-level failure or non-2xx response
     *     — callers that only want the fire-and-forget behaviour should
     *     go through maybeSendPing() instead, which already swallows this.
     */
    public function sendPing(): void
    {
        $payload = (string) json_encode([
            'install_uuid' => $this->getOrCreateUuid(),
            'version' => $this->version,
            'php_version' => PHP_VERSION,
        ]);

        $status = null;
        $error = null;

        try {
            $status = ($this->transport)($this->endpoint, $payload);
        } catch (Throwable $exception) {
            $error = $exception;
        }

        // Recorded regardless of outcome — see class docblock.
        try {
            $this->config->setOption(self::OPT_LAST_SENT_AT, (string) time());
        } catch (Throwable) {
            // Non-fatal.
        }

        if ($error !== null) {
            throw $error;
        }

        if ($status === null || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Install ping failed with HTTP status ' . ($status ?? 0) . '.');
        }
    }

    // ── Internal ─────────────────────────────────────────────────────

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10

        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /**
     * Real network transport: a stream-context POST of the JSON payload.
     * A no-op error handler suppresses the E_WARNING PHP would otherwise
     * log for a failed connection — a network failure is an entirely
     * expected, non-exceptional outcome for a fire-and-forget ping with
     * no retry logic beyond the next scheduled interval.
     */
    private function defaultTransport(string $url, string $jsonPayload): int
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $jsonPayload,
                'timeout' => self::FETCH_TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'Lumora Press/' . $this->version . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        set_error_handler(static function (): bool {
            return true;
        });

        try {
            $result = @file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        if ($result === false && $status === 0) {
            throw new \RuntimeException('Could not connect to the install ping endpoint.');
        }

        return $status;
    }
}
