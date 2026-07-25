<?php

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Models\User;
use LumoraPress\Services\UserService;
use Throwable;

/**
 * REST API authentication (LP-021): long-lived, named, revocable tokens —
 * WordPress's "Application Passwords", not a session. Structurally the
 * same selector (public, indexed) + validator (256-bit random, only its
 * SHA-256 hash stored, hash_equals()-compared) shape as RememberMeService,
 * for the same reason documented there (a high-entropy token doesn't need
 * a slow password_hash()-style hash — brute force isn't the threat model).
 *
 * Deliberately does **not** rotate on use, unlike RememberMeService: an
 * API client (a script, a mobile app, a CI job) needs the same token to
 * keep working across many requests indefinitely, only ever changing when
 * the user explicitly revokes it — the opposite requirement from a
 * browser's remember-me cookie, which should be single-use precisely so a
 * stolen value only ever works once. A wrong validator for a real selector
 * is just rejected here; there's no rotation to have been bypassed, so
 * there's no "signature of a stolen, already-rotated cookie being
 * replayed" to react to the way RememberMeService::validate() does.
 *
 * Tokens are named so a user can tell several apart (e.g. "Laptop script",
 * "CI job") and revoke one without affecting the others — sent as
 * "Authorization: Bearer {selector}:{validator}".
 */
final class ApiTokenService
{
    public function __construct(
        private readonly Database $database,
        private readonly UserService $users,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * Issues a fresh, named token for $userId. The raw token value is
     * returned once — only its hash is ever persisted, exactly like a
     * password never being stored in plaintext — so the caller must show
     * it to the user immediately and cannot retrieve it again later.
     *
     * @return array{id: int, token: string}|null null on a database failure
     */
    public function issueToken(int $userId, string $name): ?array
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        try {
            $id = $this->database->insertGetId(
                'INSERT INTO ' . $this->table() . ' (user_id, name, selector, token_hash, created_at)
                 VALUES (:user_id, :name, :selector, :token_hash, :created_at)',
                [
                    'user_id' => $userId,
                    'name' => $name,
                    'selector' => $selector,
                    'token_hash' => hash('sha256', $validator),
                    'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        return ['id' => (int) $id, 'token' => $selector . ':' . $validator];
    }

    /**
     * Resolves a bearer token to its owning User — null if malformed,
     * unknown, revoked, or the validator doesn't match. Updates
     * last_used_at on success (best-effort; a failure to record that
     * doesn't invalidate an otherwise-valid token).
     */
    public function validate(string $token): ?User
    {
        $parts = explode(':', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$selector, $validator] = $parts;

        try {
            $row = $this->database->fetchOne(
                'SELECT * FROM ' . $this->table() . ' WHERE selector = :selector',
                ['selector' => $selector],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            return null;
        }

        $user = $this->users->findById((int) $row['user_id']);

        if ($user === null) {
            return null;
        }

        $this->touchLastUsed((int) $row['id']);

        return $user;
    }

    /**
     * Revokes a token — scoped to $userId so a user can only ever revoke
     * their own tokens, never another user's by guessing an id.
     */
    public function revoke(int $tokenId, int $userId): bool
    {
        try {
            return $this->database->execute(
                'UPDATE ' . $this->table() . ' SET revoked_at = :revoked_at WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL',
                ['revoked_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $tokenId, 'user_id' => $userId],
            ) > 0;
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }
    }

    /**
     * Every token belonging to $userId — never includes token_hash/selector,
     * only what a "manage your tokens" screen needs to display.
     *
     * @return array<int, array{id: int, name: string, lastUsedAt: ?string, createdAt: string, revoked: bool}>
     */
    public function listForUser(int $userId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, name, last_used_at, created_at, revoked_at FROM ' . $this->table() . '
             WHERE user_id = :user_id ORDER BY created_at DESC',
            ['user_id' => $userId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'lastUsedAt' => $row['last_used_at'],
                'createdAt' => (string) $row['created_at'],
                'revoked' => $row['revoked_at'] !== null,
            ],
            $rows,
        );
    }

    private function touchLastUsed(int $id): void
    {
        try {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET last_used_at = :last_used_at WHERE id = :id',
                ['last_used_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[ApiTokenService] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'api_tokens';
    }
}
