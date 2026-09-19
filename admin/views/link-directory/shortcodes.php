<?php

/**
 * The admin Link Directory > Shortcodes screen (LPP-026): documents [lumora_link_directory] for site admins.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Plugins\LinkDirectory\LinkDirectoryCategoryService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the Link Directory plugin is active (see
// admin/index.php's $linkDirectoryActive-gated menu entry), so listing
// real category names here is safe without re-checking the plugin.
$linkCategories = new LinkDirectoryCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$allLinkCategories = $linkCategories->listAll();
$exampleCategory = $allLinkCategories[0] ?? null;
?>
<h1 class="lp-admin__title">Link Directory &mdash; Shortcodes</h1>

<section class="lp-admin__panel">
    <h2><code>[lumora_link_directory]</code></h2>
    <p class="lp-field__hint">
        Shows the link directory anywhere in post or page content. With no
        attributes, it lists every category (and sub-category) with its own
        link count — clicking a category browses into that category's own
        links (and any sub-categories) right there on the same page, with a
        link back to the full list, so this one placement is a complete,
        browsable directory on its own. Given a category, it instead shows
        only that category's own links — title, thumbnail, description, and
        a clickable URL for each — for a site that wants one category
        pinned to its own dedicated page. Renders nothing if nothing can be
        resolved. See
        <a href="<?= esc_url(admin_url('link-directory/categories')) ?>">Link Directory &rsaquo; Categories</a>
        for a ready-to-copy shortcode per category, and
        <a href="<?= esc_url(admin_url('link-directory/export-import')) ?>">Link Directory &rsaquo; Export / Import</a>
        to back up or copy every category and link to another install.
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list lp-admin__meta-list--attributes">
        <li><span><code>category</code></span><span>A category name, matched case-insensitively.</span></li>
        <li><span><code>category_id</code></span><span>A category's exact numeric ID. Wins over <code>category</code> when both are given.</span></li>
        <li><span><code>link_id</code></span><span>Show a single link by its exact numeric ID. Wins over <code>category</code>/<code>category_id</code>.</span></li>
    </ul>

    <h3>Examples</h3>
    <p class="lp-field__hint">Every category, with link counts:</p>
    <pre><code>[lumora_link_directory]</code></pre>

    <p class="lp-field__hint">Everything in a category, by name:</p>
    <pre><code>[lumora_link_directory category="<?= esc_html($exampleCategory->name ?? 'Fansites') ?>"]</code></pre>

    <p class="lp-field__hint">Everything in a category, by ID:</p>
    <pre><code>[lumora_link_directory category_id="<?= (int) ($exampleCategory->id ?? 1) ?>"]</code></pre>

    <p class="lp-field__hint">A single link by ID:</p>
    <pre><code>[lumora_link_directory link_id="1"]</code></pre>

    <?php if ($allLinkCategories !== []): ?>
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
                <?php foreach ($allLinkCategories as $linkCategory): ?>
                    <tr>
                        <td><?= (int) $linkCategory->id ?></td>
                        <td><?= esc_html($linkCategory->name) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="lp-admin__widget-placeholder">No categories yet &mdash; create one from <a href="<?= esc_url(admin_url('link-directory/add-new')) ?>">Link Directory &rsaquo; Add New</a>.</p>
    <?php endif; ?>
</section>
