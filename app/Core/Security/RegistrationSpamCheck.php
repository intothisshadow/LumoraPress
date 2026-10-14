<?php

/**
 * Decides whether a public sign-up looks like spam, from the details the visitor typed.
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

use LumoraPress\Services\UserService;

/**
 * Pure: every rule arrives through the constructor, so each check can be tested on its own.
 * The reasons it returns are for the security log and plugins only; the visitor is never told
 * which rule fired, so a bot can't tune its sign-ups against the answer.
 */
final class RegistrationSpamCheck
{
    public const REASON_BLOCKED_DOMAIN = 'blocked_domain';

    public const REASON_DOMAIN_NOT_ALLOWED = 'domain_not_allowed';

    public const REASON_DISPOSABLE_DOMAIN = 'disposable_domain';

    public const REASON_BLOCKED_IP = 'blocked_ip';

    public const REASON_BAD_USERNAME = 'bad_username';

    public const REASON_BAD_DISPLAY_NAME = 'bad_display_name';

    public const REASON_WRONG_ANSWER = 'wrong_answer';

    /**
     * Services that exist to hand out throw-away inboxes. A real reader has no need to
     * confirm an account at one, and a bot that wants to pass the email step uses these.
     *
     * @var list<string>
     */
    private const DISPOSABLE_DOMAINS = [
        '10minutemail.com', '10minutemail.net', 'anonbox.net', 'burnermail.io', 'discard.email',
        'dispostable.com', 'emailondeck.com', 'fakeinbox.com', 'getnada.com', 'grr.la',
        'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.net',
        'guerrillamail.org', 'guerrillamailblock.com', 'inboxkitten.com', 'maildrop.cc',
        'mailcatch.com', 'mailforspam.com', 'mailinator.com', 'mailnesia.com', 'mintemail.com',
        'moakt.com', 'mohmal.com', 'mytemp.email', 'sharklasers.com', 'spambox.us',
        'spamgourmet.com', 'temp-mail.org', 'tempail.com', 'tempinbox.com', 'tempmail.com',
        'tempmailo.com', 'throwawaymail.com', 'trashmail.com', 'trashmail.net', 'yopmail.com',
        'yopmail.fr', 'yopmail.net',
    ];

    /** A web address, an email address, or a name that ends like a domain. */
    private const LINK_PATTERN = '~(https?:|www\.|@|\.(com|net|org|ru|info|xyz|top|shop|biz|online|site|click)\b)~iu';

    /**
     * @param list<string> $blockedDomains
     * @param list<string> $allowedDomains when not empty, only these domains may sign up
     * @param list<string> $blockedIpRules single addresses or CIDR ranges
     */
    public function __construct(
        private readonly array $blockedDomains = [],
        private readonly array $allowedDomains = [],
        private readonly bool $blockDisposable = true,
        private readonly array $blockedIpRules = [],
        private readonly string $question = '',
        private readonly string $expectedAnswer = '',
    ) {
    }

    /**
     * Normalises a textarea of domains: one per line, commas also separate, `#` starts a
     * comment, and a leading `@` or `*.` is dropped.
     *
     * @return list<string>
     */
    public static function parseDomains(string $raw): array
    {
        $domains = [];

        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            foreach (preg_split('/[\s,]+/', trim(explode('#', $line, 2)[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                $entry = strtolower(ltrim($entry, '@'));
                $entry = str_starts_with($entry, '*.') ? substr($entry, 2) : $entry;

                if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $entry) === 1 && !in_array($entry, $domains, true)) {
                    $domains[] = $entry;
                }
            }
        }

        return $domains;
    }

    /**
     * @return list<string> the reasons that apply; empty means nothing looked wrong
     */
    public function reasons(string $username, string $email, string $displayName, string $answer, string $ipAddress): array
    {
        $reasons = [];
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        if ($domain !== '') {
            if ($this->domainMatches($domain, $this->blockedDomains)) {
                $reasons[] = self::REASON_BLOCKED_DOMAIN;
            }

            if ($this->allowedDomains !== [] && !$this->domainMatches($domain, $this->allowedDomains)) {
                $reasons[] = self::REASON_DOMAIN_NOT_ALLOWED;
            }

            if ($this->blockDisposable && $this->domainMatches($domain, self::DISPOSABLE_DOMAINS)) {
                $reasons[] = self::REASON_DISPOSABLE_DOMAIN;
            }
        }

        if ($this->blockedIpRules !== [] && IpRangeMatcher::matches($ipAddress, $this->blockedIpRules)) {
            $reasons[] = self::REASON_BLOCKED_IP;
        }

        if (!self::isAcceptableUsername($username)) {
            $reasons[] = self::REASON_BAD_USERNAME;
        }

        if ($displayName !== '' && !self::isAcceptableDisplayName($displayName)) {
            $reasons[] = self::REASON_BAD_DISPLAY_NAME;
        }

        if ($this->question !== '' && !$this->answerMatches($answer)) {
            $reasons[] = self::REASON_WRONG_ANSWER;
        }

        return $reasons;
    }

    /**
     * Letters, digits, and `._- ` only, so a link or an @ can't hide in the name that appears
     * beside every comment, and nobody can claim a name an administrator would use.
     */
    public static function isAcceptableUsername(string $username): bool
    {
        $length = mb_strlen($username);

        return $length >= 3
            && $length <= 60
            && preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._\- ]*$/u', $username) === 1
            && preg_match(self::LINK_PATTERN, $username) !== 1
            && !UserService::isGuessableAdministratorUsername($username);
    }

    public static function isAcceptableDisplayName(string $displayName): bool
    {
        return mb_strlen($displayName) <= 100
            && preg_match(self::LINK_PATTERN, $displayName) !== 1
            && preg_match('/[<>\x00-\x1f]/', $displayName) !== 1;
    }

    /**
     * A subdomain of a listed domain counts as that domain, so blocking example.com also
     * blocks mail.example.com.
     *
     * @param list<string> $domains
     */
    private function domainMatches(string $domain, array $domains): bool
    {
        foreach ($domains as $listed) {
            if ($domain === $listed || str_ends_with($domain, '.' . $listed)) {
                return true;
            }
        }

        return false;
    }

    private function answerMatches(string $answer): bool
    {
        $normalise = static fn (string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

        return $this->expectedAnswer !== '' && hash_equals($normalise($this->expectedAnswer), $normalise($answer));
    }
}
