<?php

/**
 * Site-wide maintenance mode gate (LP-033): blocks public requests while showing an override path for logged-in staff.
 *
 * @package LumoraPress
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Http;

use DateTimeImmutable;
use Exception;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Auth;
use LumoraPress\Core\Theme\ThemeRenderer;

/**
 * Site-wide maintenance mode gate, called once from index.php between
 * BasePath stripping and Router::dispatch(). /admin/* is always exempt
 * so an Administrator can always log in and turn maintenance mode off.
 */
final class MaintenanceGate
{
    private const DEFAULT_BYPASS_CAPABILITY = 'manage_options';

    private const DEFAULT_RETRY_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly PressConfig $config,
        private readonly Auth $auth,
        private readonly ThemeRenderer $theme,
        private readonly HookManager $hooks,
    ) {
    }

    /**
     * $requestUri is the raw, BasePath-stripped request URI (may still
     * carry a query string), extracted the same way Router::dispatch() does.
     */
    public function shouldBlock(string $requestUri): bool
    {
        $path = '/' . trim((string) (parse_url($requestUri, PHP_URL_PATH) ?? '/'), '/');

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return false;
        }

        if (!$this->isActive()) {
            return false;
        }

        return !$this->bypassesFor();
    }

    /**
     * True when the manual toggle is on, or the current time falls inside
     * an optional scheduled start/end window. A malformed schedule value
     * is treated as unset (fail open) — a config typo must never
     * accidentally take the whole site offline.
     */
    public function isActive(): bool
    {
        if ($this->config->option('maintenance_mode_enabled', '0') === '1') {
            return true;
        }

        return $this->isWithinScheduledWindow();
    }

    /**
     * Whether the signed-in user (if any) bypasses an active maintenance
     * mode. Guests never bypass. Run through the maintenance_mode_bypass
     * filter so plugins can register their own bypass rules.
     */
    public function bypassesFor(): bool
    {
        $user = $this->auth->user();
        $bypasses = false;

        if ($user !== null) {
            $capability = (string) $this->config->option('maintenance_bypass_capability', self::DEFAULT_BYPASS_CAPABILITY);
            $bypasses = $capability === '' || $user->can($capability);
        }

        return (bool) $this->hooks->applyFilters('maintenance_mode_bypass', $bypasses, $user);
    }

    /**
     * Sends the 503 response and renders the theme's maintenance page.
     * Deliberately does not call exit() — index.php calls this instead of
     * dispatch(), letting the script end naturally.
     */
    public function respond(): void
    {
        http_response_code(503);
        header('X-Robots-Tag: noindex');

        $retryAfter = $this->retryAfterSeconds();

        if ($retryAfter !== null) {
            header('Retry-After: ' . $retryAfter);
        }

        $this->theme->render('maintenance.php', [
            'page_title' => (string) $this->config->option('maintenance_title', 'Maintenance'),
            'message' => (string) $this->config->option(
                'maintenance_message',
                'We are currently performing scheduled maintenance. Please check back shortly.',
            ),
            'return_at' => $this->parseOptionalDateTime('maintenance_end_at'),
        ]);
    }

    private function isWithinScheduledWindow(): bool
    {
        $start = $this->parseOptionalDateTime('maintenance_start_at');
        $end = $this->parseOptionalDateTime('maintenance_end_at');

        if ($start === null && $end === null) {
            return false;
        }

        $now = new DateTimeImmutable();

        if ($start !== null && $now < $start) {
            return false;
        }

        if ($end !== null && $now > $end) {
            return false;
        }

        return true;
    }

    /**
     * Seconds until maintenance_end_at if set and still in the future;
     * otherwise maintenance_retry_after_seconds ('0' omits the header).
     */
    private function retryAfterSeconds(): ?int
    {
        $end = $this->parseOptionalDateTime('maintenance_end_at');

        if ($end !== null) {
            $secondsUntilEnd = $end->getTimestamp() - (new DateTimeImmutable())->getTimestamp();

            if ($secondsUntilEnd > 0) {
                return $secondsUntilEnd;
            }
        }

        $configured = (int) $this->config->option('maintenance_retry_after_seconds', (string) self::DEFAULT_RETRY_AFTER_SECONDS);

        return $configured > 0 ? $configured : null;
    }

    private function parseOptionalDateTime(string $optionKey): ?DateTimeImmutable
    {
        $raw = trim((string) $this->config->option($optionKey, ''));

        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }
}
