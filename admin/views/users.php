<?php
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
 * A user can be deleted only if: they are not the account currently signed
 * in, they are not the last remaining Administrator, and they have not
 * authored any posts or pages — there is no admin reassignment UI yet, so
 * deleting them would orphan that content's author_id.
 */
$deletionBlockReason = function (\LumoraPress\Models\User $target) use ($currentUser, $userService, $kernel): ?string {
    if ($target->id === $currentUser->id) {
        return 'You cannot delete your own account.';
    }

    if ($target->role === UserRole::Administrator && $userService->countByRole(UserRole::Administrator) <= 1) {
        return 'This is the last remaining Administrator.';
    }

    if ($kernel->posts->countByAuthor($target->id) > 0 || $kernel->pages->countByAuthor($target->id) > 0) {
        return 'This user has authored posts or pages. Reassign or remove that content first.';
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

            header('Location: ' . admin_url('users') . '?action=edit&id=' . $user->id . '&saved=1');
            exit;
        }
    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('user_delete_' . $id, $token)) {
            header('Location: ' . admin_url('users'));
            exit;
        }

        $target = $id > 0 ? $userService->findById($id) : null;

        if ($target !== null && $deletionBlockReason($target) === null) {
            $userService->delete($target->id);
            $kernel->rememberMe->forgetUserTokens($target->id);
        }

        header('Location: ' . admin_url('users'));
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
?>
<h1 class="lp-admin__title">Users</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">User saved.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">That user could not be found.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php $user = $editingUser; ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('users')) ?>">
            <?= Csrf::field('user_save') ?>
            <input type="hidden" name="form" value="save">
            <?php if ($user !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $user->id ?>">
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

    <?php $rows = $userService->listAll(); ?>

    <section class="lp-admin__panel">
        <?php if ($rows === []): ?>
            <p class="lp-admin__widget-placeholder">No users yet.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Username</th>
                        <th scope="col">Display Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $listedUser): ?>
                        <?php $blockReason = $deletionBlockReason($listedUser); ?>
                        <tr>
                            <td>
                                <a href="<?= esc_url(admin_url('users')) ?>?action=edit&id=<?= (int) $listedUser->id ?>"><?= esc_html($listedUser->username) ?></a>
                                <?= $listedUser->id === $currentUser->id ? ' <span class="lp-admin__badge">You</span>' : '' ?>
                            </td>
                            <td><?= esc_html($listedUser->displayName) ?></td>
                            <td><?= esc_html($listedUser->email) ?></td>
                            <td><?= esc_html($listedUser->role->label()) ?></td>
                            <td>
                                <?php if ($blockReason === null): ?>
                                    <form method="post" action="<?= esc_url(admin_url('users')) ?>" onsubmit="return confirm('Delete this user permanently?');">
                                        <?= Csrf::field('user_delete_' . $listedUser->id) ?>
                                        <input type="hidden" name="form" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $listedUser->id ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="lp-field__hint" title="<?= esc_attr($blockReason) ?>">Cannot delete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>
