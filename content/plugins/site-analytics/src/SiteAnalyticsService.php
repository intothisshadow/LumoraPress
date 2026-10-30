<?php

/**
 * Core logic for the bundled Site Analytics plugin: settings, validation, tracking tags, and per-provider CSP hosts.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.26.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\SiteAnalytics;

use LumoraPress\Core\ActiveConfig;

/**
 * Builds the analytics tag for one chosen provider and the matching
 * Content-Security-Policy additions. Hook-agnostic (see site-analytics.php)
 * so it can be unit tested without a request.
 */
final class SiteAnalyticsService
{
    private const OPTION_KEY = 'site_analytics_settings';

    public const PROVIDER_NONE = 'none';

    public const PLAUSIBLE_DEFAULT_SCRIPT = 'https://plausible.io/js/script.js';

    public const FATHOM_SCRIPT = 'https://cdn.usefathom.com/script.js';

    private const LOADER_PATH = 'content/plugins/site-analytics/assets/analytics.js';

    /** @var array<string, string> provider key => admin label */
    public const PROVIDERS = [
        self::PROVIDER_NONE => 'None (no analytics)',
        'plausible' => 'Plausible',
        'fathom' => 'Fathom',
        'matomo' => 'Matomo',
        'ga4' => 'Google Analytics 4',
    ];

    private const DEFAULTS = [
        'provider' => self::PROVIDER_NONE,
        'plausible_domain' => '',
        'plausible_script_url' => '',
        'fathom_site_id' => '',
        'matomo_url' => '',
        'matomo_site_id' => '',
        'ga4_id' => '',
        'skip_signed_in' => true,
        'respect_dnt' => true,
        'google_verification' => '',
        'bing_verification' => '',
    ];

    private static ?self $instance = null;

    /** @var array<string, mixed>|null */
    private ?array $settingsCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array{provider: string, plausible_domain: string, plausible_script_url: string, fathom_site_id: string, matomo_url: string, matomo_site_id: string, ga4_id: string, skip_signed_in: bool, respect_dnt: bool, google_verification: string, bing_verification: string}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        // Re-run the stored values through the validator so a hand-edited
        // or corrupted option can never reach the tag or the CSP.
        $settings = $this->sanitize(is_array($decoded) ? $decoded : [], true)['settings'];

        $this->settingsCache = $settings;

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings Already validated by sanitize().
     */
    public function saveSettings(array $settings): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    /**
     * Validates submitted (or stored) values. Invalid optional fields are
     * dropped to empty; a selected provider whose required fields are
     * missing or invalid is reported and falls back to None, so a half-filled
     * form can never emit a broken tag or widen the policy.
     *
     * @param array<string, mixed> $input
     * @return array{settings: array<string, mixed>, errors: array<int, string>}
     */
    public function sanitize(array $input, bool $stored = false): array
    {
        $errors = [];
        $settings = self::DEFAULTS;

        $provider = is_string($input['provider'] ?? null) ? $input['provider'] : self::PROVIDER_NONE;
        $settings['provider'] = isset(self::PROVIDERS[$provider]) ? $provider : self::PROVIDER_NONE;

        // A stored option omits unchecked boxes only when never saved; a
        // form post omits them when unticked. Stored values keep the default.
        $settings['skip_signed_in'] = $stored ? (bool) ($input['skip_signed_in'] ?? true) : isset($input['skip_signed_in']);
        $settings['respect_dnt'] = $stored ? (bool) ($input['respect_dnt'] ?? true) : isset($input['respect_dnt']);

        $domain = strtolower(trim((string) ($input['plausible_domain'] ?? '')));

        if ($domain !== '' && preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain) !== 1) {
            $errors[] = 'Plausible domain (use a bare domain such as example.com)';
            $domain = '';
        }

        $settings['plausible_domain'] = $domain;

        $scriptUrl = trim((string) ($input['plausible_script_url'] ?? ''));

        if ($scriptUrl !== '' && $this->httpsOrigin($scriptUrl) === null) {
            $errors[] = 'Plausible script URL (must be an https:// address)';
            $scriptUrl = '';
        }

        $settings['plausible_script_url'] = $scriptUrl;

        $fathomId = strtoupper(trim((string) ($input['fathom_site_id'] ?? '')));

        if ($fathomId !== '' && preg_match('/^[A-Z0-9]{4,16}$/', $fathomId) !== 1) {
            $errors[] = 'Fathom site ID';
            $fathomId = '';
        }

