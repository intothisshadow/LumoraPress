<?php

/**
 * Mailer decorator that reports every failed send to a callback.
 *
 * @package LumoraPress
 * @subpackage Mail
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Mail;

/**
 * Wrapping the one shared transport means password resets, comment alerts, contact forms and
 * sign-up confirmations are all covered without each caller checking the return value itself.
 */
final class FailureReportingMailer implements Mailer
{
    /**
     * @param \Closure(): void $onFailure Must not throw; the send result is returned unchanged either way.
     */
    public function __construct(
        private readonly Mailer $inner,
        private readonly \Closure $onFailure,
    ) {
    }

    public function send(string $to, string $subject, string $body, ?string $replyTo = null): bool
    {
        $sent = $this->inner->send($to, $subject, $body, $replyTo);

        if (!$sent) {
            ($this->onFailure)();
        }

        return $sent;
    }
}
