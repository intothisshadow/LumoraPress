<?php

/**
 * User lookup, credential verification, and account creation.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\ThemePreference;
use LumoraPress\Models\User;
use LumoraPress\Models\UserRole;

/**
 * User lookup, credential verification, and account creation. All queries
 * are parameterized; passwords are always handled via password_hash()/
 * password_verify().
 */
final class UserService
{
    /**
     * Per-request memoization for findById() — an archive/index render
     * calls the_author_link() once per post, and the same author id
     * typically repeats. Every write method below that can change a
     * cached row must evict it.
     *
     * @var array<int, User|null>
     */
    private array $findByIdCache = [];

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function findById(int $id): ?User
    {
        if (array_key_exists($id, $this->findByIdCache)) {
            return $this->findByIdCache[$id];
        }

        $row = $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE id = :id',
            ['id' => $id],
        );

        return $this->findByIdCache[$id] = ($row === null ? null : $this->hydrate($row));
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
     * Public author archives (`/author/{slug}`) — there's no dedicated
     * `slug` column on users, so this slugifies every username and
     * compares against $slug rather than adding a new schema column.
     * Fine at the scale of a typical blog's author list.
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

    /**
     * Obviously guessable usernames for the highest-value role. Not
     * applied to Editor/Author/Contributor, where a guessable login
     * username is a smaller blast radius than for Administrator.
     */
    private const GUESSABLE_ADMINISTRATOR_USERNAMES = ['admin', 'administrator', 'root', 'webmaster', 'superuser', 'owner'];

    /**
     * A post's public byline is the account's Display Name, so an
     * identical login username and Display Name effectively publishes
     * half of an admin/staff account's credentials. Checked explicitly
     * by the installer and Add/Edit User screen before calling
     * create()/update(), not enforced inside them, since the Importer
     * and Dummy Content generator shouldn't abort a whole batch over this.
     */
    public static function usernameMatchesDisplayName(string $username, string $displayName): bool
    {
        return mb_strtolower(trim($username)) === mb_strtolower(trim($displayName));
    }

    public static function isGuessableAdministratorUsername(string $username): bool
    {
        return in_array(mb_strtolower(trim($username)), self::GUESSABLE_ADMINISTRATOR_USERNAMES, true);
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

    /**
     * $registeredAt lets a bulk importer (e.g. a WordPress import)
     * preserve a source account's original registration date instead of
     * always stamping "now" — every other caller leaves it null.
     */
    public function create(
        string $username,
        string $email,
        string $password,
        UserRole $role,
        ?string $displayName = null,
        ?\DateTimeImmutable $registeredAt = null,
    ): User {
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
                'created_at' => ($registeredAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
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

        unset($this->findByIdCache[$id]);
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

        unset($this->findByIdCache[$id]);
    }

    /**
     * $format = null means "use the site default editor" (the profile
     * page's "Use site default" option) — stored as SQL NULL,
     * not an empty string, so it's unambiguous from "never set."
     */
    public function updateEditorPreference(int $id, ?ContentFormat $format): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET preferred_editor = :preferred_editor WHERE id = :id',
            ['preferred_editor' => $format?->value, 'id' => $id],
        );

        unset($this->findByIdCache[$id]);
    }

