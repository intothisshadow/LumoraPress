<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use LumoraPress\Models\ContentFormat;
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

    /**
     * LP-008's public author archives (`/author/{slug}`) — there's no
     * dedicated `slug` column on users, so this slugifies every
     * username the same way PostService/CategoryService/etc. already
     * slugify their own titles/names (see this class's own slugify())
     * and compares against $slug, rather than adding a new schema
     * column just for this. Fine at the scale of a typical blog's
     * author list; listAll() is already used unbounded elsewhere (e.g.
     * the admin Users screen).
     */
    public function findByAuthorSlug(string $slug): ?User
    {
        foreach ($this->listAll() as $user) {
            if ($this->slugify($user->username) === $slug) {
                return $user;
            }
        }

        return null;
    }

    /**
     * The URL-safe slug for $user's author archive — see
     * findByAuthorSlug()'s docblock for why this is computed rather than
     * stored.
     */
    public function authorSlug(User $user): string
    {
        return $this->slugify($user->username);
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'user' : $slug;
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
            'SELECT * FROM ' . $this->table() . '
                WHERE (username = :identity1 OR email = :identity2) AND trashed_at IS NULL',
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

    /**
     * LP-066/LP-067: $format = null means "use the site default editor"
     * (the profile page's "Use site default" option) — stored as SQL NULL,
     * not an empty string, so it's unambiguous from "never set."
     */
    public function updateEditorPreference(int $id, ?ContentFormat $format): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET preferred_editor = :preferred_editor WHERE id = :id',
            ['preferred_editor' => $format?->value, 'id' => $id],
        );
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Soft-deletes a user: sets trashed_at rather than removing the row.
     * A trashed user cannot authenticate (see verifyCredentials()) and is
     * excluded from listAll()/paginateForAdmin() by default, but remains
     * fully recoverable via restore() until it's permanently removed with
     * delete().
     */
    public function trash(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = :trashed_at WHERE id = :id',
            ['trashed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = NULL WHERE id = :id',
            ['id' => $id],
        ) > 0;
    }

    public function countTrashed(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE trashed_at IS NOT NULL',
        );
    }

    /**
     * @return array<int, User>
     */
    public function listAll(bool $includeTrashed = false): array
    {
        $sql = 'SELECT * FROM ' . $this->table();

        if (!$includeTrashed) {
            $sql .= ' WHERE trashed_at IS NULL';
        }

        $rows = $this->database->fetchAll($sql . ' ORDER BY username ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Search/filter/paginate for the admin Users screen (LP-032). Mirrors
     * PostService::paginateForAdmin()'s shape. $trashedOnly toggles between
     * the normal list (trashed_at IS NULL) and the Trash view (trashed_at
     * IS NOT NULL) — there is no "both" mode, matching the Posts admin's
     * own Trash-is-a-separate-tab convention.
     *
     * Three distinct LIKE placeholders (:term/:term2/:term3) rather than
     * one reused three times — Database::connect() disables emulated
     * prepares, and MySQL's native protocol rejects a repeated named
     * placeholder (see PostService::paginateForAdmin()'s own docblock and
     * PHP-TEST-SUITE.md's "Known gaps").
     *
     * @return array{users: array<int, User>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(
        int $page = 1,
        int $perPage = 20,
        ?UserRole $roleFilter = null,
        string $term = '',
        bool $trashedOnly = false,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $conditions = [$trashedOnly ? 'trashed_at IS NOT NULL' : 'trashed_at IS NULL'];
        $params = [];

        if ($roleFilter !== null) {
            $conditions[] = 'role = :role';
            $params['role'] = $roleFilter->value;
        }

        if ($term !== '') {
            $conditions[] = '(username LIKE :term OR email LIKE :term2 OR display_name LIKE :term3)';
            $params['term'] = '%' . $term . '%';
            $params['term2'] = '%' . $term . '%';
            $params['term3'] = '%' . $term . '%';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . " {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " {$where} ORDER BY username ASC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'users' => array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    public function updateAvatar(int $id, ?int $mediaId): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET avatar_media_id = :avatar_media_id WHERE id = :id',
            ['avatar_media_id' => $mediaId, 'id' => $id],
        );
    }

    /**
     * The default avatar for a user with no uploaded avatar_media_id.
     * Gravatar's newer avatar API accepts a SHA256 hash of the lowercased,
     * trimmed email address (alongside its legacy MD5 form, which this
     * project avoids per its own "never MD5" rule even outside a password
     * context). `d=mp` falls back to a generic silhouette ("mystery
     * person") for any email with no registered Gravatar, so this never
     * needs a null-check at the call site.
     */
    public function gravatarUrl(string $email, int $size = 96): string
    {
        $hash = hash('sha256', strtolower(trim($email)));

        return 'https://www.gravatar.com/avatar/' . $hash . '?s=' . $size . '&d=mp';
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
            preferredEditor: isset($row['preferred_editor'])
                ? ContentFormat::tryFrom((string) $row['preferred_editor'])
                : null,
            trashedAt: isset($row['trashed_at']) ? new \DateTimeImmutable((string) $row['trashed_at']) : null,
            avatarMediaId: isset($row['avatar_media_id']) ? (int) $row['avatar_media_id'] : null,
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'users';
    }
}
