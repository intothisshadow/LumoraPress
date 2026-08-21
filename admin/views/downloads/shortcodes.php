<?php

/**
 * The admin Downloads > Shortcodes screen (LPP-009): documents [lumora_downloads] for site admins.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the Downloads plugin is active (see
// admin/index.php's $downloadsActive-gated menu entry), so listing
// real category names here is safe without re-checking the plugin.
$allFolders = $kernel->folders->listAll();
$exampleFolder = $allFolders[0] ?? null;
?>
<h1 class="lp-admin__title">Downloads &mdash; Shortcodes</h1>

<section class="lp-admin__panel">
    <h2><code>[lumora_downloads]</code></h2>
    <p class="lp-field__hint">
        Shows a category's downloads anywhere in post or page content — a
        title, a link, and (optionally) a file size and description.
        Renders nothing if the category can't be resolved or has no
        downloads in it.
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list">
        <li><span><code>category</code></span><span>A category (folder) name, matched case-insensitively.</span></li>
        <li><span><code>category_id</code></span><span>A category's exact numeric ID. Wins over <code>category</code> when both are given.</span></li>
        <li><span><code>show_size</code></span><span>Set to <code>"1"</code> to show each file download's size next to its link. Ignored for URL-type downloads, which have no file size.</span></li>
    </ul>

    <h3>Examples</h3>
    <p class="lp-field__hint">By category name:</p>
    <pre><code>[lumora_downloads category="<?= esc_html($exampleFolder->name ?? 'Patches') ?>"]</code></pre>

    <p class="lp-field__hint">By category ID, with file sizes shown:</p>
    <pre><code>[lumora_downloads category_id="<?= (int) ($exampleFolder->id ?? 1) ?>" show_size="1"]</code></pre>

    <?php if ($allFolders !== []): ?>
        <h3>Your categories</h3>
        <p class="lp-field__hint">Use either the name or the ID below in a shortcode.</p>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Name</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allFolders as $folder): ?>
                    <tr>
                        <td><?= (int) $folder->id ?></td>
                        <td><?= esc_html($folder->name) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="lp-admin__widget-placeholder">No categories yet &mdash; create one from <a href="<?= esc_url(admin_url('downloads/add-new')) ?>">Downloads &rsaquo; Add New</a>.</p>
    <?php endif; ?>
</section>
