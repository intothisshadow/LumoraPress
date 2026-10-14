<?php

/**
 * Downloads Google Fonts stylesheets and their font files so the site can serve them itself.
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

use Closure;
use LumoraPress\Core\Errors\QuietCall;
use LumoraPress\Core\PressConfig;

/**
 * Hosting fonts locally keeps visitors' IP addresses away from Google and removes two
 * third-party connections from every page load. Downloads only ever happen when an
 * administrator asks for them, never during a public request.
 *
 * Google serves different stylesheets depending on the browser, so the request pretends to
 * be a current Chrome to get woff2 files, the one format every supported browser reads.
 */
final class LocalFontService
{
    public const DISPLAY_VALUES = ['swap', 'fallback', 'optional', 'block', 'auto'];

    private const MANIFEST_OPTION = 'font_local_manifest';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const MAX_FILES = 40;

    private const MAX_FILE_BYTES = 1_500_000;

    private const TIMEOUT_SECONDS = 20;

    private const PRELOAD_LIMIT = 2;

    private readonly Closure $httpGet;

    /**
     * @param (Closure(string $url, array<int, string> $headers, int $timeoutSeconds): (string|null))|null $httpGet
     *     Injectable for tests; production code never passes this.
     */
    public function __construct(
        private readonly PressConfig $config,
        private readonly string $fontsPath,
        private readonly string $fontsUrl,
        ?Closure $httpGet = null,
    ) {
        $this->httpGet = $httpGet ?? $this->defaultHttpGet(...);
    }

    public static function isGoogleFontsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && ($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === 'fonts.googleapis.com'
            && !isset($parts['user'])
            && !isset($parts['port'])
            && in_array($parts['path'] ?? '', ['/css', '/css2'], true);
    }

    public static function normalizeDisplay(string $display): string
    {
        return in_array($display, self::DISPLAY_VALUES, true) ? $display : 'swap';
    }

    /**
     * Sets the `display` query parameter without re-encoding the rest of the URL, since
     * Google's `family=Name:wght@400;700` syntax must keep its literal `:`, `@` and `;`.
     */
    public static function withDisplay(string $url, string $display): string
    {
        $display = self::normalizeDisplay($display);

        if (preg_match('/([?&])display=[^&#]*/', $url) === 1) {
            return (string) preg_replace('/([?&])display=[^&#]*/', '${1}display=' . $display, $url);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'display=' . $display;
    }

    /**
     * Identifies a stylesheet regardless of its `display` value, so changing the
     * font-display setting never orphans the downloaded copy.
     */
    public static function hashFor(string $url): string
    {
        $bare = (string) preg_replace('/([?&])display=[^&#]*&?/', '${1}', $url);

        return sha1(rtrim($bare, '?&'));
    }

    /**
     * @return array{css: string, preload: array<int, string>}|null URLs relative to the site root
     */
    public function localFor(string $url): ?array
    {
        $entry = $this->manifest()[self::hashFor($url)] ?? null;

        if (!is_array($entry) || !is_file($this->fontsPath . '/' . self::hashFor($url) . '/fonts.css')) {
            return null;
        }

        $base = rtrim($this->fontsUrl, '/') . '/' . self::hashFor($url);

        return [
            'css' => $base . '/fonts.css?v=' . (int) ($entry['updated'] ?? 0),
            'preload' => array_map(static fn (string $file): string => $base . '/' . $file, array_values(array_filter((array) ($entry['preload'] ?? []), 'is_string'))),
        ];
    }

    /**
     * @return array<string, array{url: string, files: int, updated: int}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->manifest() as $hash => $entry) {
            if (is_array($entry) && is_string($entry['url'] ?? null)) {
                $entries[(string) $hash] = [
                    'url' => $entry['url'],
                    'files' => (int) ($entry['files'] ?? 0),
                    'updated' => (int) ($entry['updated'] ?? 0),
                ];
            }
        }

        return $entries;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function download(string $url, string $display): array
    {
        if (!self::isGoogleFontsUrl($url)) {
            return ['ok' => false, 'message' => 'Only https://fonts.googleapis.com/css or /css2 stylesheet addresses can be hosted locally.'];
        }

        $display = self::normalizeDisplay($display);
        $css = ($this->httpGet)(self::withDisplay($url, $display), ['User-Agent: ' . self::USER_AGENT, 'Accept: text/css,*/*;q=0.1'], self::TIMEOUT_SECONDS);

        if ($css === null || !str_contains($css, '@font-face')) {
            return ['ok' => false, 'message' => 'The font stylesheet could not be downloaded. Check the address, and that this server can make outgoing HTTPS requests.'];
        }

        preg_match_all('~url\((https://fonts\.gstatic\.com/[^)\s\'"]+)\)~', $css, $matches);
        $fontUrls = array_values(array_unique($matches[1]));

        if ($fontUrls === [] || count($fontUrls) > self::MAX_FILES) {
            return ['ok' => false, 'message' => $fontUrls === [] ? 'The stylesheet did not list any font files.' : 'The stylesheet lists too many font files to host locally.'];
        }

        $hash = self::hashFor($url);
        $directory = $this->fontsPath . '/' . $hash;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return ['ok' => false, 'message' => 'The fonts folder could not be created. Check that the uploads folder is writable.'];
        }

