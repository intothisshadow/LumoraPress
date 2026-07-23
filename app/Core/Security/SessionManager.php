<?php

declare(strict_types=1);

namespace LumoraPress\Core\Security;

/**
 * Configures and manages PHP sessions with secure defaults: HttpOnly,
 * SameSite=Lax, and Secure when served over HTTPS.
 */
final class SessionManager
{
    public function __construct(
        private readonly string $sessionPath,
        private readonly bool $secureCookies,
        private readonly string $sessionName = 'lumora_press_session',
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (is_dir($this->sessionPath) && is_writable($this->sessionPath)) {
            session_save_path($this->sessionPath);
        }

        session_name($this->sessionName);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }

        session_destroy();
    }
}
