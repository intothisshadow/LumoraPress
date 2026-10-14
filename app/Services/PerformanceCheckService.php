<?php

/**
 * Inspects the server and site settings and reports what could make the site faster.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\PressConfig;

/**
 * Every check is read-only and cheap, so the screen can run them on each visit. The server
 * facts are passed in rather than read from globals so the checks can be exercised with any
 * combination of settings.
 */
final class PerformanceCheckService
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_INFO = 'info';

    /**
     * @param array<string, mixed> $server the $_SERVER array
     * @param array<string, string|false> $ini ini_get() values keyed by setting name
     * @param array<int, string> $extensions loaded PHP extensions, lower-cased
     * @param array<int, string> $apacheModules loaded Apache modules, when PHP can list them
     * @param array<int, string> $trustedProxies entries from the trusted proxy setting
     * @param bool $debugMode the `debug` key from config.php, which lives outside the options table
     */
    public function __construct(
        private readonly PressConfig $config,
        private readonly array $server,
        private readonly array $ini,
        private readonly array $extensions,
        private readonly array $apacheModules,
        private readonly bool $webpSupported,
        private readonly string $cacheDriver,
        private readonly string $siteUrl,
        private readonly array $trustedProxies,
        private readonly bool $debugMode,
    ) {
    }

    /**
     * @return array<int, array{id: string, label: string, status: string, detail: string, recommendation: string}>
     */
    public function checks(): array
    {
        return [
            $this->opcache(),
            $this->compression(),
            $this->memoryLimit(),
            $this->https(),
            $this->httpCaching(),
            $this->deferScripts(),
            $this->localFonts(),
            $this->webp(),
            $this->proxy(),
            $this->debugMode(),
        ];
    }

    /**
     * Only the checks that found something worth acting on.
     *
     * @return array<int, array{id: string, label: string, status: string, detail: string, recommendation: string}>
     */
    public function recommendations(): array
    {
        return array_values(array_filter(
            $this->checks(),
            static fn (array $check): bool => $check['recommendation'] !== '',
        ));
    }

    /**
     * Understands the shorthand PHP uses for memory sizes ("128M", "1G"); -1 means unlimited.
     */
    public static function bytesFromIni(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function check(string $id, string $label, string $status, string $detail, string $recommendation = ''): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail, 'recommendation' => $recommendation];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function opcache(): array
    {
        $available = in_array('zend opcache', $this->extensions, true);
        $enabled = $available && in_array(strtolower((string) ($this->ini['opcache.enable'] ?? '')), ['1', 'on', 'true', 'yes'], true);

        if ($enabled) {
            return $this->check('opcache', 'OPcache', self::STATUS_OK, 'Enabled. PHP keeps compiled code in memory between requests.');
        }

        return $this->check(
            'opcache',
            'OPcache',
            self::STATUS_WARNING,
            $available ? 'Installed but switched off.' : 'Not available.',
            'Turn on OPcache in your hosting control panel or php.ini (opcache.enable=1). It is the single biggest speed-up for PHP sites.',
        );
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function compression(): array
    {
        $zlib = in_array(strtolower((string) ($this->ini['zlib.output_compression'] ?? '')), ['1', 'on', 'true', 'yes'], true);
        $deflate = in_array('mod_deflate', array_map('strtolower', $this->apacheModules), true);

        if ($zlib || $deflate) {
            return $this->check('compression', 'Text compression', self::STATUS_OK, $deflate ? 'Apache mod_deflate is loaded.' : 'PHP output compression is on.');
        }

        // Compression is often done by the web server or a proxy in front of PHP, which PHP cannot see.
        return $this->check(
            'compression',
            'Text compression',
            self::STATUS_INFO,
            'Could not be detected from PHP.',
            'Check that your host compresses HTML, CSS, and JavaScript with gzip or Brotli. Browser developer tools show a Content-Encoding header when it is working.',
        );
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function memoryLimit(): array
    {
        $limit = self::bytesFromIni((string) ($this->ini['memory_limit'] ?? ''));
        $label = (string) ($this->ini['memory_limit'] ?? 'unknown');

        if ($limit === -1 || $limit >= 128 * 1024 ** 2) {
            return $this->check('memory', 'PHP memory limit', self::STATUS_OK, $label . '.');
        }

        return $this->check(
            'memory',
            'PHP memory limit',
            self::STATUS_WARNING,
            $label . '.',
            'Raise memory_limit to at least 128M. Thumbnail generation and imports need headroom with large images.',
        );
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function https(): array
    {
        if (str_starts_with(strtolower($this->siteUrl), 'https://')) {
            return $this->check('https', 'HTTPS', self::STATUS_OK, 'The site address uses HTTPS, which also lets browsers use HTTP/2.');
        }

        return $this->check(
            'https',
            'HTTPS',
            self::STATUS_WARNING,
            'The site address is plain HTTP.',
            'Serve the site over HTTPS. Browsers only use the faster HTTP/2 protocol on secure connections.',
        );
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function httpCaching(): array
    {
        if ($this->config->option('cache_enabled', '1') === '0') {
            return $this->check('http-caching', 'HTTP caching', self::STATUS_WARNING, 'Switched off.', 'Turn HTTP caching back on under Settings › Cache so visitors and CDNs can reuse pages.');
        }

        $driver = $this->cacheDriver === 'null' ? 'cache headers only' : $this->cacheDriver . ' driver active';

        return $this->check('http-caching', 'HTTP caching', self::STATUS_OK, 'On (' . $driver . ').');
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function deferScripts(): array
    {
        if ($this->config->option('perf_defer_js', '1') !== '0') {
            return $this->check('defer-js', 'Deferred scripts', self::STATUS_OK, 'Scripts load without blocking the page.');
        }

        return $this->check('defer-js', 'Deferred scripts', self::STATUS_INFO, 'Switched off.', 'Turn on "Defer scripts" so JavaScript no longer delays the first paint.');
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function localFonts(): array
    {
        if ($this->config->option('perf_font_local', '0') === '1') {
            return $this->check('local-fonts', 'Font hosting', self::STATUS_OK, 'Fonts are served from this site where a local copy exists.');
        }

        return $this->check(
            'local-fonts',
            'Font hosting',
            self::STATUS_INFO,
            'Fonts load from Google.',
            'Host fonts locally to remove two third-party connections per page and keep visitors\' IP addresses away from Google.',
        );
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function webp(): array
    {
        $enabled = $this->config->option('thumbnail_webp', '0') === '1';

        if ($enabled && !$this->webpSupported) {
            return $this->check('webp', 'WebP thumbnails', self::STATUS_WARNING, 'Switched on, but this PHP build cannot write WebP.', 'Ask your host to enable WebP support in PHP\'s GD extension, or switch the option off.');
        }

        if ($enabled) {
            return $this->check('webp', 'WebP thumbnails', self::STATUS_OK, 'New thumbnails of JPEG and PNG images are saved as WebP.');
        }

        if ($this->webpSupported) {
            return $this->check('webp', 'WebP thumbnails', self::STATUS_INFO, 'Available but switched off.', 'Turn on WebP thumbnails under Media › Thumbnails for smaller image downloads.');
        }

        return $this->check('webp', 'WebP thumbnails', self::STATUS_INFO, 'Not supported by this PHP build.');
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function proxy(): array
    {
        $hasForwardedHeader = ($this->server['HTTP_X_FORWARDED_FOR'] ?? '') !== '' || ($this->server['HTTP_CF_CONNECTING_IP'] ?? '') !== '';

        if ($hasForwardedHeader && $this->trustedProxies === []) {
            return $this->check(
                'proxy',
                'Reverse proxy',
                self::STATUS_WARNING,
                'Requests arrive with forwarded-address headers, but no proxy is trusted.',
                'If a proxy or CDN sits in front of this site, add its address under Reverse Proxy so visitors are not all seen as one IP address.',
            );
        }

        return $this->check('proxy', 'Reverse proxy', self::STATUS_OK, $this->trustedProxies === [] ? 'No proxy detected.' : count($this->trustedProxies) . ' trusted address' . (count($this->trustedProxies) === 1 ? '' : 'es') . ' configured.');
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    private function debugMode(): array
    {
        if ($this->debugMode) {
            return $this->check('debug', 'Debug mode', self::STATUS_WARNING, 'On.', 'Turn debug mode off in config/config.php on a live site; it adds overhead and can expose details.');
        }

        return $this->check('debug', 'Debug mode', self::STATUS_OK, 'Off.');
    }
}
