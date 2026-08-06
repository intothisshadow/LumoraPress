<?php

/**
 * The admin Users screen: list, create, edit, and manage user accounts.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\UserRole;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$userService = $kernel->users;

/**
 * Selectable roles for the admin form. Guest is not a stored account role
 * (it represents an unauthenticated visitor), so it never appears here.
 *
 * @return array<int, UserRole>
 */
$selectableRoles = static fn (): array => array_values(array_filter(
    UserRole::cases(),
    static fn (UserRole $role): bool => $role !== UserRole::Guest,
));

/**
 * A user can be trashed or permanently deleted only if: they are not the
 * account currently signed in, they are not the last remaining
 * Administrator, and they have not authored any posts or pages — there is
 * no admin reassignment UI yet, so removing them would orphan that
 * content's author_id. The same three guards apply to both actions (and to
 * the equivalent bulk actions below), since trashing also disables the
 * account's ability to log in.
 */
$actionBlockReason = function (\LumoraPress\Models\User $target) use ($currentUser, $userService, $kernel): ?string {
    if ($target->id === $currentUser->id) {
        return 'You cannot remove your own account.';
    }

    if ($target->role === UserRole::Administrator && $userService->countByRole(UserRole::Administrator) <= 1) {
        return 'This is the last remaining Administrator.';
    }

    if ($kernel->posts->countByAuthor($target->id) > 0 || $kernel->pages->countByAuthor($target->id) > 0) {
        return 'This user has authored posts or pages. Reassign or remove that content first.';
    }

    return null;
};

/**
 * Applies an uploaded/removed avatar to $userId from the current request.
 * Returns an error message on upload failure, or null on success/no-op.
 */