        $settings['fathom_site_id'] = $fathomId;

        $matomoUrl = trim((string) ($input['matomo_url'] ?? ''));

        if ($matomoUrl !== '') {
            $normalized = $this->normalizeTrackerUrl($matomoUrl);

            if ($normalized === null) {
                $errors[] = 'Matomo tracker URL (must be an https:// address without a query string)';
            }

            $matomoUrl = $normalized ?? '';
        }

        $settings['matomo_url'] = $matomoUrl;

        $matomoSite = trim((string) ($input['matomo_site_id'] ?? ''));

        if ($matomoSite !== '' && preg_match('/^[1-9][0-9]{0,8}$/', $matomoSite) !== 1) {
            $errors[] = 'Matomo site ID (a whole number)';
            $matomoSite = '';
        }

        $settings['matomo_site_id'] = $matomoSite;

        $ga4 = strtoupper(trim((string) ($input['ga4_id'] ?? '')));

        if ($ga4 !== '' && preg_match('/^G-[A-Z0-9]{4,16}$/', $ga4) !== 1) {
            $errors[] = 'Google measurement ID (looks like G-XXXXXXXXXX)';
            $ga4 = '';
        }

        $settings['ga4_id'] = $ga4;

        foreach (['google_verification' => 'Google Search Console verification', 'bing_verification' => 'Bing verification'] as $key => $label) {
            $token = $this->extractVerificationToken((string) ($input[$key] ?? ''));

            if ($token === null) {
                $errors[] = $label . ' code';
                $token = '';
            }

            $settings[$key] = $token;
        }

        if (!$this->providerIsConfigured($settings)) {
            if ($settings['provider'] !== self::PROVIDER_NONE) {
                $errors[] = self::PROVIDERS[$settings['provider']] . ' needs its required field(s) filled in';
            }

            $settings['provider'] = self::PROVIDER_NONE;
        }

