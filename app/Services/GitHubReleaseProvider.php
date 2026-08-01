<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\PressConfig;
use RuntimeException;

/**
 * Fetches release metadata from the GitHub Releases API for the
 * administrator-initiated "Check for Updates" flow (LP-027).
 *
 * The active channel ('stable' or 'prerelease', via the `update_channel`
 * option) selects which endpoint is queried:
 *   stable:     GET /repos/{repo}/releases/latest (GitHub already excludes
 *               drafts and prereleases from this endpoint)
 *   prerelease: GET /repos/{repo}/releases (most recent non-draft entry,
 *               whether marked prerelease or not)
 *
 * The repository is configurable via `update_github_repo` (default
 * intothisshadow/LumoraPress) so forks can point at their own releases.
 * An optional `update_github_token` personal access token raises the
 * unauthenticated API rate limit (60/hour) and is required for a private
 * fork's releases; it is only ever sent to api.github.com, never to a
 * third-party redirect target.
 *
 * Prefers the curated release asset LP-052 produces
 * (`LumoraPress-v{version}.zip`) over GitHub's raw tag-archive zipball,
 * falling back to the zipball when a release wasn't cut with the curated
 * asset attached. Both download paths go through the GitHub API's
 * asset/zipball endpoints (never a bare `browser_download_url` redirect)
 * so the same Accept/Authorization headers apply uniformly and an
 * Authorization header is never handed to curl to forward across a
 * cross-host redirect.
 */
final class GitHubReleaseProvider
{
    private const DEFAULT_REPO = 'intothisshadow/LumoraPress';

    private const API_BASE = 'https://api.github.com';

    private const USER_AGENT = 'LumoraPress-Updater';

    private const METADATA_TIMEOUT_SECONDS = 15;

    private const DOWNLOAD_TIMEOUT_SECONDS = 120;

    private const MAX_DOWNLOAD_BYTES = 200 * 1024 * 1024;

    private const DEFAULT_CHECK_INTERVAL_SECONDS = 86400;

    private const MIN_CHECK_INTERVAL_SECONDS = 3600;

    /**
     * @param (\Closure(string $url, array<int, string> $headers, int $timeoutSeconds): (string|null))|null $httpGet
     *     Injectable for tests; production code never passes this.
     * @param (\Closure(string $url, array<int, string> $headers, string $destinationPath, int $timeoutSeconds): bool)|null $httpDownload
     *     Injectable for tests; production code never passes this.
     */
    public function __construct(
        private readonly PressConfig $config,
        private readonly ?\Closure $httpGet = null,
        private readonly ?\Closure $httpDownload = null,
    ) {
    }

    /**
     * @return array{
     *     latest_version: string,
     *     release_date: ?string,
     *     release_notes: ?string,
     *     release_name: ?string,
     *     changelog_url: ?string,
     *     sha256: ?string,
     *     prerelease: bool,
     *     download: array{type: string, url: string, name: string, size: ?int},
     * }|null Null when GitHub could not be reached or returned no usable release.
     */
    public function fetchLatestRelease(): ?array
    {
        $data = $this->channel() === 'prerelease'
            ? $this->fetchLatestFromList()
            : $this->fetchLatestStable();

        return $data !== null ? $this->mapRelease($data) : null;
    }

