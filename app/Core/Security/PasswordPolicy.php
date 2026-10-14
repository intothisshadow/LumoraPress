<?php

/**
 * The site's password rule: one minimum length, applied everywhere a password is chosen.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use LumoraPress\Core\PressConfig;

/**
 * Length only, on purpose: composition rules and forced expiry push people toward predictable
 * passwords. The floor of 8 stops a setting from weakening the rule below what every screen
 * enforced before it existed, and a changed minimum never affects passwords already set.
 */
final class PasswordPolicy
{
    public const DEFAULT_MIN_LENGTH = 10;

    public const FLOOR_MIN_LENGTH = 8;

    public const CEILING_MIN_LENGTH = 64;

    public function __construct(private readonly PressConfig $config)
    {
    }

    public function minLength(): int
    {
        $stored = (int) $this->config->option('password_min_length', (string) self::DEFAULT_MIN_LENGTH);

        return min(self::CEILING_MIN_LENGTH, max(self::FLOOR_MIN_LENGTH, $stored));
    }

    /**
     * @return ?string a plain-language reason the password is not acceptable, or null when it is
     */
    public function violation(string $password): ?string
    {
        return mb_strlen($password) < $this->minLength()
            ? sprintf('Password must be at least %d characters long.', $this->minLength())
            : null;
    }
}
