<?php

/**
 * Minimal outbound-email interface (LP-058).
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
 * Minimal outbound-email abstraction. Kept as an interface rather than a
 * concrete class so tests can substitute a capturing fake instead of
 * sending real mail — the same DI-for-testability pattern
 * GitHubReleaseProvider's injectable HTTP closures use, just as an
 * interface since a mailer is a natural service boundary rather than a
 * single function call.
 */
interface Mailer
{
    /**
     * @param ?string $replyTo Set when a reply should go somewhere other
     *     than $to's own inbox (e.g. a contact form's Reply-To pointing at
     *     the submitter rather than the site's notification address).
     * @return bool True if the mail transport accepted the message for
     *     delivery. This is not a delivery guarantee — `mail()` itself
     *     offers none — only confirmation the message was handed off.
     */
    public function send(string $to, string $subject, string $body, ?string $replyTo = null): bool;
}
