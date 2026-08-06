<?php

/**
 * The admin Settings > Redirects screen (LP-022): manage URL redirects.
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

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$redirects = $kernel->redirects;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'create_redirect' && Csrf::verify('create_redirect', $token)) {
        $sourcePath = ltrim(trim((string) ($_POST['source_path'] ?? '')), '/');
        $targetUrl = trim((string) ($_POST['target_url'] ?? ''));
        $statusCode = (int) ($_POST['status_code'] ?? 301);
        $statusCode = in_array($statusCode, [301, 302], true) ? $statusCode : 301;

        if ($sourcePath === '' || $targetUrl === '') {
            $error = 'Both a source path and a target URL are required.';
        } elseif ($redirects->findBySourcePath($sourcePath) !== null) {
            $error = 'A redirect for that source path already exists.';
        } else {
            $redirects->create($sourcePath, $targetUrl, $statusCode);

            header('Location: ' . admin_url('settings/redirects') . '?saved=1');
            exit;
        }
    } elseif ($form === 'delete_redirect') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('delete_redirect_' . $id, $token)) {
            $redirects->delete($id);

            header('Location: ' . admin_url('settings/redirects') . '?deleted=1');
            exit;
        }
    }
}

$allRedirects = $redirects->listAll();
?>
<h1 class="lp-admin__title">Redirects</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Redirect saved.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Redirect deleted.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Add Redirect</h2>
    <p class="lp-field__hint">When a visitor requests a URL that doesn't match any post, page, or other content, this list is checked before showing a 404 &mdash; useful when a URL has moved or you're migrating from another site.</p>
    <form method="post" action="<?= esc_url(admin_url('settings/redirects')) ?>">
        <?= Csrf::field('create_redirect') ?>
        <input type="hidden" name="form" value="create_redirect">

        <p class="lp-field">
            <label for="redirect-source">From (path on this site)</label>
            <input type="text" id="redirect-source" name="source_path" placeholder="old-post-url" required>
            <span class="lp-field__hint">Relative to the site root, no leading slash needed &mdash; e.g. <code>old-post-url</code> for <code><?= esc_html(home_url('old-post-url')) ?></code>.</span>
        </p>

        <p class="lp-field">
            <label for="redirect-target">To (destination URL)</label>
            <input type="text" id="redirect-target" name="target_url" placeholder="<?= esc_attr(home_url('new-post-url')) ?>" required>
            <span class="lp-field__hint">A full URL, on this site or elsewhere.</span>
        </p>

        <p class="lp-field">
            <label for="redirect-status">Redirect type</label>
            <select id="redirect-status" name="status_code">
                <option value="301">301 &mdash; Permanent</option>
                <option value="302">302 &mdash; Temporary</option>
            </select>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Add Redirect</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Existing Redirects</h2>
    <?php if ($allRedirects === []): ?>
        <p class="lp-admin__widget-placeholder">No redirects configured yet.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">From</th>
                    <th scope="col">To</th>
                    <th scope="col">Type</th>
                    <th scope="col">Hits</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allRedirects as $redirect): ?>
                    <tr>
                        <td><code>/<?= esc_html((string) $redirect['source_path']) ?></code></td>
                        <td><code><?= esc_html((string) $redirect['target_url']) ?></code></td>
                        <td><?= (int) $redirect['status_code'] ?></td>
                        <td><?= (int) $redirect['hit_count'] ?></td>
                        <td>
                            <form method="post" action="<?= esc_url(admin_url('settings/redirects')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this redirect?">
                                <?= Csrf::field('delete_redirect_' . (int) $redirect['id']) ?>
                                <input type="hidden" name="form" value="delete_redirect">
                                <input type="hidden" name="id" value="<?= (int) $redirect['id'] ?>">
                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="lp-admin__panel">
    <h2>Other SEO Tools</h2>
    <ul class="lp-admin__meta-list lp-admin__meta-list--stacked">
        <li>XML sitemap: <a href="<?= esc_url(home_url('sitemap.xml')) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html(home_url('sitemap.xml')) ?></a> &mdash; every published post, page, category, and tag, regenerated on every request.</li>
        <li>robots.txt: <a href="<?= esc_url(home_url('robots.txt')) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html(home_url('robots.txt')) ?></a> already references the sitemap above.</li>
        <li>Search engine visibility (discourage indexing) and the site-wide default meta description live on <a href="<?= esc_url(admin_url('settings/reading')) ?>">Settings &rsaquo; Reading</a> and <a href="<?= esc_url(admin_url('settings/general')) ?>">Settings &rsaquo; General</a> respectively.</li>
        <li>Per-post/page SEO title and meta description overrides are on that post or page's own editor screen.</li>
    </ul>
</section>
