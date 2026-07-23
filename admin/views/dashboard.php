<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$version = require LUMORA_ROOT . '/version.php';
$installDirectoryExists = is_dir(LUMORA_ROOT . '/install');
?>
<h1 class="lp-admin__title">Dashboard</h1>

<?php if ($installDirectoryExists): ?>
    <div class="lp-alert lp-alert--error">
        The <code>install/</code> directory still exists on the server. It should be removed
        for security — it can be left over from a fresh install that couldn't delete itself,
        or restored by a manual update. Delete it via FTP/SFTP or your hosting file manager.
    </div>
<?php endif; ?>

<section class="lp-admin__panel lp-admin__panel--welcome">
    <h2>Welcome, <?= esc_html($currentUser->displayName) ?></h2>
    <p>Sit down. Write. Publish. Here is an overview of your site.</p>
</section>

<div class="lp-admin__grid">
    <section class="lp-admin__widget">
        <h2>Recent Posts</h2>
        <?php $recentPosts = $kernel->posts->paginateForAdmin(1, 5)['posts']; ?>
        <?php if ($recentPosts === []): ?>
            <p class="lp-admin__widget-placeholder">No posts yet.</p>
        <?php else: ?>
            <ul class="lp-admin__meta-list">
                <?php foreach ($recentPosts as $recentPost): ?>
                    <li>
                        <span><a href="<?= esc_url(admin_url('posts')) ?>?action=edit&id=<?= (int) $recentPost->id ?>"><?= esc_html($recentPost->title) ?></a></span>
                        <span><?= esc_html($recentPost->status->label()) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="lp-admin__widget">
        <h2>Recent Comments</h2>
        <p class="lp-admin__widget-placeholder">No comments yet.</p>
    </section>

    <section class="lp-admin__widget">
        <h2>Quick Draft</h2>
        <form class="lp-admin__quick-draft" method="post" action="<?= esc_url(admin_url('posts')) ?>">
            <?= Csrf::field('quick_draft') ?>
            <input type="hidden" name="form" value="quick_draft">
            <p class="lp-field">
                <label for="quick-draft-title">Title</label>
                <input type="text" id="quick-draft-title" name="title">
            </p>
            <p class="lp-field">
                <label for="quick-draft-content">Content</label>
                <textarea id="quick-draft-content" name="content" rows="4"></textarea>
            </p>
            <button type="submit" class="lp-button lp-button--primary">Save Draft</button>
        </form>
    </section>

    <section class="lp-admin__widget">
        <h2>System Information</h2>
        <ul class="lp-admin__meta-list">
            <li><span>PHP Version</span><span><?= esc_html(PHP_VERSION) ?></span></li>
            <li><span>Lumora Press Version</span><span><?= esc_html((string) $version['version']) ?> (<?= esc_html((string) $version['codename']) ?>)</span></li>
            <li><span>Active Theme</span><span><?= esc_html($kernel->theme->activeTheme() ?? '—') ?></span></li>
        </ul>
    </section>

    <section class="lp-admin__widget">
        <h2>Update Status</h2>
        <p>Running Lumora Press <?= esc_html((string) $version['version']) ?>.</p>
        <p><a class="lp-button" href="<?= esc_url(admin_url('updates')) ?>">Manage Updates</a></p>
    </section>
</div>