        $written = [];

        foreach ($fontUrls as $fontUrl) {
            $bytes = ($this->httpGet)($fontUrl, ['User-Agent: ' . self::USER_AGENT], self::TIMEOUT_SECONDS);

            // The wOF2 signature check keeps an error page or redirect body from being stored as a font.
            if ($bytes === null || strlen($bytes) > self::MAX_FILE_BYTES || !str_starts_with($bytes, 'wOF2')) {
                $this->deleteDirectory($directory);

                return ['ok' => false, 'message' => 'A font file could not be downloaded, so nothing was changed.'];
            }

            $name = substr(sha1($fontUrl), 0, 16) . '.woff2';

            if (file_put_contents($directory . '/' . $name, $bytes, LOCK_EX) === false) {
                $this->deleteDirectory($directory);

                return ['ok' => false, 'message' => 'A font file could not be saved. Check that the uploads folder is writable.'];
            }

            $written[$fontUrl] = $name;
            $css = str_replace($fontUrl, $name, $css);
        }

        $css = self::setFontDisplay($css, $display);

        if (file_put_contents($directory . '/fonts.css', $css, LOCK_EX) === false) {
            $this->deleteDirectory($directory);

            return ['ok' => false, 'message' => 'The stylesheet could not be saved. Check that the uploads folder is writable.'];
        }

        $manifest = $this->manifest();
        $manifest[$hash] = [
            'url' => $url,
            'files' => count($written),
            'preload' => $this->preloadCandidates($css),
            'updated' => time(),
        ];
        $this->saveManifest($manifest);

        return ['ok' => true, 'message' => 'Hosting ' . count($written) . ' font file' . (count($written) === 1 ? '' : 's') . ' locally.'];
    }

    public function remove(string $hash): bool
    {
        $manifest = $this->manifest();

        if (preg_match('/^[a-f0-9]{40}$/', $hash) !== 1 || !isset($manifest[$hash])) {
            return false;
        }

        $this->deleteDirectory($this->fontsPath . '/' . $hash);
        unset($manifest[$hash]);
        $this->saveManifest($manifest);

        return true;
    }

    /**
     * Rewrites the saved stylesheets when the font-display setting changes, so the choice
     * applies to fonts that are already hosted without downloading them again.
     */
    public function applyDisplay(string $display): void
    {
        $display = self::normalizeDisplay($display);

        foreach (array_keys($this->manifest()) as $hash) {
            $path = $this->fontsPath . '/' . $hash . '/fonts.css';
            $css = is_file($path) ? file_get_contents($path) : false;

            if ($css !== false) {
                file_put_contents($path, self::setFontDisplay($css, $display), LOCK_EX);
            }
        }
    }

    public static function setFontDisplay(string $css, string $display): string
    {
        $display = self::normalizeDisplay($display);

        return (string) preg_replace_callback('/@font-face\s*\{[^}]*\}/', static function (array $match) use ($display): string {
            $block = $match[0];

            if (preg_match('/font-display\s*:/i', $block) === 1) {
                return (string) preg_replace('/font-display\s*:\s*[a-z]+\s*;?/i', 'font-display: ' . $display . ';', $block);
            }

            return (string) preg_replace('/\}$/', '  font-display: ' . $display . ";\n}", $block);
        }, $css);
    }

    /**
     * Preloading everything would compete with the page's own content, so only the regular
     * (non-italic) Latin faces are offered, which cover the text of most English pages.
     *
     * @return array<int, string>
     */
    private function preloadCandidates(string $css): array
    {
        $files = [];

        preg_match_all('~/\*\s*([a-z0-9-]+)\s*\*/\s*@font-face\s*\{([^}]*)\}~i', $css, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $isRegularLatin = strtolower($block[1]) === 'latin'
                && preg_match('/font-style\s*:\s*normal/i', $block[2]) === 1;

            if ($isRegularLatin && preg_match('~url\(([a-f0-9]{16}\.woff2)\)~', $block[2], $file) === 1) {
                $files[] = $file[1];
            }
        }

        return array_slice(array_values(array_unique($files)), 0, self::PRELOAD_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $decoded = json_decode((string) $this->config->option(self::MANIFEST_OPTION, '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function saveManifest(array $manifest): void
    {
        $this->config->setOption(self::MANIFEST_OPTION, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES));
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($directory . '/' . $entry)) {
                unlink($directory . '/' . $entry);
            }
        }

        rmdir($directory);
    }

    /**
     * @param array<int, string> $headers
     */
    private function defaultHttpGet(string $url, array $headers, int $timeoutSeconds): ?string
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);

            return (is_string($body) && $status >= 200 && $status < 300) ? $body : null;
        }

        if (!ini_get('allow_url_fopen')) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeoutSeconds,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = QuietCall::run(static fn (): string|false => file_get_contents($url, false, $context));

        return $body !== false ? $body : null;
    }
}
