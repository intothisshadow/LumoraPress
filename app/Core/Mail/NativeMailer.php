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
 * Composer dependency of its own at all. This is the only mail transport
 * most shared hosts guarantee is configured out of the box.
 */
final class NativeMailer implements Mailer
{
    public function __construct(private readonly string $fromAddress)
    {
    }

    public function send(string $to, string $subject, string $body, ?string $replyTo = null): bool
    {
        // Defense-in-depth against header injection via a stray CRLF in an
        // address or subject line, even though both are expected to already
        // be well-formed (an account's own stored email, a static subject
        // string this codebase controls). $replyTo can come from a public
        // contact form submission, so it gets the same treatment plus a
        // valid-email check rather than being trusted outright.
        $to = str_replace(["\r", "\n"], '', $to);
        $subject = str_replace(["\r", "\n"], '', $subject);

        $headers = "From: {$this->fromAddress}\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        if ($replyTo !== null) {
            $replyTo = str_replace(["\r", "\n"], '', $replyTo);

            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
                $headers .= "Reply-To: {$replyTo}\r\n";
            }
        }

        return @mail($to, $subject, $body, $headers);
    }
}
