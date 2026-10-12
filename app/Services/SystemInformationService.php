<?php

/**
 * Builds the installation details shown on Maintenance > System Information and exported as a support snapshot.
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

/**
 * Every value is handed in rather than read from globals, so each badge
 * state is unit-testable without a real server, and database credentials
 * simply never reach this class, which keeps them out of the support
 * snapshot by construction.
 */
final class SystemInformationService
{
    public const BADGE_OK = 'success';
    public const BADGE_BAD = 'failed';
    public const BADGE_WARN = 'rolled_back';
    public const BADGE_NEUTRAL = 'neutral';

    private const MINIMUM_MYSQL = '5.6.4';
    private const MINIMUM_MARIADB = '10.0.5';

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, string> $directories label => absolute path
     * @param array<string, string> $phpLimits label => ini value
     */
    public function __construct(
        private readonly array $server,
        private readonly string $storedSiteUrl,
        private readonly string $detectedSiteUrl,
        private readonly string $applicationRoot,
        private readonly array $directories,
        private readonly string $databaseHost,
        private readonly string $databaseName,
        private readonly string $tablePrefix,
        private readonly string $databaseServerVersion,
        private readonly string $phpVersion,
        private readonly array $phpLimits,
        private readonly string $cacheDriver,
        private readonly bool $cacheEnabled,
        private readonly bool $brotliLoaded,
    ) {
    }

    /**
     * @return array<int, array{title: string, rows: array<int, array{label: string, value: string, code: bool, badges: array<int, array{text: string, variant: string}>}>}>
     */
    public function sections(): array
    {
        return [
            ['title' => 'Site', 'rows' => $this->siteRows()],
            ['title' => 'Directories', 'rows' => $this->directoryRows()],
            ['title' => 'Database', 'rows' => $this->databaseRows()],
            ['title' => 'Server', 'rows' => $this->serverRows()],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->siteUrlsDiffer()) {
            $warnings[] = 'The stored site URL does not match the address this page was reached at. Links, redirects and feeds use the stored URL, so correct it under Settings › General if the site has moved.';
        }

        return $warnings;
    }

