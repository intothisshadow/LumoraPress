<?php

/**
 * Optional Google reCAPTCHA verification for Contact Form submissions.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\ContactForms;

use LumoraPress\Core\PressConfig;

/**
 * Off by default. Unlike Akismet, which can only push toward spam, enabling
 * this is a hard gate: verify() failing (bad token or unreachable service)
 * rejects the submission — failing open would defeat the point of turning it on.
 */
final class ReCaptchaClient
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private const TIMEOUT_SECONDS = 10;

    /**
     * @param (\Closure(string $url, array<string, string> $fields, int $timeoutSeconds): (string|null))|null $httpPost
     *     Injectable for tests; production code never passes this.
     */
    public function __construct(
        private readonly PressConfig $config,
        private readonly ?\Closure $httpPost = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return ((string) $this->config->option('contact_forms_recaptcha_enabled', '0')) === '1' && $this->secretKey() !== '';
    }

    public function siteKey(): string
    {
        return trim((string) $this->config->option('contact_forms_recaptcha_site_key', ''));
    }

    public function verify(string $token, string $remoteIp): bool
    {
        if ($token === '') {
            return false;
        }

        $body = $this->post(self::VERIFY_URL, [
            'secret' => $this->secretKey(),
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        if ($body === null) {
            return false;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) && ($decoded['success'] ?? false) === true;
    }

    private function secretKey(): string
    {
        return trim((string) $this->config->option('contact_forms_recaptcha_secret_key', ''));
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

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
