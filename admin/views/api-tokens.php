<?php

/**
 * The admin REST API personal access tokens screen (LP-021).
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

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * Self-service API token management (LP-021). Every authenticated role
 * can reach this page (see admin/index.php's $menu entry, capability
 * null) but every action here is scoped to $currentUser->id — a user can
 * only ever see or revoke their own tokens, never anyone else's
 * (ApiTokenService::revoke() also enforces this server-side, so this
 * isn't just a UI-level restriction). There is no cross-user token
 * oversight view yet (an administrator can't see other users' tokens
 * here) — deferred, not silently dropped.
 */
$error = null;
$issuedToken = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'create_token' && Csrf::verify('create_token', $token)) {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            $error = 'A name is required so you can tell your tokens apart later.';
        } else {
            $issued = $kernel->apiTokens->issueToken($currentUser->id, $name);

            if ($issued === null) {
                $error = 'Unable to create a token. Please try again.';
            } else {
                $issuedToken = $issued['token'];
            }
        }
    } elseif ($form === 'revoke_token') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('revoke_token_' . $id, $token)) {
            $kernel->apiTokens->revoke($id, $currentUser->id);
            header('Location: ' . admin_url('api-tokens') . '?revoked=1');
            exit;
        }
    }
}

$tokens = $kernel->apiTokens->listForUser($currentUser->id);
?>
<h1 class="lp-admin__title">API Tokens</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['revoked'])): ?>
    <div class="lp-alert lp-alert--success">Token revoked.</div>
<?php endif; ?>

<?php if ($issuedToken !== null): ?>
    <div class="lp-alert lp-alert--success">
        <p>Your new token (copy it now — it will not be shown again):</p>
        <code class="lp-api-token__value"><?= esc_html($issuedToken) ?></code>
        <p class="lp-field__hint">
            Send it as <code>Authorization: Bearer <?= esc_html($issuedToken) ?></code> when calling the REST API.
        </p>
    </div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Create a New Token</h2>
    <p class="lp-field__hint">
        API tokens let scripts and other applications act as you against the REST API
        (<code>/api/v1/...</code>) without knowing your password. Give each one a name so you can
        tell them apart and revoke just one without affecting the others.
    </p>
    <form method="post" action="<?= esc_url(admin_url('api-tokens')) ?>">
        <?= Csrf::field('create_token') ?>
        <input type="hidden" name="form" value="create_token">
        <p class="lp-field">
            <label for="token-name">Name</label>
            <input type="text" id="token-name" name="name" placeholder="e.g. Laptop script" required>
        </p>
        <button type="submit" class="lp-button lp-button--primary">Create Token</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Your Tokens</h2>
    <?php if ($tokens === []): ?>
        <p class="lp-admin__widget-placeholder">You have no API tokens yet.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last Used</th>
                    <th scope="col">Created</th>
                    <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tokens as $tokenRow): ?>
                    <tr>
                        <td><?= esc_html($tokenRow['name']) ?></td>
                        <td>
                            <?php if ($tokenRow['revoked']): ?>
                                <span class="lp-status-badge">Revoked</span>
                            <?php else: ?>
                                <span class="lp-status-badge lp-status-badge--approved">Active</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $tokenRow['lastUsedAt'] !== null ? esc_html($tokenRow['lastUsedAt']) : 'Never' ?></td>
                        <td><?= esc_html($tokenRow['createdAt']) ?></td>
                        <td>
                            <?php if (!$tokenRow['revoked']): ?>
                                <form method="post" action="<?= esc_url(admin_url('api-tokens')) ?>" data-lp-confirm="Revoke this token? Anything using it will stop working immediately.">
                                    <?= Csrf::field('revoke_token_' . $tokenRow['id']) ?>
                                    <input type="hidden" name="form" value="revoke_token">
                                    <input type="hidden" name="id" value="<?= (int) $tokenRow['id'] ?>">
                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Revoke</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