    /**
     * Plain label => text pairs per section, for the downloadable snapshot.
     *
     * @return array<string, array<string, string>>
     */
    public function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->sections() as $section) {
            foreach ($section['rows'] as $row) {
                $badges = array_map(static fn (array $badge): string => $badge['text'], $row['badges']);
                $snapshot[$section['title']][$row['label']] = $row['value'] . ($badges === [] ? '' : ' (' . implode(', ', $badges) . ')');
            }
        }

        return $snapshot;
    }

    /**
     * @return array{family: string, version: string}
     */
    public static function parseDatabaseVersion(string $raw): array
    {
        $isMariaDb = stripos($raw, 'mariadb') !== false;

        // Older PDO MySQL drivers prefix MariaDB's real version with a
        // fake "5.5.5-" for replication compatibility.
        $clean = (string) preg_replace('/^5\.5\.5-/', '', $raw);
        $version = preg_match('/\d+(?:\.\d+){1,2}/', $clean, $match) === 1 ? $match[0] : $raw;

        return ['family' => $isMariaDb ? 'MariaDB' : 'MySQL', 'version' => $version];
    }

    private function siteUrlsDiffer(): bool
    {
        if ($this->storedSiteUrl === '') {
            return false;
        }

        return rtrim(strtolower($this->storedSiteUrl), '/') !== rtrim(strtolower($this->detectedSiteUrl), '/');
    }

    private function isHttps(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }

    /**
     * @return array<int, array{label: string, value: string, code: bool, badges: array<int, array{text: string, variant: string}>}>
     */
    private function siteRows(): array
    {
        $detectedBadges = [];

        if ($this->isHttps()) {
            $detectedBadges[] = $this->badge('HTTPS active', self::BADGE_OK);
        }

        if ($this->siteUrlsDiffer()) {
            $detectedBadges[] = $this->badge('differs from stored', self::BADGE_WARN);
        }

        return [
            $this->row('Site URL (stored)', $this->storedSiteUrl === '' ? '—' : $this->storedSiteUrl, true),
            $this->row('Site URL (auto-detected)', $this->detectedSiteUrl, true, $detectedBadges),
            $this->row('Application root', $this->applicationRoot, true),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, code: bool, badges: array<int, array{text: string, variant: string}>}>
     */
    private function directoryRows(): array
    {
        $rows = [];

        foreach ($this->directories as $label => $path) {
            $badge = is_dir($path) && is_writable($path)
                ? $this->badge('writable', self::BADGE_OK)
                : $this->badge(is_dir($path) ? 'not writable' : 'missing', self::BADGE_BAD);
            $rows[] = $this->row($label, $path, true, [$badge]);
        }

        return $rows;
    }

    /**
     * @return array<int, array{label: string, value: string, code: bool, badges: array<int, array{text: string, variant: string}>}>
     */
    private function databaseRows(): array
    {
        $parsed = self::parseDatabaseVersion($this->databaseServerVersion);
        $minimum = $parsed['family'] === 'MariaDB' ? self::MINIMUM_MARIADB : self::MINIMUM_MYSQL;
        $supported = version_compare($parsed['version'], $minimum, '>=');

        return [
            $this->row('Database host', $this->databaseHost, true),
            $this->row('Database name', $this->databaseName, true),
            $this->row('Table prefix', $this->tablePrefix, true),
            $this->row(
                'Database server',
                $parsed['family'] . ' ' . $parsed['version'],
                false,
                [$supported
                    ? $this->badge('supported', self::BADGE_OK)
                    : $this->badge('older than ' . $minimum, self::BADGE_BAD)],
            ),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, code: bool, badges: array<int, array{text: string, variant: string}>}>
     */
    private function serverRows(): array
    {
        $software = trim((string) ($this->server['SERVER_SOFTWARE'] ?? ''));
        $family = $this->serverFamily($software);

        $rows = [
            $this->row('PHP version', $this->phpVersion),
            $this->row('Web server', $software === '' ? '—' : $software, false, $family === null ? [] : [$this->badge($family . ' detected', self::BADGE_OK)]),
        ];

        foreach ($this->phpLimits as $label => $value) {
            $rows[] = $this->row($label, $value === '' ? '—' : $value);
        }

        $rows[] = $this->row('Detected capabilities', '', false, $this->capabilityBadges($family));

        return $rows;
    }

    private function serverFamily(string $software): ?string
    {
        $lower = strtolower($software);

        foreach (['litespeed' => 'LiteSpeed', 'apache' => 'Apache', 'nginx' => 'nginx', 'caddy' => 'Caddy'] as $needle => $name) {
            if (str_contains($lower, $needle)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * PHP only sees what the web server tells it, so a capability that
     * can't be seen is "not detected", never a claim that it's absent.
     *
     * @return array<int, array{text: string, variant: string}>
     */
    private function capabilityBadges(?string $family): array
    {
        $protocol = strtoupper((string) ($this->server['SERVER_PROTOCOL'] ?? ''));
        $detected = static fn (bool $on, string $name): array => [
            'text' => ($on ? '✓ ' : '— ') . $name . ($on ? '' : ' not detected'),
            'variant' => $on ? self::BADGE_OK : self::BADGE_NEUTRAL,
        ];

        $badges = [
            $detected(str_starts_with($protocol, 'HTTP/2'), 'HTTP/2'),
            $detected(str_starts_with($protocol, 'HTTP/3'), 'HTTP/3'),
            $detected($this->brotliLoaded, 'Brotli'),
        ];

        $cacheActive = $this->cacheEnabled && $this->cacheDriver !== 'null';
        $cacheName = $this->cacheDriver === 'litespeed' ? 'LSCache' : ucfirst($this->cacheDriver) . ' cache';
        $badges[] = $cacheActive
            ? ['text' => '✓ ' . $cacheName . ' active', 'variant' => self::BADGE_OK]
            : ['text' => '— page cache not active', 'variant' => self::BADGE_NEUTRAL];

        return $badges;
    }

    /**
     * @param array<int, array{text: string, variant: string}> $badges
     * @return array{label: string, value: string, code: bool, badges: array<int, array{text: string, variant: string}>}
     */
    private function row(string $label, string $value, bool $code = false, array $badges = []): array
    {
        return ['label' => $label, 'value' => $value, 'code' => $code, 'badges' => $badges];
    }

    /**
     * @return array{text: string, variant: string}
     */
    private function badge(string $text, string $variant): array
    {
        return ['text' => $text, 'variant' => $variant];
    }
}
