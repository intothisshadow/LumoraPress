<?php

/**
 * Sends mail via PHP's built-in mail() function, with no PHPMailer/Symfony Mailer dependency.
 *
 * @package LumoraPress
 * @subpackage Mail
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Mail;

/**
 * Sends mail via PHP's built-in mail() function — no PHPMailer/Symfony
 * Mailer dependency, matching the fact that the main application has no
 * Composer dependency of its own at all (only the separate PHP Test Suite
 * does). This is the only mail transport most shared hosts guarantee is
 * configured out of the box, which is exactly the deployment target
 * CLAUDE.md's Performance goals describe.
 */
final class NativeMailer implements Mailer
{
    public function __construct(private readonly string $fromAddress)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        // Defense-in-depth against header injection via a stray CRLF in an
        // address or subject line, even though both are expected to already
        // be well-formed (an account's own stored email, a static subject
        // string this codebase controls).
        $to = str_replace(["\r", "\n"], '', $to);
        $subject = str_replace(["\r", "\n"], '', $subject);

        $headers = "From: {$this->fromAddress}\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        return @mail($to, $subject, $body, $headers);
    }
}
