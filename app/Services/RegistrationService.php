<?php

/**
 * Public sign-up: pending registrations, email confirmation, optional approval, and the spam checks in front of them.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Mail\Mailer;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\IpRangeMatcher;
use LumoraPress\Core\Security\PasswordPolicy;
use LumoraPress\Core\Security\RegistrationSpamCheck;
use LumoraPress\Core\Security\SecurityLog;
use LumoraPress\Models\User;
use LumoraPress\Models\UserRole;
use Throwable;

/**
 * A sign-up writes only to `pending_registrations`. A `users` row exists once the emailed link
 * is followed (or an administrator approves it), so a bot that never reads its mail can't fill
 * the Users list, and abandoned sign-ups simply expire.
 *
 * Whether an email address already has an account is never revealed to the visitor: the form
 * answers the same either way, and an existing owner gets a "you already have an account"
 * email instead of a confirmation link.
 */
final class RegistrationService
{
    public const STATUS_UNCONFIRMED = 'unconfirmed';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const RESULT_SENT = 'sent';

    public const RESULT_INVALID = 'invalid';

    public const CONFIRM_CREATED = 'created';

    public const CONFIRM_AWAITING_APPROVAL = 'awaiting_approval';

    public const CONFIRM_INVALID = 'invalid';

    private const CONFIRM_LIFETIME_SECONDS = 172800;

    private const APPROVAL_LIFETIME_SECONDS = 1209600;

