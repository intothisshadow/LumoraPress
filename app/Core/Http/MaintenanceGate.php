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
use LumoraPress\Core\Security\CspNonce;
use LumoraPress\Core\Security\IpRangeMatcher;
use LumoraPress\Core\Theme\ThemeRenderer;
use LumoraPress\Services\MediaService;

/**
 * Site-wide maintenance mode gate, called once from index.php between
 * BasePath stripping and Router::dispatch(). /admin/* is always exempt
 * so an Administrator can always log in and turn maintenance mode off.
 */
final class MaintenanceGate
{
    private const DEFAULT_BYPASS_CAPABILITY = 'manage_options';

    private const DEFAULT_RETRY_AFTER_SECONDS = 3600;

    private const PREVIEW_TOKEN_SESSION_KEY = '_maintenance_preview_token';

    /** @var array<string, string> unsaved settings applied only while rendering a preview */
    private array $overrides = [];

    public function __construct(
        private readonly PressConfig $config,
        private readonly Auth $auth,
        private readonly ThemeRenderer $theme,
        private readonly HookManager $hooks,
        private readonly ?MediaService $media = null,
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
     * Whether the current visitor bypasses an active maintenance mode: a
     * signed-in user with the bypass capability, or anyone browsing from a
     * whitelisted IP address. Run through the maintenance_mode_bypass
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

        // REMOTE_ADDR only: a forwarded-for header is client-supplied, so
        // trusting it here would let anyone claim a whitelisted address.
        if (!$bypasses && $this->isWhitelistedIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $bypasses = true;
        }

        return (bool) $this->hooks->applyFilters('maintenance_mode_bypass', $bypasses, $user);
    }

    public function isWhitelistedIp(string $ipAddress): bool
    {
        $rules = IpRangeMatcher::parseList((string) $this->config->option('maintenance_ip_whitelist', ''));

        return $rules !== [] && IpRangeMatcher::matches($ipAddress, $rules);
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

        $this->renderPage();
    }