    /**
     * Downloads a release's package to $destinationPath, verifying its
     * SHA-256 checksum first when the release metadata carries one.
     *
     * @param array{download: array{type: string, url: string, name: string, size: ?int}, sha256: ?string} $release
     *
     * @throws RuntimeException on download failure or checksum mismatch.
     */
    public function downloadRelease(array $release, string $destinationPath): void
    {
        $download = $release['download'];
        $headers = $this->apiHeaders(['Accept: application/octet-stream']);

        $ok = ($this->httpDownload ?? $this->defaultHttpDownload(...))(
            $download['url'],
            $headers,
            $destinationPath,
            self::DOWNLOAD_TIMEOUT_SECONDS,
        );

        if (!$ok || !is_file($destinationPath)) {
            throw new RuntimeException('Unable to download the release package from GitHub.');
        }

        if (filesize($destinationPath) > self::MAX_DOWNLOAD_BYTES) {
            unlink($destinationPath);

            throw new RuntimeException('The release package reported by GitHub is larger than expected and was rejected.');
        }

        if ($release['sha256'] !== null && hash_file('sha256', $destinationPath) !== $release['sha256']) {
            unlink($destinationPath);

            throw new RuntimeException('The downloaded package failed checksum verification and was discarded.');
        }
    }

    /**
     * Cron-free "scheduled" check: called opportunistically from the
     * Dashboard page (see admin/views/dashboard.php) rather than a real
     * background job, since this codebase has no queue/cron
     * infrastructure. Throttled to at most once per
     * `update_check_interval` seconds (floor: 1 hour) via the
     * `update_last_checked_at` option, and no-ops entirely when
     * `update_auto_check_enabled` is off. Only ever checks — never
     * downloads or installs anything, matching this ticket's Phase 1
     * "stay manual, administrator-initiated" requirement for the actual
     * update step.
     */
    public function maybeCheckForUpdates(): void
    {
        if (!$this->autoCheckEnabled()) {
            return;
        }

        $lastCheckedAt = (int) $this->config->option('update_last_checked_at', '0');

        if (time() - $lastCheckedAt < $this->checkIntervalSeconds()) {
            return;
        }

        $this->checkNow();
    }

    /**
     * Checks GitHub immediately, ignoring the throttle interval, and
     * caches the result the same way maybeCheckForUpdates() does — used by
     * the Updates page's manual "Check for Updates Now" button.
     *
     * @return array{
     *     latest_version: string,
     *     release_date: ?string,
     *     release_notes: ?string,
     *     release_name: ?string,
     *     changelog_url: ?string,
     *     sha256: ?string,
     *     prerelease: bool,
     *     download: array{type: string, url: string, name: string, size: ?int},
     * }|null
     */
    public function checkNow(): ?array
    {
        $this->config->setOption('update_last_checked_at', (string) time());

        $release = $this->fetchLatestRelease();

        if ($release !== null) {
            $this->cacheRelease($release);
        }

        return $release;
    }

    /**
     * @param array{
     *     latest_version: string,
     *     release_date: ?string,
     *     release_notes: ?string,
     *     release_name: ?string,
     *     changelog_url: ?string,
     *     prerelease: bool,
     *     download: array{type: string, url: string, name: string, size: ?int},
     * } $release
     */
    private function cacheRelease(array $release): void
    {
        $this->config->setOption('update_last_known_version', $release['latest_version']);
        $this->config->setOption('update_last_known_release_date', (string) ($release['release_date'] ?? ''));
        $this->config->setOption('update_last_known_release_name', (string) ($release['release_name'] ?? ''));
        $this->config->setOption('update_last_known_release_notes', (string) ($release['release_notes'] ?? ''));
        $this->config->setOption('update_last_known_changelog_url', (string) ($release['changelog_url'] ?? ''));
        $this->config->setOption('update_last_known_prerelease', $release['prerelease'] ? '1' : '0');
        $this->config->setOption('update_last_known_download_name', $release['download']['name']);
        $this->config->setOption('update_last_known_download_size', (string) ($release['download']['size'] ?? ''));
    }

