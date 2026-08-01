<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use LumoraPress\Models\User;
use LumoraPress\Models\UserRole;

/**
 * User lookup, credential verification, and account creation. All queries
 * are parameterized; passwords are always handled via password_hash()/
 * password_verify().
 */
final class UserService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function findById(int $id): ?User
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByUsername(string $username): ?User
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE username = :username',
            ['username' => $username],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE LOWER(email) = LOWER(:email)',
            ['email' => $email],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function usernameOrEmailExists(string $username, string $email): bool
    {
        $row = $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE username = :username OR email = :email',
            ['username' => $username, 'email' => $email],
        );

        return $row !== null;
    }

    /**
     * Same check as usernameOrEmailExists(), but excluding $excludeId — for
     * validating an edit form, where the user's own current username/email
     * must not collide with itself.
     */
    public function usernameOrEmailExistsForOther(int $excludeId, string $username, string $email): bool
    {
        $row = $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . '
                WHERE (username = :username OR email = :email) AND id != :exclude_id',
            ['username' => $username, 'email' => $email, 'exclude_id' => $excludeId],
        );

        return $row !== null;
    }

    public function verifyCredentials(string $usernameOrEmail, string $password): ?User
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE username = :identity1 OR email = :identity2',
            ['identity1' => $usernameOrEmail, 'identity2' => $usernameOrEmail],
        );

        if ($row === null || !password_verify($password, $row['password_hash'])) {
            return null;
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET password_hash = :hash WHERE id = :id',
                ['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['id']],
            );
        }

        return $this->hydrate($row);
    }

    public function create(string $username, string $email, string $password, UserRole $role, ?string $displayName = null): User
    {
        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (username, email, password_hash, display_name, role, created_at)
             VALUES (:username, :email, :password_hash, :display_name, :role, :created_at)',
            [
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => $displayName ?? $username,
                'role' => $role->value,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );

        $user = $this->findById((int) $id);

        if ($user === null) {
            throw new \RuntimeException('Failed to load the user that was just created.');
        }

        return $user;
    }

    public function update(int $id, string $username, string $email, UserRole $role, ?string $displayName = null): User
    {
        $existing = $this->findById($id);

        if ($existing === null) {
            throw new \RuntimeException("User {$id} does not exist.");
        }

        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET username = :username, email = :email, display_name = :display_name, role = :role
              WHERE id = :id',
            [
                'username' => $username,
                'email' => $email,
                'display_name' => $displayName ?? $username,
                'role' => $role->value,
                'id' => $id,
            ],
        );

        $user = $this->findById($id);

        if ($user === null) {
            throw new \RuntimeException('Failed to load the user that was just updated.');
        }

        return $user;
    }

    public function changePassword(int $id, string $newPassword): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET password_hash = :hash WHERE id = :id',
            ['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $id],
        );
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * @return array<int, User>
     */
    public function listAll(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' ORDER BY username ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Used to guard against removing the last remaining Administrator,
     * whether by deletion or by demoting their role.
     */
    public function countByRole(UserRole $role): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE role = :role',
            ['role' => $role->value],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            displayName: (string) ($row['display_name'] ?? $row['username']),
            role: UserRole::from((string) $row['role']),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'users';
    }
}
