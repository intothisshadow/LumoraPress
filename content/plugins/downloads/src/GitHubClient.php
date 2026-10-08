<?php

/**
 * Reads public repository details, READMEs, and screenshot files from GitHub for the Downloads importer.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use Closure;

/**
 * Every address is built here from a validated GitHubRepositoryRef on api.github.com or
 * raw.githubusercontent.com, and the default transport never follows a redirect, so a response
 * can't send the request anywhere else. The HTTP calls are injectable so tests need no network.
 */
final class GitHubClient
{
    /** Folders searched for screenshots, in order; the first with images wins. */
    public const SCREENSHOT_FOLDERS = ['screenshots', 'screenshot', 'images', 'docs/screenshots'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const MAX_IMAGE_BYTES = 8_388_608;

    private const MAX_README_BYTES = 1_000_000;

    private const TIMEOUT_SECONDS = 20;

    private const USER_AGENT = 'LumoraPress-Downloads';

    /** @var Closure(string, array<int, string>, int): (array{status: int, body: string, headers: array<string, string>}|null) */
    private readonly Closure $httpGet;

    /** @var Closure(string, string, int, int): int */
    private readonly Closure $httpDownload;

    /**
     * @param (Closure(string $url, array<int, string> $headers, int $timeoutSeconds): (array{status: int, body: string, headers: array<string, string>}|null))|null $httpGet null means the request could not be made at all
     * @param (Closure(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): int)|null $httpDownload returns the HTTP status, or 0 when the request failed
     * @param string|null $token optional access token; sent to api.github.com only, never to the raw file host
     */
    public function __construct(?Closure $httpGet = null, ?Closure $httpDownload = null, private readonly ?string $token = null)
    {
        $this->httpGet = $httpGet ?? $this->defaultHttpGet(...);
        $this->httpDownload = $httpDownload ?? $this->defaultHttpDownload(...);
    }

    /**
     * @return array<int, array{name: string, description: string, fork: bool, archived: bool, defaultBranch: string}>
     */
    public function listRepositories(string $owner): array
    {
        if (!GitHubRepositoryRef::isValidOwner($owner)) {
            throw new GitHubImportException('That is not a valid GitHub username.', GitHubImportException::INVALID);
        }

        $repos = [];

        // Three pages of 100 is plenty for a personal account and keeps the API cost bounded.
        for ($page = 1; $page <= 3; $page++) {
            $items = $this->getJson('/users/' . rawurlencode($owner) . '/repos?per_page=100&sort=updated&page=' . $page);

            if (!is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['name'] ?? null)) {
                    continue;
                }

                $repos[] = [
                    'name' => $item['name'],
                    'description' => is_string($item['description'] ?? null) ? $item['description'] : '',
                    'fork' => (bool) ($item['fork'] ?? false),
                    'archived' => (bool) ($item['archived'] ?? false),
                    'defaultBranch' => is_string($item['default_branch'] ?? null) ? $item['default_branch'] : 'main',
                ];
            }

            if (count($items) < 100) {
                break;
            }
        }