    /**
     * Reads back the result of the most recent check (manual, via
     * checkNow(), or via maybeCheckForUpdates()) without making a network
     * call itself — safe to call on every admin page load. Only
     * `available`/`latest_version` are compared against the currently
     * installed version; everything else is display-only — actually
     * downloading a release always goes through fetchLatestRelease()
     * again rather than trusting this cache, since GitHub asset URLs can
     * go stale between checks.
     *
     * @return array{
     *     available: bool,
     *     latest_version: ?string,
     *     release_date: ?string,
     *     release_name: ?string,
     *     release_notes: ?string,
     *     changelog_url: ?string,
     *     prerelease: bool,
     *     download_name: ?string,
     *     download_size: ?int,
     *     last_checked_at: ?int,
     * }
     */
    public function cachedUpdateStatus(string $installedVersion): array
    {
        $latestVersion = trim((string) $this->config->option('update_last_known_version', ''));
        $lastCheckedAt = (int) $this->config->option('update_last_checked_at', '0');

        if ($latestVersion === '') {
            return [
                'available' => false,
                'latest_version' => null,
                'release_date' => null,
                'release_name' => null,
                'release_notes' => null,
                'changelog_url' => null,
                'prerelease' => false,
                'download_name' => null,
                'download_size' => null,
                'last_checked_at' => $lastCheckedAt > 0 ? $lastCheckedAt : null,
            ];
        }

        $nullableOption = function (string $key): ?string {
            $value = trim((string) $this->config->option($key, ''));

            return $value !== '' ? $value : null;
        };

        $downloadSize = $nullableOption('update_last_known_download_size');

        return [
            'available' => version_compare($latestVersion, $installedVersion, '>'),
            'latest_version' => $latestVersion,
            'release_date' => $nullableOption('update_last_known_release_date'),
            'release_name' => $nullableOption('update_last_known_release_name'),
            'release_notes' => $nullableOption('update_last_known_release_notes'),
            'changelog_url' => $nullableOption('update_last_known_changelog_url'),
            'prerelease' => ((string) $this->config->option('update_last_known_prerelease', '0')) === '1',
            'download_name' => $nullableOption('update_last_known_download_name'),
            'download_size' => $downloadSize !== null ? (int) $downloadSize : null,
            'last_checked_at' => $lastCheckedAt > 0 ? $lastCheckedAt : null,
        ];
    }

    private function autoCheckEnabled(): bool
    {
        return ((string) $this->config->option('update_auto_check_enabled', '1')) === '1';
    }

    private function checkIntervalSeconds(): int
    {
        $interval = (int) $this->config->option('update_check_interval', (string) self::DEFAULT_CHECK_INTERVAL_SECONDS);

        return max(self::MIN_CHECK_INTERVAL_SECONDS, $interval);
    }

    private function repo(): string
    {
        $repo = trim((string) $this->config->option('update_github_repo', self::DEFAULT_REPO));

        return $repo !== '' ? $repo : self::DEFAULT_REPO;
    }

    private function channel(): string
    {
        $channel = (string) $this->config->option('update_channel', 'stable');

        return $channel === 'prerelease' ? 'prerelease' : 'stable';
    }

    private function token(): string
    {
        return trim((string) $this->config->option('update_github_token', ''));
    }

