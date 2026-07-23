<?php

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use LumoraPress\Models\User;
use LumoraPress\Services\UserService;

/**
 * Session-backed authentication. Regenerates the session id on every
 * privilege change (login/logout) to prevent session fixation.
 */
final class Auth
{
    private const SESSION_KEY = 'user_id';

    private ?User $resolvedUser = null;

    private bool $resolved = false;

    public function __construct(
        private readonly UserService $users,
        private readonly SessionManager $sessions,
    ) {
    }

    public function attempt(string $usernameOrEmail, string $password): ?User
    {
        $user = $this->users->verifyCredentials($usernameOrEmail, $password);

        if ($user === null) {
            return null;
        }

        $this->login($user);

        return $user;
    }

    public function login(User $user): void
    {
        $this->sessions->regenerate();
        $_SESSION[self::SESSION_KEY] = $user->id;
        $this->resolvedUser = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        $this->sessions->regenerate();
        $this->resolvedUser = null;
        $this->resolved = true;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->resolvedUser;
        }

        $id = $_SESSION[self::SESSION_KEY] ?? null;
        $this->resolvedUser = $id === null ? null : $this->users->findById((int) $id);
        $this->resolved = true;

        return $this->resolvedUser;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }
}
