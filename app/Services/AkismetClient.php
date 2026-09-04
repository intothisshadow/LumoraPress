<?php

/**
 * Optional Akismet spam-checking for comments.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\PressConfig;

/**
 * Optional Akismet spam-checking for comments, off by default — comments work exactly as
 * before if never enabled. Modeled on GitHubReleaseProvider's HTTP pattern (injectable POST
 * closure, curl-first with a fallback) and fails open: `checkComment()` returns null rather
 * than throwing on any network/API problem, leaving the existing local moderation untouched.
 */
final class AkismetClient
{
    private const API_BASE = 'https://rest.akismet.com/1.1';

    private const USER_AGENT = 'LumoraPress/1.0 | Akismet/1.0';

    private const TIMEOUT_SECONDS = 10;

    /**
     * @param (\Closure(string $url, array<string, string> $fields, int $timeoutSeconds): (string|null))|null $httpPost
     *     Injectable for tests; production code never passes this.
     */
    public function __construct(
        private readonly PressConfig $config,
        private readonly string $siteUrl,
        private readonly ?\Closure $httpPost = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return ((string) $this->config->option('akismet_enabled', '0')) === '1' && $this->apiKey() !== '';
    }

    /**
     * Verifies the configured API key against Akismet, independent of
     * `isEnabled()` — used by the Settings page's "Verify Key" button,
     * which should work even before the administrator has ticked "Enable".
     */
    public function verifyKey(): bool
    {
        $key = $this->apiKey();

        if ($key === '') {
            return false;
        }

        $body = $this->post(self::API_BASE . '/verify-key', [
            'key' => $key,
            'blog' => $this->siteUrl,
        ]);

        return trim((string) $body) === 'valid';
    }

    /**
     * @param array{comment_type: string, comment_author: string, comment_author_email: string, comment_author_url: ?string, comment_content: string, user_ip: string, user_agent: ?string, referrer: ?string, permalink: string} $comment
     *
     * @return bool|null True: spam. False: not spam. Null: Akismet could
     *     not be reached or returned something unexpected — callers should
     *     treat this the same as "not spam" (fail open).
     */
    public function checkComment(array $comment): ?bool
    {
        $body = $this->post($this->keyedUrl('comment-check'), $this->commentFields($comment));

        if ($body === null) {
            return null;
        }

        $body = trim($body);

        return match ($body) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /**
     * @param array{comment_type: string, comment_author: string, comment_author_email: string, comment_author_url: ?string, comment_content: string, user_ip: string, user_agent: ?string, referrer: ?string, permalink: string} $comment
     */
    public function submitSpam(array $comment): void
    {
        $this->post($this->keyedUrl('submit-spam'), $this->commentFields($comment));
    }

    /**
     * @param array{comment_type: string, comment_author: string, comment_author_email: string, comment_author_url: ?string, comment_content: string, user_ip: string, user_agent: ?string, referrer: ?string, permalink: string} $comment
     */
    public function submitHam(array $comment): void
    {
        $this->post($this->keyedUrl('submit-ham'), $this->commentFields($comment));
    }

    /**
     * @param array{comment_type: string, comment_author: string, comment_author_email: string, comment_author_url: ?string, comment_content: string, user_ip: string, user_agent: ?string, referrer: ?string, permalink: string} $comment
     *
     * @return array<string, string>
     */
    private function commentFields(array $comment): array
    {
        return array_filter([
            'blog' => $this->siteUrl,
            'user_ip' => $comment['user_ip'],
            'user_agent' => (string) ($comment['user_agent'] ?? ''),
            'referrer' => (string) ($comment['referrer'] ?? ''),
            'permalink' => $comment['permalink'],
            'comment_type' => $comment['comment_type'],
            'comment_author' => $comment['comment_author'],
            'comment_author_email' => $comment['comment_author_email'],
            'comment_author_url' => (string) ($comment['comment_author_url'] ?? ''),
            'comment_content' => $comment['comment_content'],
        ], static fn (string $value): bool => $value !== '');
    }

    private function keyedUrl(string $endpoint): string
    {
        return 'https://' . $this->apiKey() . '.rest.akismet.com/1.1/' . $endpoint;
    }

    private function apiKey(): string
    {
        return trim((string) $this->config->option('akismet_api_key', ''));
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $url, array $fields): ?string
    {
        return ($this->httpPost ?? $this->defaultHttpPost(...))($url, $fields, self::TIMEOUT_SECONDS);
    }

    /**
     * @param array<string, string> $fields
     */
    private function defaultHttpPost(string $url, array $fields, int $timeoutSeconds): ?string
    {
        $payload = http_build_query($fields);
        $headers = [
            'User-Agent: ' . self::USER_AGENT,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeoutSeconds,
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
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => $timeoutSeconds,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        return $body !== false ? $body : null;
    }
}