$applyAvatarFromRequest = function (int $userId) use ($kernel, $userService, $currentUser): ?string {
    if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $media = $kernel->media->upload($_FILES['avatar'], $currentUser->id);
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }

        if (!str_starts_with((string) $media['mime_type'], 'image/')) {
            $kernel->media->delete((int) $media['id']);

            return 'Avatars must be an image file.';
        }

        $userService->updateAvatar($userId, (int) $media['id']);
    } elseif (($_POST['remove_avatar'] ?? null) === '1') {
        $userService->updateAvatar($userId, null);
    }

    return null;
};

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'save' && Csrf::verify('user_save', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? $userService->findById($id) : null;

        if ($id > 0 && $existing === null) {
            header('Location: ' . admin_url('users') . '?error=forbidden');
            exit;
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $role = UserRole::tryFrom((string) ($_POST['role'] ?? '')) ?? UserRole::Subscriber;
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($role === UserRole::Guest) {
            $role = UserRole::Subscriber;
        }

        if ($username === '' || $email === '') {
            $error = 'Username and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($existing === null && $password === '') {
            $error = 'A password is required for a new user.';
        } elseif ($password !== '' && strlen($password) < 10) {
            $error = 'Password must be at least 10 characters long.';
        } elseif ($password !== '' && $password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } elseif ($existing === null && $userService->usernameOrEmailExists($username, $email)) {
            $error = 'That username or email is already in use.';
        } elseif ($existing !== null && $userService->usernameOrEmailExistsForOther($existing->id, $username, $email)) {
            $error = 'That username or email is already in use.';
        } elseif ($existing !== null && $existing->role === UserRole::Administrator && $role !== UserRole::Administrator
            && $userService->countByRole(UserRole::Administrator) <= 1) {
            $error = 'You cannot remove the last remaining Administrator.';
        } else {
            $user = $existing === null
                ? $userService->create($username, $email, $password, $role, $displayName !== '' ? $displayName : null)
                : $userService->update($existing->id, $username, $email, $role, $displayName !== '' ? $displayName : null);

            if ($existing !== null && $password !== '') {
                $userService->changePassword($existing->id, $password);
            }

            if (!$kernel->editorPreferences->isLockedToDefault() && isset($_POST['preferred_editor'])) {
                $preferredEditorInput = trim((string) $_POST['preferred_editor']);
                $userService->updateEditorPreference(
                    $user->id,
                    $preferredEditorInput === '' ? null : \LumoraPress\Models\ContentFormat::tryFrom($preferredEditorInput),
                );
            }

            $avatarError = $applyAvatarFromRequest($user->id);

            if ($avatarError !== null) {
                header('Location: ' . admin_url('users') . '?action=edit&id=' . $user->id . '&saved=1&avatar_error=' . urlencode($avatarError));
                exit;
            }

            header('Location: ' . admin_url('users') . '?action=edit&id=' . $user->id . '&saved=1');
            exit;
        }
    } elseif ($form === 'trash') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('user_trash_' . $id, $token)) {
            header('Location: ' . admin_url('users'));
            exit;
        }

        $target = $id > 0 ? $userService->findById($id) : null;

        if ($target !== null && $actionBlockReason($target) === null) {
            $userService->trash($target->id);
            $kernel->rememberMe->forgetUserTokens($target->id);
        }

        header('Location: ' . admin_url('users') . '?trashed=1');
        exit;
    } elseif ($form === 'restore') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('user_restore_' . $id, $token)) {
            header('Location: ' . admin_url('users') . '?status=trashed');
            exit;
        }

        $target = $id > 0 ? $userService->findById($id) : null;

        if ($target !== null) {
            $userService->restore($target->id);
        }

        header('Location: ' . admin_url('users') . '?status=trashed&restored=1');
        exit;
    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('user_delete_' . $id, $token)) {
            header('Location: ' . admin_url('users') . '?status=trashed');
            exit;
        }

        $target = $id > 0 ? $userService->findById($id) : null;

        // Permanent delete is only offered (and only honoured) for users
        // already in the Trash — Trash is the only reachable path to
        // actually removing a user from the active list, the same
        // "delete means trash first" guardrail the Posts admin enforces.
        if ($target !== null && $target->trashedAt !== null && $actionBlockReason($target) === null) {
            $userService->delete($target->id);
            $kernel->rememberMe->forgetUserTokens($target->id);
        }

        header('Location: ' . admin_url('users') . '?status=trashed&deleted=1');
        exit;
    } elseif ($form === 'bulk_action' && Csrf::verify('users_bulk_action', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : [])));
        $isTrashView = ($_POST['status'] ?? '') === 'trashed';
        $redirect = admin_url('users') . ($isTrashView ? '?status=trashed' : '');

        if ($bulkAction === 'change_role') {
            $targetRole = UserRole::tryFrom((string) ($_POST['target_role'] ?? ''));

            if ($targetRole !== null && $targetRole !== UserRole::Guest) {
                foreach ($ids as $id) {
                    $target = $userService->findById($id);

                    if ($target === null || $target->trashedAt !== null) {
                        continue;
                    }

                    if ($target->role === UserRole::Administrator && $targetRole !== UserRole::Administrator
                        && $userService->countByRole(UserRole::Administrator) <= 1) {
                        continue;
                    }

                    $userService->update($target->id, $target->username, $target->email, $targetRole, $target->displayName);
                }
            }

            header('Location: ' . $redirect);
            exit;
        }

        foreach ($ids as $id) {
            $target = $userService->findById($id);

            if ($target === null) {
                continue;
            }

            if ($bulkAction === 'trash' && $target->trashedAt === null && $actionBlockReason($target) === null) {
                $userService->trash($target->id);
                $kernel->rememberMe->forgetUserTokens($target->id);
            } elseif ($bulkAction === 'restore' && $target->trashedAt !== null) {
                $userService->restore($target->id);
            } elseif ($bulkAction === 'delete_permanently' && $target->trashedAt !== null && $actionBlockReason($target) === null) {
                $userService->delete($target->id);
                $kernel->rememberMe->forgetUserTokens($target->id);
            }
        }

        header('Location: ' . $redirect);
        exit;
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingUser = null;

if ($action === 'edit') {
    $editingUser = $editingId !== null ? $userService->findById($editingId) : null;

    if ($editingUser === null) {
        header('Location: ' . admin_url('users') . '?error=forbidden');
        exit;
    }
}

/**
 * @return string the URL of $target's avatar image at $size pixels —
 *     their uploaded avatar if one is set, otherwise Gravatar.
 */