    /** At most this many sign-up emails go to one address per hour, so the form can't mail-bomb someone. */
    private const MAX_EMAILS_PER_ADDRESS_PER_HOUR = 2;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
        private readonly UserService $users,
        private readonly Mailer $mailer,
        private readonly NotificationService $notifications,
        private readonly SecurityLog $securityLog,
        private readonly HookManager $hooks,
        private readonly ?AkismetClient $akismet = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->option('users_can_register', '0') === '1';
    }

    /**
     * Only roles that cannot publish are offered; a stored value naming anything else falls
     * back to Subscriber rather than ever creating a more privileged account.
     */
    public function defaultRole(): UserRole
    {
        return $this->config->option('registration_default_role', 'subscriber') === UserRole::Contributor->value
            ? UserRole::Contributor
            : UserRole::Subscriber;
    }

    public function requiresApproval(): bool
    {
        return $this->config->option('registration_require_approval', '0') === '1';
    }

    public function question(): string
    {
        return trim((string) $this->config->option('registration_question', ''));
    }

    /**
     * @param array{username?: string, email?: string, password?: string, password_confirm?: string, display_name?: string, answer?: string} $input
     * @return array{status: string, errors: list<string>}
     */
    public function register(array $input, string $ipAddress, string $userAgent = ''): array
    {
        $this->purgeExpired();

        $username = trim((string) ($input['username'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $answer = (string) ($input['answer'] ?? '');

        $errors = $this->visibleErrors($username, $email, $displayName, $password, (string) ($input['password_confirm'] ?? ''), $answer, $ipAddress);

        if ($errors !== []) {
            return ['status' => self::RESULT_INVALID, 'errors' => $errors];
        }

        $silentReasons = $this->silentReasons($username, $email, $displayName, $answer, $ipAddress, $userAgent);

        if ($silentReasons !== []) {
            $this->securityLog->record(SecurityLog::EVENT_REGISTRATION_BLOCKED, $ipAddress, implode(', ', $silentReasons));

            return ['status' => self::RESULT_SENT, 'errors' => []];
        }

        if ($this->emailMayBeMailed($email)) {
            if ($this->users->findByEmail($email) !== null) {
                $this->sendAlreadyRegisteredEmail($email);
            } else {
                $this->issuePending($username, $email, $displayName, $password, $ipAddress);
            }
        }

        return ['status' => self::RESULT_SENT, 'errors' => []];
    }

    /**
     * Non-mutating, so a link that a mail scanner pre-fetches doesn't use the token up; only
     * the confirm button on the page does.
     *
     * @return array<string, mixed>|null the pending row, or null when the link is invalid or expired
     */
    public function peek(string $tokenValue): ?array
    {
        $parts = explode(':', $tokenValue, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        try {
            $row = $this->database->fetchOne(
                'SELECT * FROM ' . $this->table() . ' WHERE selector = :selector',
                ['selector' => $parts[0]],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        if (
            $row === null
            || $row['status'] !== self::STATUS_UNCONFIRMED
            || strtotime((string) $row['expires_at']) < time()
            || !hash_equals((string) $row['token_hash'], hash('sha256', $parts[1]))
        ) {
            return null;
        }

        return $row;
    }

    /**
     * @return array{status: string, user: ?User}
     */
    public function confirm(string $tokenValue): array
    {
        $row = $this->peek($tokenValue);

        if ($row === null) {
            return ['status' => self::CONFIRM_INVALID, 'user' => null];
        }

        // Someone may have taken the name or address since the form was submitted.
        if ($this->users->usernameOrEmailExists((string) $row['username'], (string) $row['email'])) {
            $this->deleteRow((int) $row['id']);

            return ['status' => self::CONFIRM_INVALID, 'user' => null];
        }

        if ($this->requiresApproval()) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET status = :status, token_hash = :token_hash, expires_at = :expires_at WHERE id = :id',
                [
                    'status' => self::STATUS_AWAITING_APPROVAL,
                    // The link is spent: a fresh random hash that no token can match.
                    'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
                    'expires_at' => date('Y-m-d H:i:s', time() + self::APPROVAL_LIFETIME_SECONDS),
                    'id' => $row['id'],
                ],
            );

            $this->notifyAdministrators(
                'registration_awaiting_approval',
                sprintf('%s signed up and is waiting for your approval.', (string) $row['username']),
                admin_url('users') . '#pending-signups',
            );

            return ['status' => self::CONFIRM_AWAITING_APPROVAL, 'user' => null];
        }

        $user = $this->createAccount($row);
        $this->deleteRow((int) $row['id']);

        return ['status' => self::CONFIRM_CREATED, 'user' => $user];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function awaitingApproval(): array
    {
        $this->purgeExpired();

        return $this->database->fetchAll(
            'SELECT id, username, email, display_name, ip_address, created_at FROM ' . $this->table() . '
             WHERE status = :status ORDER BY created_at ASC',
            ['status' => self::STATUS_AWAITING_APPROVAL],
        );
    }

    public function approve(int $pendingId): ?User
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE id = :id AND status = :status',
            ['id' => $pendingId, 'status' => self::STATUS_AWAITING_APPROVAL],
        );

        if ($row === null) {
            return null;
        }

        $this->deleteRow($pendingId);

        if ($this->users->usernameOrEmailExists((string) $row['username'], (string) $row['email'])) {
            return null;
        }

        $user = $this->createAccount($row);

        $this->mailer->send(
            $user->email,
            sprintf('Your %s account was approved', site_name()),
            sprintf("Your account on %s was approved. You can log in now:\n\n%s", site_name(), home_url('admin/login')),
        );

        return $user;
    }

    /**
     * Rejecting sends nothing: a reply to a possible bot only confirms that the address is read.
     */
    public function reject(int $pendingId): bool
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE id = :id AND status = :status',
            ['id' => $pendingId, 'status' => self::STATUS_AWAITING_APPROVAL],
        ) > 0;
    }

    public function purgeExpired(): int
    {
        try {
            return $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE expires_at < :now',
                ['now' => date('Y-m-d H:i:s')],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return 0;
        }
    }

    public function countPending(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table());
    }

    /**
     * Removes every pending sign-up for an address, for a privacy erasure request.
     */
    public function deleteByEmail(string $email): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE email = :email',
            ['email' => strtolower(trim($email))],
        );
    }

    /**
     * Logged and announced once, by the caller that tripped the breaker.
     */
    public function announcePause(string $ipAddress): void
    {
        $minutes = max(1, (int) $this->config->option('registration_pause_minutes', '60'));

        $this->securityLog->record(SecurityLog::EVENT_REGISTRATION_PAUSED, $ipAddress, "paused for {$minutes} min");
        $this->notifyAdministrators(
            'registration_paused',
            sprintf('Sign-ups were paused for %d minutes because of a burst of submissions.', $minutes),
            admin_url('settings/registration'),
        );
    }

    public function spamCheck(): RegistrationSpamCheck
    {
        return new RegistrationSpamCheck(
            blockedDomains: RegistrationSpamCheck::parseDomains((string) $this->config->option('registration_domain_blocklist', '')),
            allowedDomains: RegistrationSpamCheck::parseDomains((string) $this->config->option('registration_domain_allowlist', '')),
            blockDisposable: $this->config->option('registration_block_disposable', '1') !== '0',
            blockedIpRules: IpRangeMatcher::parseList((string) $this->config->option('comment_ip_blacklist', '')),
            question: $this->question(),
            expectedAnswer: (string) $this->config->option('registration_answer', ''),
        );
    }

    /**
     * Mistakes a real visitor can fix, so they are told. None of these reveal whether an email
     * address already has an account.
     *
     * @return list<string>
     */
    private function visibleErrors(string $username, string $email, string $displayName, string $password, string $passwordConfirm, string $answer, string $ipAddress): array
    {
        $errors = [];

        if (!RegistrationSpamCheck::isAcceptableUsername($username)) {
            $errors[] = 'Choose a username of 3 to 60 characters using letters, numbers, spaces, dots, dashes, or underscores.';
        }

        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }

        if ($displayName !== '' && !RegistrationSpamCheck::isAcceptableDisplayName($displayName)) {
            $errors[] = 'The display name can\'t contain links or email addresses.';
        }

        $passwordProblem = (new PasswordPolicy($this->config))->violation($password);

        if ($passwordProblem !== null) {
            $errors[] = $passwordProblem;
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'The passwords do not match.';
        }

        $reasons = $errors === [] ? $this->spamCheck()->reasons($username, $email, $displayName, $answer, $ipAddress) : [];

        if (array_intersect($reasons, [RegistrationSpamCheck::REASON_BLOCKED_DOMAIN, RegistrationSpamCheck::REASON_DOMAIN_NOT_ALLOWED, RegistrationSpamCheck::REASON_DISPOSABLE_DOMAIN]) !== []) {
            $errors[] = 'We can\'t accept sign-ups from that email address. Please use a different one.';
        }

        if (in_array(RegistrationSpamCheck::REASON_WRONG_ANSWER, $reasons, true)) {
            $errors[] = 'That answer was not right. Please try again.';
        }

        if ($errors === [] && $this->usernameTaken($username, $email)) {
            $errors[] = 'That username is already taken. Please choose another.';
        }

        return $errors;
    }

    /**
     * Signals that are never shown to the visitor, so a bot can't tune against them: the
     * administrator's blocked IP list, Akismet, and any plugin's own checks.
     *
     * @return list<string>
     */
    private function silentReasons(string $username, string $email, string $displayName, string $answer, string $ipAddress, string $userAgent): array
    {
        $reasons = array_values(array_intersect(
            $this->spamCheck()->reasons($username, $email, $displayName, $answer, $ipAddress),
            [RegistrationSpamCheck::REASON_BLOCKED_IP],
        ));

        if ($reasons === [] && $this->akismet !== null && $this->config->option('registration_use_akismet', '0') === '1' && $this->akismet->isEnabled()) {
            $isSpam = $this->akismet->checkComment([
                'comment_type' => 'signup',
                'comment_author' => $username,
                'comment_author_email' => $email,
                'comment_author_url' => null,
                'comment_content' => $displayName,
                'user_ip' => $ipAddress,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
                'referrer' => null,
                'permalink' => home_url('admin/register'),
            ]);

            // A null answer means Akismet could not be reached, which must not block anyone.
            if ($isSpam === true) {
                $reasons[] = 'akismet';
            }
        }

        $filtered = $this->hooks->applyFilters('registration_spam_reasons', $reasons, [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'ip_address' => $ipAddress,
        ]);

        return is_array($filtered) ? array_values(array_filter($filtered, 'is_string')) : $reasons;
    }

    private function usernameTaken(string $username, string $email): bool
    {
        if ($this->users->findByUsername($username) !== null) {
            return true;
        }

        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE username = :username AND email <> :email',
            ['username' => $username, 'email' => $email],
        ) > 0;
    }

    /**
     * Counted from the attempts table, which already holds this submission, so "2" means the
     * visitor's second request for the same address within the hour.
     */
    private function emailMayBeMailed(string $email): bool
    {
        try {
            $recent = (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM ' . $this->tablePrefix . 'registration_attempts WHERE email = :email AND attempted_at >= :since',
                ['email' => $email, 'since' => date('Y-m-d H:i:s', time() - 3600)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return true;
        }

        return $recent <= self::MAX_EMAILS_PER_ADDRESS_PER_HOUR;
    }

    private function issuePending(string $username, string $email, string $displayName, string $password, string $ipAddress): void
    {
        try {
            $existing = $this->database->fetchOne('SELECT status FROM ' . $this->table() . ' WHERE email = :email', ['email' => $email]);

            // A sign-up already waiting for an administrator is left alone; sending a new
            // link would only reset it.
            if ($existing !== null && $existing['status'] === self::STATUS_AWAITING_APPROVAL) {
                return;
            }

            $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE email = :email', ['email' => $email]);

            $selector = bin2hex(random_bytes(12));
            $validator = bin2hex(random_bytes(32));

            $this->database->execute(
                'INSERT INTO ' . $this->table() . '
                    (username, email, display_name, password_hash, selector, token_hash, status, ip_address, created_at, expires_at)
                 VALUES (:username, :email, :display_name, :password_hash, :selector, :token_hash, :status, :ip_address, :created_at, :expires_at)',
                [
                    'username' => $username,
                    'email' => $email,
                    'display_name' => $displayName,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'selector' => $selector,
                    'token_hash' => hash('sha256', $validator),
                    'status' => self::STATUS_UNCONFIRMED,
                    'ip_address' => mb_substr($ipAddress, 0, 45),
                    'created_at' => date('Y-m-d H:i:s'),
                    'expires_at' => date('Y-m-d H:i:s', time() + self::CONFIRM_LIFETIME_SECONDS),
                ],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return;
        }

        $link = home_url('admin/confirm-registration') . '?token=' . urlencode($selector . ':' . $validator);

        $this->mailer->send(
            $email,
            sprintf('Confirm your %s account', site_name()),
            sprintf(
                "Someone, hopefully you, signed up on %s with this email address.\n\nConfirm your account: %s\n\nThis link expires in 48 hours. If you didn't sign up, you can ignore this email and nothing will be created.",
                site_name(),
                $link,
            ),
        );
    }

    private function sendAlreadyRegisteredEmail(string $email): void
    {
        $this->mailer->send(
            $email,
            sprintf('You already have a %s account', site_name()),
            sprintf(
                "Someone tried to sign up on %s with this email address, but it already has an account.\n\nLog in: %s\nForgot your password? %s\n\nIf this wasn't you, you can ignore this email.",
                site_name(),
                home_url('admin/login'),
                home_url('admin/forgot-password'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $row a pending_registrations row
     */
    private function createAccount(array $row): User
    {
        $user = $this->users->createFromPasswordHash(
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['password_hash'],
            $this->defaultRole(),
            (string) $row['display_name'] !== '' ? (string) $row['display_name'] : null,
            null,
            'signup',
        );

        $this->hooks->doAction('user_registered', $user);

        return $user;
    }

    private function notifyAdministrators(string $type, string $message, string $url): void
    {
        try {
            $this->notifications->notifyUsersWithCapability($this->users, 'manage_users', $type, $message, $url);
        } catch (Throwable $exception) {
            // A failed notice must never break the sign-up that triggered it.
            $this->logFailure($exception);
        }
    }

    private function deleteRow(int $id): void
    {
        $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[RegistrationService] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'pending_registrations';
    }
}