        return ['settings' => $settings, 'errors' => $errors];
    }

    /**
     * Whether $settings names a provider with every required field present.
     *
     * @param array<string, mixed> $settings
     */
    private function providerIsConfigured(array $settings): bool
    {
        return match ($settings['provider']) {
            'plausible' => $settings['plausible_domain'] !== '',
            'fathom' => $settings['fathom_site_id'] !== '',
            'matomo' => $settings['matomo_url'] !== '' && $settings['matomo_site_id'] !== '',
            'ga4' => $settings['ga4_id'] !== '',
            default => true,
        };
    }

    /**
     * Accepts a bare code or a pasted `<meta ... content="...">` tag.
     * Returns '' for empty input and null when the value isn't a plausible code.
     */
    private function extractVerificationToken(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $match) === 1) {
            $raw = $match[1];
        }

        return preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $raw) === 1 ? $raw : null;
    }

    /**
     * `https://host[:port]` for a valid https URL, else null. Only https is
     * ever allowed so a mis-typed http:// host can't be added to the policy.
     */
    private function httpsOrigin(string $url): ?string
    {
        if (preg_match('/\s/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        return 'https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** Matomo's base address with a trailing slash; a query or fragment is rejected. */
    private function normalizeTrackerUrl(string $url): ?string
    {
        $origin = $this->httpsOrigin($url);

        if ($origin === null || str_contains($url, '?') || str_contains($url, '#')) {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if ($path !== '' && preg_match('#^/[A-Za-z0-9._~\-/]*$#', $path) !== 1) {
            return null;
        }

        return $origin . rtrim($path, '/') . '/';
    }

    public function isActive(): bool
    {
        return $this->settings()['provider'] !== self::PROVIDER_NONE;
    }

    /**
     * Whether this request may be counted. `$relativePath` is the request
     * path with the install's base path already removed.
     */
    public function shouldTrack(bool $signedIn, bool $maintenancePage, string $relativePath): bool
    {
        $settings = $this->settings();

        if ($settings['provider'] === self::PROVIDER_NONE) {
            return false;
        }

        if ($settings['skip_signed_in'] && $signedIn) {
            return false;
        }

        if ($maintenancePage) {
            return false;
        }

        return preg_match('#^/(admin|preview|preview-page)(/|$)#', '/' . ltrim($relativePath, '/')) !== 1;
    }

    /**
     * The `<script>` tag for the chosen provider, or '' for None.
     *
     * Plausible and Fathom use their own tag directly unless Do Not Track
     * is honored, in which case everything goes through the plugin's loader
     * so the browser-side signal is read before any provider code loads
     * (a server-side check would be baked into cached pages).
     */
    public function tag(string $loaderUrl): string
    {
        $settings = $this->settings();
        $provider = $settings['provider'];

        if ($provider === self::PROVIDER_NONE) {
            return '';
        }

        if (!$settings['respect_dnt']) {
            if ($provider === 'plausible') {
                return '<script defer data-domain="' . esc_attr($settings['plausible_domain']) . '" src="' . esc_url($this->plausibleScriptUrl()) . '"></script>' . "\n";
            }

            if ($provider === 'fathom') {
                return '<script defer data-site="' . esc_attr($settings['fathom_site_id']) . '" src="' . esc_url(self::FATHOM_SCRIPT) . '"></script>' . "\n";
            }
        }

        $attributes = [
            'data-lp-analytics' => $provider,
            'data-respect-dnt' => $settings['respect_dnt'] ? '1' : '0',
        ];

        $attributes += match ($provider) {
            'plausible' => ['data-domain' => $settings['plausible_domain'], 'data-script-url' => $this->plausibleScriptUrl()],
            'fathom' => ['data-site-id' => $settings['fathom_site_id'], 'data-script-url' => self::FATHOM_SCRIPT],
            'matomo' => ['data-tracker-url' => $settings['matomo_url'], 'data-site-id' => $settings['matomo_site_id']],
            default => ['data-measurement-id' => $settings['ga4_id']],
        };

        $html = '<script defer src="' . esc_url($loaderUrl) . '"';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . esc_attr($value) . '"';
        }

        return $html . '></script>' . "\n";
    }

    public function plausibleScriptUrl(): string
    {
        $custom = $this->settings()['plausible_script_url'];

        return $custom !== '' ? $custom : self::PLAUSIBLE_DEFAULT_SCRIPT;
    }

    /** Public URL of the plugin-owned loader, cache-busted by file mtime. */
    public function loaderUrl(): string
    {
        $path = dirname(__DIR__) . '/assets/analytics.js';
        $mtime = is_file($path) ? filemtime($path) : false;

        return site_url(self::LOADER_PATH) . ($mtime !== false ? '?v=' . $mtime : '');
    }

    /**
     * The verification `<meta>` tags. Independent of the provider and of
     * the skip rules: a search engine's crawler is never signed in.
     */
    public function verificationTags(): string
    {
        $settings = $this->settings();
        $html = '';

        if ($settings['google_verification'] !== '') {
            $html .= '<meta name="google-site-verification" content="' . esc_attr($settings['google_verification']) . '">' . "\n";
        }

        if ($settings['bing_verification'] !== '') {
            $html .= '<meta name="msvalidate.01" content="' . esc_attr($settings['bing_verification']) . '">' . "\n";
        }

        return $html;
    }

    /**
     * Hosts the policy must allow for the selected provider, keyed by CSP
     * directive. Empty for None. Shown read-only on the settings screen.
     *
     * @return array<string, array<int, string>>
     */
    public function cspHosts(): array
    {
        $settings = $this->settings();

        switch ($settings['provider']) {
            case 'plausible':
                $origin = $this->httpsOrigin($this->plausibleScriptUrl());

                return $origin === null ? [] : ['script-src' => [$origin], 'connect-src' => [$origin]];

            case 'fathom':
                return ['script-src' => ['https://cdn.usefathom.com'], 'connect-src' => ['https://cdn.usefathom.com']];

            case 'matomo':
                $origin = $this->httpsOrigin($settings['matomo_url']);

                return $origin === null ? [] : ['script-src' => [$origin], 'connect-src' => [$origin], 'img-src' => [$origin]];

            case 'ga4':
                return [
                    'script-src' => ['https://www.googletagmanager.com'],
                    'connect-src' => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'],
                    'img-src' => ['https://*.google-analytics.com', 'https://*.googletagmanager.com'],
                ];

            default:
                return [];
        }
    }

    /**
     * @param array<string, string> $directives
     * @return array<string, string>
     */
    public function filterCsp(array $directives): array
    {
        foreach ($this->cspHosts() as $directive => $hosts) {
            $existing = $directives[$directive] ?? "'self'";

            foreach ($hosts as $host) {
                if (!in_array($host, explode(' ', $existing), true)) {
                    $existing .= ' ' . $host;
                }
            }

            $directives[$directive] = $existing;
        }

        return $directives;
    }
}
