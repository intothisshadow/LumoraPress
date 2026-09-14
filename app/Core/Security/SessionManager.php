<?php

/**
 * Configures and manages PHP sessions with secure defaults (HttpOnly, SameSite=Lax, Secure when applicable).
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use SessionHandlerInterface;

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
        private readonly ?SessionHandlerInterface $handler = null,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Debian/Ubuntu-family hosts ship PHP with session.gc_probability=0 and rely on a
        // distro cron job that only sweeps the default session.save_path — since we've just
        // taken session storage over ourselves (a custom handler, or a redirected file path),
        // nothing would ever clean it up otherwise. Force PHP's own probabilistic GC back on
        // so it isn't silently disabled by a host default that no longer applies here; PHP
        // calls a custom handler's gc() under these same settings, not just the file handler's.
        if ($this->handler !== null) {
            session_set_save_handler($this->handler, true);

            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        } elseif (is_dir($this->sessionPath) && is_writable($this->sessionPath)) {
            session_save_path($this->sessionPath);

            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
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