$avatarUrl = function (\LumoraPress\Models\User $target, int $size = 32) use ($kernel, $userService): string {
    if ($target->avatarMediaId !== null) {
        $media = $kernel->media->find($target->avatarMediaId);

        if ($media !== null) {
            return $kernel->media->url($media);
        }
    }

    return $userService->gravatarUrl($target->email, $size);
};
?>
<h1 class="lp-admin__title">Users</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">User saved.</div>
<?php endif; ?>

<?php if (isset($_GET['avatar_error'])): ?>
    <div class="lp-alert lp-alert--error">Avatar not saved: <?= esc_html((string) $_GET['avatar_error']) ?></div>
<?php endif; ?>

<?php if (isset($_GET['trashed'])): ?>
    <div class="lp-alert lp-alert--success">User moved to Trash.</div>
<?php endif; ?>

<?php if (isset($_GET['restored'])): ?>
    <div class="lp-alert lp-alert--success">User restored.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">User permanently deleted.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">That user could not be found.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php $user = $editingUser; ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('users')) ?>" enctype="multipart/form-data">
            <?= Csrf::field('user_save') ?>
            <input type="hidden" name="form" value="save">
            <?php if ($user !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $user->id ?>">
            <?php endif; ?>

            <?php if ($user !== null): ?>
                <p class="lp-field">
                    <label>Avatar</label>
                    <img class="lp-user-avatar lp-user-avatar--large" src="<?= esc_url($avatarUrl($user, 96)) ?>" alt="" width="96" height="96">
                </p>
                <p class="lp-field">
                    <label for="user-avatar">Upload new avatar</label>
                    <input type="file" id="user-avatar" name="avatar" accept="image/*">
                    <?php if ($user->avatarMediaId !== null): ?>
                        <span class="lp-field__hint">
                            <label><input type="checkbox" name="remove_avatar" value="1"> Remove uploaded avatar (fall back to Gravatar)</label>
                        </span>
                    <?php else: ?>
                        <span class="lp-field__hint">No avatar uploaded — using Gravatar (based on the account email).</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <p class="lp-field">
                <label for="user-username">Username</label>
                <input type="text" id="user-username" name="username" value="<?= esc_attr($user->username ?? '') ?>" required>
            </p>

            <p class="lp-field">
                <label for="user-email">Email</label>
                <input type="email" id="user-email" name="email" value="<?= esc_attr($user->email ?? '') ?>" required>
            </p>

            <p class="lp-field">
                <label for="user-display-name">Display Name</label>
                <input type="text" id="user-display-name" name="display_name" value="<?= esc_attr($user->displayName ?? '') ?>">
                <span class="lp-field__hint">Leave blank to use the username.</span>
            </p>

            <p class="lp-field">
                <label for="user-role">Role</label>
                <select id="user-role" name="role">
                    <?php foreach ($selectableRoles() as $roleOption): ?>
                        <option value="<?= esc_attr($roleOption->value) ?>" <?= ($user->role ?? UserRole::Subscriber) === $roleOption ? 'selected' : '' ?>>
                            <?= esc_html($roleOption->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php $editorLocked = $kernel->editorPreferences->isLockedToDefault(); ?>
            <p class="lp-field">
                <label for="user-preferred-editor">Default editor</label>
                <select id="user-preferred-editor" name="preferred_editor" <?= $editorLocked ? 'disabled' : '' ?>>
                    <option value="">Use site default (<?= esc_html($kernel->editorPreferences->defaultEditor()->label()) ?>)</option>
                    <?php foreach ($kernel->editorPreferences->registeredEditors() as $editorOption): ?>
                        <option value="<?= esc_attr($editorOption['value']) ?>" <?= $user?->preferredEditor?->value === $editorOption['value'] ? 'selected' : '' ?>>
                            <?= esc_html($editorOption['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($editorLocked): ?>
                    <span class="lp-field__hint">Every account is currently locked to the site default editor (Settings &rsaquo; General).</span>
                <?php endif; ?>
            </p>

            <p class="lp-field">
                <label for="user-password"><?= $user === null ? 'Password' : 'New Password' ?></label>
                <input type="password" id="user-password" name="password" autocomplete="new-password" minlength="10" <?= $user === null ? 'required' : '' ?>>
                <span class="lp-field__hint"><?php if ($user === null): ?>At least 10 characters.<?php else: ?>Leave blank to keep the current password.<?php endif; ?></span>
            </p>

            <p class="lp-field">
                <label for="user-password-confirm"><?= $user === null ? 'Confirm Password' : 'Confirm New Password' ?></label>
                <input type="password" id="user-password-confirm" name="password_confirm" autocomplete="new-password" minlength="10" <?= $user === null ? 'required' : '' ?>>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save User</button>
            <a class="lp-button" href="<?= esc_url(admin_url('users')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('users')) ?>?action=new">Add New User</a></p>

    <?php
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $isTrashView = ($_GET['status'] ?? '') === 'trashed';
    $termFilter = trim((string) ($_GET['q'] ?? ''));
    $roleFilter = UserRole::tryFrom((string) ($_GET['role'] ?? ''));
    $pagination = $userService->paginateForAdmin(
        page: $page,
        roleFilter: $roleFilter,
        term: $termFilter,
        trashedOnly: $isTrashView,
    );
    $activeCount = $userService->paginateForAdmin(page: 1, perPage: 1)['total'];
    $trashedCount = $userService->countTrashed();
    ?>

    <p class="lp-admin__filters">
        <a href="<?= esc_url(admin_url('users')) ?>" class="<?= !$isTrashView ? 'is-active' : '' ?>">All (<?= (int) $activeCount ?>)</a>
        <a href="<?= esc_url(admin_url('users')) ?>?status=trashed" class="<?= $isTrashView ? 'is-active' : '' ?>">Trash (<?= (int) $trashedCount ?>)</a>
    </p>

    <section class="lp-admin__panel">
        <details class="lp-admin__collapsible">
            <summary>Search &amp; Filter</summary>
            <div class="lp-admin__collapsible__body">
                <form method="get" action="<?= esc_url(admin_url('users')) ?>" class="lp-admin__filter-form">
                    <?php if ($isTrashView): ?>
                        <input type="hidden" name="status" value="trashed">
                    <?php endif; ?>
                    <p class="lp-field">
                        <label for="users-q">Search username, email, or display name</label>
                        <input type="text" id="users-q" name="q" value="<?= esc_attr($termFilter) ?>">
                    </p>
                    <p class="lp-field">
                        <label for="users-role-filter">Role</label>
                        <select id="users-role-filter" name="role">
                            <option value="">All roles</option>
                            <?php foreach ($selectableRoles() as $roleOption): ?>
                                <option value="<?= esc_attr($roleOption->value) ?>" <?= $roleFilter === $roleOption ? 'selected' : '' ?>><?= esc_html($roleOption->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <button type="submit" class="lp-button">Filter</button>
                </form>
            </div>
        </details>
    </section>

    <section class="lp-admin__panel">
        <?php if ($pagination['users'] === []): ?>
            <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No users yet.' ?></p>
        <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('users')) ?>" data-lp-bulk-form>
                <?= Csrf::field('users_bulk_action') ?>
                <input type="hidden" name="form" value="bulk_action">
                <?php if ($isTrashView): ?>
                    <input type="hidden" name="status" value="trashed">
                <?php endif; ?>

                <p class="lp-admin__bulk-actions">
                    <label class="lp-visually-hidden" for="users-bulk-action">Bulk action</label>
                    <select id="users-bulk-action" name="bulk_action">
                        <option value="">Bulk actions</option>
                        <?php if ($isTrashView): ?>
                            <option value="restore">Restore</option>
                            <option value="delete_permanently">Delete Permanently</option>
                        <?php else: ?>
                            <option value="trash">Move to Trash</option>
                            <option value="change_role">Change role to&hellip;</option>
                        <?php endif; ?>
                    </select>
                    <?php if (!$isTrashView): ?>
                        <select name="target_role">
                            <?php foreach ($selectableRoles() as $bulkRoleOption): ?>
                                <option value="<?= esc_attr($bulkRoleOption->value) ?>"><?= esc_html($bulkRoleOption->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="lp-button lp-button--secondary">Apply</button>
                </p>

                <table class="lp-table">
                    <thead>
                        <tr>
                            <th scope="col">
                                <label class="lp-visually-hidden" for="users-select-all">Select all</label>
                                <input type="checkbox" id="users-select-all" data-lp-select-all="user_ids[]" data-lp-select-all-scope="table">
                            </th>
                            <th scope="col"><span class="lp-visually-hidden">Avatar</span></th>
                            <th scope="col">Username</th>
                            <th scope="col">Display Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagination['users'] as $listedUser): ?>
                            <?php $blockReason = $actionBlockReason($listedUser); ?>
                            <tr>
                                <td>
                                    <?php if ($listedUser->id !== $currentUser->id): ?>
                                        <label class="lp-visually-hidden" for="user-select-<?= (int) $listedUser->id ?>">Select "<?= esc_html($listedUser->username) ?>"</label>
                                        <input type="checkbox" id="user-select-<?= (int) $listedUser->id ?>" name="user_ids[]" value="<?= (int) $listedUser->id ?>">
                                    <?php endif; ?>
                                </td>
                                <td><img class="lp-user-avatar" src="<?= esc_url($avatarUrl($listedUser)) ?>" alt="" width="32" height="32"></td>
                                <td>
                                    <?php if (!$isTrashView): ?>
                                        <a href="<?= esc_url(admin_url('users')) ?>?action=edit&id=<?= (int) $listedUser->id ?>"><?= esc_html($listedUser->username) ?></a>
                                    <?php else: ?>
                                        <?= esc_html($listedUser->username) ?>
                                    <?php endif; ?>
                                    <?= $listedUser->id === $currentUser->id ? ' <span class="lp-admin__badge">You</span>' : '' ?>
                                </td>
                                <td><?= esc_html($listedUser->displayName) ?></td>
                                <td><?= esc_html($listedUser->email) ?></td>
                                <td><?= esc_html($listedUser->role->label()) ?></td>
                                <td class="lp-admin__row-actions">
                                    <?php if ($isTrashView): ?>
                                        <?php $restoreFormId = 'user-restore-form-' . $listedUser->id; ?>
                                        <span class="lp-admin__inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('user_restore_' . $listedUser->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                            <input type="hidden" name="form" value="restore" form="<?= esc_attr($restoreFormId) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $listedUser->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                            <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                        </span>
                                        <?php if ($blockReason === null): ?>
                                            <?php $deleteFormId = 'user-delete-form-' . $listedUser->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('user_delete_' . $listedUser->id)) ?>" form="<?= esc_attr($deleteFormId) ?>">
                                                <input type="hidden" name="form" value="delete" form="<?= esc_attr($deleteFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedUser->id ?>" form="<?= esc_attr($deleteFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deleteFormId) ?>" data-lp-confirm="Permanently delete this user? This cannot be undone.">Delete Permanently</button>
                                            </span>
                                        <?php else: ?>
                                            <span class="lp-field__hint" title="<?= esc_attr($blockReason) ?>">Cannot delete</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($blockReason === null): ?>
                                            <?php $trashFormId = 'user-trash-form-' . $listedUser->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('user_trash_' . $listedUser->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedUser->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this user to the Trash?">Trash</button>
                                            </span>
                                        <?php else: ?>
                                            <span class="lp-field__hint" title="<?= esc_attr($blockReason) ?>">Cannot delete</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php
            /*
             * Out-of-band target forms for each row action button above —
             * see admin/views/posts/all-posts.php's own docblock for why a
             * nested <form> can't be used here: the browser's parse-error
             * recovery would silently close the outer bulk-action form as
             * soon as it hit the first inner </form> tag.
             */
            foreach ($pagination['users'] as $listedUser):
                if ($isTrashView):
                    ?>
                    <form id="user-restore-form-<?= (int) $listedUser->id ?>" method="post" action="<?= esc_url(admin_url('users')) ?>"></form>
                    <?php if ($actionBlockReason($listedUser) === null): ?>
                        <form id="user-delete-form-<?= (int) $listedUser->id ?>" method="post" action="<?= esc_url(admin_url('users')) ?>"></form>
                    <?php endif; ?>
                    <?php
                elseif ($actionBlockReason($listedUser) === null):
                    ?>
                    <form id="user-trash-form-<?= (int) $listedUser->id ?>" method="post" action="<?= esc_url(admin_url('users')) ?>"></form>
                    <?php
                endif;
            endforeach;
            ?>

            <?php render_pagination($pagination, 'Users pagination'); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