    /**
     * Unlike updateEditorPreference(), $preference is never null — Auto
     * (follow the OS/browser setting) is itself a stored value here,
     * since there's no site-wide theme setting to defer to.
     */
    public function updateThemePreference(int $id, ThemePreference $preference): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET theme_preference = :theme_preference WHERE id = :id',
            ['theme_preference' => $preference->value, 'id' => $id],
        );

        unset($this->findByIdCache[$id]);
    }

    /**
     * The saved item order/collapse state for a sortable.js group, for
     * the given screen type. An empty 'order' means "nothing saved
     * yet" — the caller falls back to its own built-in default item list.
     *
     * @return array{order: array<int, string>, collapsed: array<int, string>}
     */
    public function getEditorLayoutPreferences(int $id, string $screenType): array
    {
        $user = $this->findById($id);
        $decoded = json_decode($user?->editorLayoutPreferences ?? '{}', true);
        $forScreen = is_array($decoded) ? ($decoded[$screenType] ?? null) : null;

        return [
            'order' => is_array($forScreen['order'] ?? null) ? array_values(array_map('strval', $forScreen['order'])) : [],
            'collapsed' => is_array($forScreen['collapsed'] ?? null) ? array_values(array_map('strval', $forScreen['collapsed'])) : [],
        ];
    }

    /**
     * Persists $order/$collapsed for one screen type without disturbing
     * the other's saved state — the column holds both under one JSON
     * blob, so this is a read-modify-write.
     *
     * @param array<int, string> $order
     * @param array<int, string> $collapsed
     */
    public function updateEditorLayoutPreferences(int $id, string $screenType, array $order, array $collapsed): void
    {
        $user = $this->findById($id);
        $decoded = json_decode($user?->editorLayoutPreferences ?? '{}', true);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $decoded[$screenType] = ['order' => array_values($order), 'collapsed' => array_values($collapsed)];

        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET editor_layout_preferences = :editor_layout_preferences WHERE id = :id',
            ['editor_layout_preferences' => json_encode($decoded), 'id' => $id],
        );

        unset($this->findByIdCache[$id]);
    }

    /**
     * This user's saved Grid/List view-mode choice for the given admin
     * list screen, defaulting to 'grid' since every such screen only had
     * a grid layout before this feature.
     */
    public function getListViewMode(int $id, string $screenType): string
    {
        $user = $this->findById($id);
        $decoded = json_decode($user?->listViewPreferences ?? '{}', true);
        $forScreen = is_array($decoded) ? ($decoded[$screenType] ?? null) : null;

        return $forScreen === 'list' ? 'list' : 'grid';
    }

    /**
     * Persists $mode for one screen type without disturbing another
     * screen's saved choice — same read-modify-write shape as updateEditorLayoutPreferences().
     */
    public function setListViewMode(int $id, string $screenType, string $mode): void
    {
        $mode = $mode === 'list' ? 'list' : 'grid';
        $user = $this->findById($id);
        $decoded = json_decode($user?->listViewPreferences ?? '{}', true);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $decoded[$screenType] = $mode;

        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET list_view_preferences = :list_view_preferences WHERE id = :id',
            ['list_view_preferences' => json_encode($decoded), 'id' => $id],
        );

        unset($this->findByIdCache[$id]);
    }

    /**
     * The Media Manager folder ids this user currently has collapsed in
     * the sidebar tree — everything else defaults to expanded, so a user
     * who's never touched a toggle sees every folder open.
     *
     * @return array<int, int>
     */
    public function getCollapsedMediaFolders(int $id): array
    {
        $user = $this->findById($id);
        $decoded = json_decode($user?->folderTreeState ?? '[]', true);

        return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
    }

    /**
     * @param array<int, int> $collapsedFolderIds
     */
    public function setCollapsedMediaFolders(int $id, array $collapsedFolderIds): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET folder_tree_state = :folder_tree_state WHERE id = :id',
            ['folder_tree_state' => json_encode(array_values(array_unique(array_map('intval', $collapsedFolderIds)))), 'id' => $id],
        );

        unset($this->findByIdCache[$id]);
    }

    /**
     * This user's most recently inserted emoji, newest first — backs the
     * Emoji Picker's "Recently used" category. Mirrors
     * getCollapsedMediaFolders()'s identical flat-JSON-array-column
     * shape (no per-screen-type keying needed, unlike
     * getEditorLayoutPreferences()/getListViewMode() — there is only one
     * "recently used emoji" list per user, not one per screen).
     *
     * @return array<int, string>
     */
    public function getRecentEmoji(int $id): array
    {
        $user = $this->findById($id);
        $decoded = json_decode($user?->recentEmoji ?? '[]', true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * Moves $emoji to the front of the recently-used list (adding it if
     * new), trimmed to $limit entries. Returns the updated list so the
     * caller (the AJAX sub-action dispatched from posts/new.php etc.)
     * can hand it straight back to the picker without a second read.
     *
     * @return array<int, string>
     */
    public function addRecentEmoji(int $id, string $emoji, int $limit): array
    {
        $updated = array_slice(
            array_values(array_unique(array_merge([$emoji], $this->getRecentEmoji($id)))),
            0,
            max(0, $limit),
        );

        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET recent_emoji = :recent_emoji WHERE id = :id',
            ['recent_emoji' => json_encode($updated), 'id' => $id],
        );

        unset($this->findByIdCache[$id]);

        return $updated;
    }

    public function delete(int $id): bool
    {
        $deleted = $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;

        unset($this->findByIdCache[$id]);

        return $deleted;
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
        $trashed = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = :trashed_at WHERE id = :id',
            ['trashed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;

        unset($this->findByIdCache[$id]);

        return $trashed;
    }

    public function restore(int $id): bool
    {
        $restored = $this->database->execute(
            'UPDATE ' . $this->table() . ' SET trashed_at = NULL WHERE id = :id',
            ['id' => $id],
        ) > 0;

        unset($this->findByIdCache[$id]);

        return $restored;
    }

    public function countTrashed(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE trashed_at IS NOT NULL',
        );
    }

    /**
     * Total registered (non-trashed) user count — backs the Statistics
     * widget's user count, mirroring listAll()'s own
     * $includeTrashed default of excluding trashed accounts.
     */
    public function countAll(bool $includeTrashed = false): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->table();

        if (!$includeTrashed) {
            $sql .= ' WHERE trashed_at IS NULL';
        }

        return (int) $this->database->fetchColumn($sql);
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
     * Search/filter/paginate for the admin Users screen, mirroring PostService's shape.
     * $trashedOnly toggles between the normal list and Trash view; there is no "both" mode.
     * Uses three distinct LIKE placeholders rather than one reused, since MySQL's native
     * prepare protocol rejects a repeated named placeholder.
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

        unset($this->findByIdCache[$id]);
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
     * Stamps $id as active right now — called once per authenticated admin
     * request (see admin/index.php). This is the only source of "who's
     * currently active" data in the application (there is no DB-backed
     * session table — see SessionManager's docblock), so it deliberately
     * tracks the coarsest signal that's actually available: the last time
     * this user's session was seen making an admin request, not a live
     * connection count.
     */
    public function touchLastActive(int $id): void
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET last_active_at = :last_active_at WHERE id = :id',
            ['last_active_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );

        unset($this->findByIdCache[$id]);
    }

    /**
     * Display names of other users (never $excludeUserId, so an
     * administrator starting an update never sees a warning about their
     * own session) stamped active within the last $withinSeconds — used by
     * UpdateService's "Warn about active users" pre-update check.
     *
     * @return array<int, string>
     */
    public function activeUsernamesExcluding(int $excludeUserId, int $withinSeconds): array
    {
        $since = (new \DateTimeImmutable())->modify('-' . max(0, $withinSeconds) . ' seconds')->format('Y-m-d H:i:s');

        $rows = $this->database->fetchAll(
            'SELECT display_name FROM ' . $this->table() . '
                WHERE id != :exclude_id AND trashed_at IS NULL AND last_active_at IS NOT NULL AND last_active_at >= :since
             ORDER BY last_active_at DESC',
            ['exclude_id' => $excludeUserId, 'since' => $since],
        );

        return array_map(static fn (array $row): string => (string) $row['display_name'], $rows);
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
            editorLayoutPreferences: isset($row['editor_layout_preferences']) ? (string) $row['editor_layout_preferences'] : null,
            themePreference: isset($row['theme_preference'])
                ? (ThemePreference::tryFrom((string) $row['theme_preference']) ?? ThemePreference::Auto)
                : ThemePreference::Auto,
            listViewPreferences: isset($row['list_view_preferences']) ? (string) $row['list_view_preferences'] : null,
            folderTreeState: isset($row['folder_tree_state']) ? (string) $row['folder_tree_state'] : null,
            recentEmoji: isset($row['recent_emoji']) ? (string) $row['recent_emoji'] : null,
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'users';
    }
}