        return $repos;
    }

    /**
     * @return array{name: string, description: string, defaultBranch: string}
     */
    public function fetchRepository(GitHubRepositoryRef $ref): array
    {
        $data = $this->getJson($this->repoPath($ref));

        if (!is_array($data) || !is_string($data['name'] ?? null)) {
            throw new GitHubImportException('GitHub returned an unexpected response for that repository.', GitHubImportException::INVALID);
        }

        if (($data['private'] ?? false) === true) {
            throw $this->notFound();
        }

        return [
            'name' => $data['name'],
            'description' => is_string($data['description'] ?? null) ? $data['description'] : '',
            'defaultBranch' => is_string($data['default_branch'] ?? null) && $data['default_branch'] !== '' ? $data['default_branch'] : 'main',
        ];
    }

    /**
     * Null when the repository has no README.
     */
    public function fetchReadme(GitHubRepositoryRef $ref, string $branch): ?string
    {
        $response = $this->request(
            $this->repoPath($ref) . '/readme?ref=' . rawurlencode($branch),
            'application/vnd.github.raw+json',
            allowNotFound: true,
        );

        if ($response === null) {
            return null;
        }

        return substr($response['body'], 0, self::MAX_README_BYTES);
    }

    /**
     * @return array<int, array{path: string, name: string, sha: string, size: int}>
     */
    public function listScreenshots(GitHubRepositoryRef $ref, string $branch): array
    {
        foreach (self::SCREENSHOT_FOLDERS as $folder) {
            $response = $this->request(
                $this->repoPath($ref) . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $folder))) . '?ref=' . rawurlencode($branch),
                'application/vnd.github+json',
                allowNotFound: true,
            );

            if ($response === null) {
                continue;
            }

            $entries = json_decode($response['body'], true);

            if (!is_array($entries) || !array_is_list($entries)) {
                continue;
            }

            $images = [];

            foreach ($entries as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? '') !== 'file' || !is_string($entry['name'] ?? null) || !is_string($entry['sha'] ?? null)) {
                    continue;
                }

                $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));

                if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                    continue;
                }

                $images[] = [
                    'path' => $folder . '/' . $entry['name'],
                    'name' => $entry['name'],
                    'sha' => $entry['sha'],
                    'size' => (int) ($entry['size'] ?? 0),
                ];
            }

            if ($images !== []) {
                usort($images, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

                return $images;
            }
        }

        return [];
    }

    public function downloadFile(GitHubRepositoryRef $ref, string $branch, string $path, string $destinationPath): void
    {
        $status = ($this->httpDownload)($ref->rawUrl($branch, $path), $destinationPath, self::TIMEOUT_SECONDS, self::MAX_IMAGE_BYTES);

        if ($status === 200 && is_file($destinationPath) && filesize($destinationPath) <= self::MAX_IMAGE_BYTES) {
            return;
        }

        if (is_file($destinationPath)) {
            unlink($destinationPath);
        }

        throw new GitHubImportException('A file could not be downloaded from GitHub.', $status === 0 ? GitHubImportException::UNREACHABLE : GitHubImportException::INVALID);
    }

    private function repoPath(GitHubRepositoryRef $ref): string
    {
        return '/repos/' . rawurlencode($ref->owner) . '/' . rawurlencode($ref->repo);
    }

    /**
     * @return array<mixed>|null
     */
    private function getJson(string $path): ?array
    {
        $response = $this->request($path, 'application/vnd.github+json');
        $decoded = $response !== null ? json_decode($response['body'], true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}|null null only for a 404 when $allowNotFound
     */
    private function request(string $path, string $accept, bool $allowNotFound = false): ?array
    {
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: ' . self::USER_AGENT,
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $response = ($this->httpGet)('https://api.github.com' . $path, $headers, self::TIMEOUT_SECONDS);

        if ($response === null) {
            throw new GitHubImportException('GitHub could not be reached. Try again in a moment.', GitHubImportException::UNREACHABLE);
        }

        $status = $response['status'];

        if ($status === 200) {
            return $response;
        }

        if ($status === 404) {
            if ($allowNotFound) {
                return null;
            }

            throw $this->notFound();
        }

        if ($status === 401) {
            throw new GitHubImportException('GitHub did not accept the saved access token. Check it, or remove it to use GitHub without one.', GitHubImportException::INVALID);
        }

        if (in_array($status, [301, 302, 307, 308], true)) {
            throw new GitHubImportException('That repository has moved or been renamed. Use its new address.', GitHubImportException::MOVED);
        }

        if (($status === 403 || $status === 429) && ($response['headers']['x-ratelimit-remaining'] ?? '') === '0') {
            $reset = (int) ($response['headers']['x-ratelimit-reset'] ?? 0);
            $when = $reset > 0 ? ' It resets at ' . date('H:i', $reset) . '.' : '';
            $advice = $this->token === null || $this->token === '' ? ' Adding a GitHub access token on the Import from GitHub screen raises the limit.' : '';

            throw new GitHubImportException('GitHub has limited how many requests this site can make for now.' . $when . $advice, GitHubImportException::RATE_LIMITED);
        }

        throw new GitHubImportException('GitHub returned an unexpected response.', GitHubImportException::INVALID);
    }

    private function notFound(): GitHubImportException
    {
        return new GitHubImportException('No public repository was found there.', GitHubImportException::NOT_FOUND);
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|null
     */
    private function defaultHttpGet(string $url, array $headers, int $timeoutSeconds): ?array
    {
        if (!function_exists('curl_init')) {
            return $this->streamGet($url, $headers, $timeoutSeconds);
        }

        $responseHeaders = [];
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            // A redirect could point anywhere, so none is followed; a moved repository is reported instead.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if (!is_string($body)) {
            return null;
        }

        return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|null
     */
    private function streamGet(string $url, array $headers, int $timeoutSeconds): ?array
    {
        if (!ini_get('allow_url_fopen')) {
            return null;
        }

        $context = stream_context_create([
            'http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $timeoutSeconds, 'follow_location' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = file_get_contents($url, false, $context);

        if ($body === false) {
            return null;
        }

        $status = 0;
        $responseHeaders = [];

        // $http_response_header is deprecated from PHP 8.5; its replacement exists from 8.4.
        $headerLines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);

        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
    }

    private function defaultHttpDownload(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): int
    {
        $file = fopen($destinationPath, 'wb');

        if ($file === false) {
            return 0;
        }

        $written = 0;
        $status = 0;

        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // Returning fewer bytes than given aborts the transfer, so an oversized file stops early.
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, $maxBytes, &$written): int {
                    $written += strlen($chunk);

                    return $written > $maxBytes ? 0 : (int) fwrite($file, $chunk);
                },
            ]);

            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        } elseif (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . self::USER_AGENT, 'timeout' => $timeoutSeconds, 'follow_location' => 0, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = file_get_contents($url, false, $context, 0, $maxBytes + 1);

            if ($body !== false) {
                fwrite($file, $body);

                $headerLines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);

                foreach ($headerLines as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                        $status = (int) $matches[1];
                    }
                }
            }
        }

        fclose($file);

        if ($status !== 200 && is_file($destinationPath)) {
            unlink($destinationPath);
        }

        return $status;
    }
}