    /**
     * Handles the settings screen's Preview button: renders the maintenance
     * page exactly as visitors would get it, using the form's unsaved
     * values, without the 503 and without touching any saved setting.
     * Signed-in administrators only, with a session-bound token; the token
     * is reusable so a second click on Preview keeps working, which is safe
     * because nothing is changed or stored.
     *
     * @param array<string, mixed> $post
     */
    public function handlePreviewRequest(array $post): void
    {
        $user = $this->auth->user();
        $token = is_string($post['preview_token'] ?? null) ? $post['preview_token'] : '';

        if ($user === null || !$user->can('manage_options') || !hash_equals($this->previewToken(), $token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not permitted. Reload the settings page and try again.';

            return;
        }

        $this->overrides = $this->overridesFromPost($post);

        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');

        ob_start();
        $this->renderPage();
        $html = (string) ob_get_clean();

        $bar = '<div class="lp-theme-preview-bar" role="status">'
            . '<link rel="stylesheet" href="' . htmlspecialchars(admin_asset_url('css/theme-preview-bar.css'), ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="lp-theme-preview-bar__label"><strong>Preview</strong> &mdash; this is what visitors see while maintenance mode is on. Nothing here has been saved.</span>'
            . '<a class="lp-theme-preview-bar__exit" href="' . htmlspecialchars(admin_url('settings/maintenance-mode'), ENT_QUOTES, 'UTF-8') . '">Back to settings</a>'
            . '</div>';

        echo preg_replace('/(<body\b[^>]*>)/i', '$1' . $bar, $html, 1) ?? $html;
    }

    /**
     * The token the settings screen embeds in its Preview button. Created
     * once per session so repeated previews from one page load all verify.
     */
    public function previewToken(): string
    {
        if (!isset($_SESSION[self::PREVIEW_TOKEN_SESSION_KEY]) || !is_string($_SESSION[self::PREVIEW_TOKEN_SESSION_KEY])) {
            $_SESSION[self::PREVIEW_TOKEN_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::PREVIEW_TOKEN_SESSION_KEY];
    }

    /**
     * Only the keys that change how the page looks are accepted; everything
     * stays a plain string, and the same output-time validation that
     * protects saved values (hex colors, image check, style-tag stripping)
     * applies to these too.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function overridesFromPost(array $post): array
    {
        $text = static fn (string $key): string => is_string($post[$key] ?? null) ? trim($post[$key]) : '';
        $endAt = $text('maintenance_end_at');

        if ($endAt !== '') {
            try {
                $endAt = (new DateTimeImmutable($endAt))->format('Y-m-d H:i:s');
            } catch (Exception) {
                $endAt = '';
            }
        }

        return [
            'maintenance_title' => $text('maintenance_title'),
            'maintenance_message' => $text('maintenance_message'),
            'maintenance_end_at' => $endAt,
            'maintenance_show_countdown' => $text('maintenance_show_countdown') === '1' ? '1' : '0',
            'maintenance_background_color' => $text('maintenance_background_color'),
            'maintenance_text_color' => $text('maintenance_text_color'),
            'maintenance_accent_color' => $text('maintenance_accent_color'),
            'maintenance_background_media_id' => (string) max(0, (int) $text('maintenance_background_media_id')),
            'maintenance_custom_css' => $text('maintenance_custom_css'),
        ];
    }

    private function setting(string $key, string $default = ''): string
    {
        return $this->overrides[$key] ?? (string) $this->config->option($key, $default);
    }

    private function renderPage(): void
    {
        $returnAt = $this->parseOptionalDateTime('maintenance_end_at');
        $countdownTo = $this->countdownTarget($returnAt);

        $css = $this->appearanceCss();

        if ($css !== '') {
            $this->hooks->addAction('head_assets', static function () use ($css): void {
                echo '<style nonce="' . htmlspecialchars(CspNonce::value(), ENT_QUOTES, 'UTF-8') . '">' . $css . "</style>\n";
            });
        }

        if ($countdownTo !== null) {
            $this->hooks->addAction('footer_assets', static function (): void {
                echo '<script defer src="' . esc_url(core_asset_url('js/maintenance-countdown.js')) . '"></script>' . "\n";
            });
        }

        $data = [
            'page_title' => (string) $this->setting('maintenance_title', 'Maintenance'),
            'message' => $this->setting(
                'maintenance_message',
                'We are currently performing scheduled maintenance. Please check back shortly.',
            ),
            'return_at' => $returnAt,
            'countdown_to' => $countdownTo,
        ];

        $filtered = $this->hooks->applyFilters('maintenance_page_data', $data);

        $this->theme->render('maintenance.php', is_array($filtered) ? $filtered + $data : $data);
    }

    /**
     * The scheduled end time, but only when the countdown is switched on
     * and the end is still ahead — a countdown to a moment already past
     * would just sit at zero.
     */
    private function countdownTarget(?DateTimeImmutable $returnAt): ?DateTimeImmutable
    {
        if ($returnAt === null || $this->setting('maintenance_show_countdown', '0') !== '1') {
            return null;
        }

        return $returnAt > new DateTimeImmutable() ? $returnAt : null;
    }

    /**
     * CSS for the page's optional background image, colors, and custom
     * rules, printed into <head> with the request's style nonce. Colors
     * are re-validated here, not just on save, so a bad stored value can
     * never break out of the declaration.
     */
    public function appearanceCss(): string
    {
        $css = '';
        $background = $this->safeColor((string) $this->setting('maintenance_background_color', ''));
        $text = $this->safeColor((string) $this->setting('maintenance_text_color', ''));
        $accent = $this->safeColor((string) $this->setting('maintenance_accent_color', ''));
        $imageUrl = $this->backgroundImageUrl();

        // "html body[class]" outranks a theme's own `body.some-class`
        // background rule, and a chosen color clears the theme's
        // background-image so a decorative gradient can't paint over it.
        $bodySelector = 'html body,html body[class]';

        if ($background !== '' && $imageUrl === '') {
            $css .= $bodySelector . '{background-color:' . $background . ";background-image:none;}\n";
        } elseif ($background !== '') {
            $css .= $bodySelector . '{background-color:' . $background . ";}\n";
        }

        if ($imageUrl !== '') {
            $css .= $bodySelector . '{background-image:url("' . addcslashes($imageUrl, "\"\\\r\n") . '");background-size:cover;background-position:center;background-attachment:fixed;background-repeat:no-repeat;}' . "\n";
        }

        if ($text !== '') {
            $css .= '.lp-maintenance,.lp-maintenance .lp-page-title,.lp-maintenance__message,.lp-maintenance__return-at{color:' . $text . ";}\n";
        }

        if ($accent !== '') {
            $css .= '.lp-maintenance__countdown{color:' . $accent . ";}\n";
        }

        $custom = trim((string) preg_replace('/<\/style\s*>/i', '', (string) $this->setting('maintenance_custom_css', '')));

        return $custom === '' ? $css : $css . $custom . "\n";
    }

    private function backgroundImageUrl(): string
    {
        $mediaId = (int) $this->setting('maintenance_background_media_id', '0');

        if ($this->media === null || $mediaId <= 0) {
            return '';
        }

        $item = $this->media->find($mediaId);

        // The settings screen only saves images, but a preview posts raw
        // form values, so the same check is repeated here.
        if ($item === null || !str_starts_with((string) ($item['mime_type'] ?? ''), 'image/')) {
            return '';
        }

        return $this->media->url($item);
    }

    private function safeColor(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1 ? $value : '';
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
        $raw = trim($this->setting($optionKey, ''));

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
