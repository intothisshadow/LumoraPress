<?php

/**
 * Keeps the optional GitHub access token, encrypted, in the site's options.
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

use LumoraPress\Core\PressConfig;
use RuntimeException;

/**
 * The key is derived from the install's secret key, so a copy of the database or a backup alone
 * doesn't reveal the token. Without libsodium or a secret key the token is refused rather than
 * stored in the clear.
 */
final class GitHubTokenStore
{
    public const OPTION = 'downloads_github_token';

    /** Covers classic ("ghp_…") and fine-grained ("github_pat_…") tokens without admitting header-breaking characters. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_\-]{20,255}$/';

    public function __construct(private readonly PressConfig $config)
    {
    }

    public function isSet(): bool
    {
        return trim((string) $this->config->option(self::OPTION, '')) !== '';
    }

    public function token(): ?string
    {
        $encoded = trim((string) $this->config->option(self::OPTION, ''));
        $key = $this->key();
        $raw = base64_decode($encoded, true);

        if ($encoded === '' || $key === null || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        return $plain === false ? null : $plain;
    }

    /**
     * An empty string removes the token. False means the value was malformed or could not be
     * encrypted, and nothing was changed.
     */
    public function save(string $token): bool
    {
        $token = trim($token);

        if ($token === '') {
            $this->config->setOption(self::OPTION, '');

            return true;
        }

        $key = $this->key();

        if ($key === null || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return false;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $this->config->setOption(self::OPTION, base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, $key)));

        return true;
    }

    public function canEncrypt(): bool
    {
        return $this->key() !== null;
    }

    private function key(): ?string
    {
        try {
            $secretKey = (string) $this->config->get('secret_key', '');
        } catch (RuntimeException) {
            return null;
        }

        if ($secretKey === '' || !function_exists('sodium_crypto_secretbox')) {
            return null;
        }

        return hash('sha256', 'downloads-github-token|' . $secretKey, true);
    }
}