    /**
     * @param array<int, string> $extra
     *
     * @return array<int, string>
     */
    private function apiHeaders(array $extra = []): array
    {
        $headers = array_merge([
            'User-Agent: ' . self::USER_AGENT,
            'X-GitHub-Api-Version: 2022-11-28',
        ], $extra);

        $token = $this->token();

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestStable(): ?array
    {
        $url = self::API_BASE . '/repos/' . $this->repo() . '/releases/latest';
        $raw = $this->httpGetJson($url);

        return (is_array($raw) && !empty($raw['tag_name'])) ? $raw : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestFromList(): ?array
    {
        $url = self::API_BASE . '/repos/' . $this->repo() . '/releases?per_page=10';
        $list = $this->httpGetJson($url);

        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $release) {
            if (is_array($release) && empty($release['draft']) && !empty($release['tag_name'])) {
                return $release;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpGetJson(string $url): ?array
    {
        $raw = ($this->httpGet ?? $this->defaultHttpGet(...))(
            $url,
            $this->apiHeaders(['Accept: application/vnd.github+json']),
            self::METADATA_TIMEOUT_SECONDS,
        );

        if ($raw === null) {
            return null;
        }

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *     latest_version: string,
     *     release_date: ?string,
     *     release_notes: ?string,
     *     release_name: ?string,
     *     changelog_url: ?string,
     *     sha256: ?string,
     *     prerelease: bool,
     *     download: array{type: string, url: string, name: string, size: ?int},
     * }
     */
    private function mapRelease(array $data): array
    {
        $tag = trim((string) $data['tag_name']);
        $version = ltrim($tag, 'v');

        $releaseDate = !empty($data['published_at'])
            ? substr((string) $data['published_at'], 0, 10)
            : null;

        $notes = isset($data['body']) ? trim((string) $data['body']) : null;
        $notes = ($notes === '' ? null : $notes);

        if ($notes !== null && strlen($notes) > 2000) {
            $notes = substr($notes, 0, 1997) . '…';
        }

        $releaseName = isset($data['name']) ? trim((string) $data['name']) : null;
        $releaseName = ($releaseName === '' ? null : $releaseName);

        $changelogUrl = isset($data['html_url']) ? trim((string) $data['html_url']) : null;
        $prerelease = (bool) ($data['prerelease'] ?? false);

        $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];
        $curatedName = 'LumoraPress-v' . $version . '.zip';
        $curatedAsset = null;

        foreach ($assets as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $curatedName) {
                $curatedAsset = $asset;
                break;
            }
        }

        $sha256 = null;

        if ($curatedAsset !== null) {
            $download = [
                'type' => 'asset',
                'url' => (string) $curatedAsset['url'],
                'name' => $curatedName,
                'size' => isset($curatedAsset['size']) ? (int) $curatedAsset['size'] : null,
            ];

            $checksumName = $curatedName . '.sha256';

            foreach ($assets as $asset) {
                if (is_array($asset) && ($asset['name'] ?? null) === $checksumName) {
                    $sha256 = $this->fetchChecksum((string) $asset['url']);
                    break;
                }
            }
        } else {
            $download = [
                'type' => 'archive',
                'url' => self::API_BASE . '/repos/' . $this->repo() . '/zipball/' . $tag,
                'name' => 'LumoraPress-' . $version . '.zip',
                'size' => null,
            ];
        }

        return [
            'latest_version' => $version,
            'release_date' => $releaseDate,
            'release_notes' => $notes,
            'release_name' => $releaseName,
            'changelog_url' => $changelogUrl,
            'sha256' => $sha256,
            'prerelease' => $prerelease,
            'download' => $download,
        ];
    }

    private function fetchChecksum(string $assetApiUrl): ?string
    {
        $raw = ($this->httpGet ?? $this->defaultHttpGet(...))(
            $assetApiUrl,
            $this->apiHeaders(['Accept: application/octet-stream']),
            self::METADATA_TIMEOUT_SECONDS,
        );

        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        if (preg_match('/^[a-f0-9]{64}$/i', $raw) === 1) {
            return strtolower($raw);
        }

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^([a-f0-9]{64})\s/i', trim($line), $matches) === 1) {
                return strtolower($matches[1]);
            }
        }

        return null;
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
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

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
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        return $body !== false ? $body : null;
    }

    /**
     * @param array<int, string> $headers
     */
    private function defaultHttpDownload(string $url, array $headers, string $destinationPath, int $timeoutSeconds): bool
    {
        if (function_exists('curl_init')) {
            $file = fopen($destinationPath, 'wb');

            if ($file === false) {
                return false;
            }

            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_FILE => $file,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $success = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);
            fclose($file);

            if ($success !== true || $status < 200 || $status >= 300) {
                @unlink($destinationPath);

                return false;
            }

            return true;
        }

        if (!ini_get('allow_url_fopen')) {
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeoutSeconds,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return false;
        }

        return file_put_contents($destinationPath, $body) !== false;
    }
}
