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

use LumoraPress\Plugins\Downloads\DownloadCategoryService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the Downloads plugin is active (see
// admin/index.php's $downloadsActive-gated menu entry), so listing
// real category names here is safe without re-checking the plugin.
$downloadCategories = new DownloadCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$allDownloadCategories = $downloadCategories->listAll();
$exampleCategory = $allDownloadCategories[0] ?? null;
?>
<h1 class="lp-admin__title">Downloads &mdash; Shortcodes</h1>

<section class="lp-admin__panel">
    <h2><code>[lumora_downloads]</code></h2>
    <p class="lp-field__hint">
        Shows one or more downloads anywhere in post or page content — a
        title, a link, and (optionally) a file size and description.
        Renders nothing if nothing can be resolved. See
        <a href="<?= esc_url(admin_url('downloads/categories')) ?>">Downloads &rsaquo; Categories</a>
        for a ready-to-copy shortcode per category.
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list">
        <li><span><code>category</code></span><span>A category name, matched case-insensitively.</span></li>
        <li><span><code>category_id</code></span><span>A category's exact numeric ID. Wins over <code>category</code> when both are given.</span></li>
        <li><span><code>download_id</code></span><span>Show a single download by its exact numeric ID. Wins over every other attribute.</span></li>
        <li><span><code>count</code></span><span>Show the newest <em>N</em> live downloads, sorted most-recent-first, instead of every download in a category. Combine with <code>category</code>/<code>category_id</code> to limit to one category; omit them for the newest across every category. <code>count="1"</code> is "the single newest download".</span></li>
        <li><span><code>show_size</code></span><span>Set to <code>"1"</code> to show each file download's size next to its link. Ignored for URL-type downloads, which have no file size.</span></li>
    </ul>

    <h3>Examples</h3>
    <p class="lp-field__hint">Everything in a category, by name:</p>
    <pre><code>[lumora_downloads category="<?= esc_html($exampleCategory->name ?? 'Patches') ?>"]</code></pre>

    <p class="lp-field__hint">Everything in a category, by ID, with file sizes shown:</p>
    <pre><code>[lumora_downloads category_id="<?= (int) ($exampleCategory->id ?? 1) ?>" show_size="1"]</code></pre>

    <p class="lp-field__hint">A single download by ID:</p>
    <pre><code>[lumora_downloads download_id="1"]</code></pre>

    <p class="lp-field__hint">The newest download in a category:</p>
    <pre><code>[lumora_downloads category_id="<?= (int) ($exampleCategory->id ?? 1) ?>" count="1"]</code></pre>

    <p class="lp-field__hint">The newest 5 downloads, across every category:</p>
    <pre><code>[lumora_downloads count="5"]</code></pre>

    <?php if ($allDownloadCategories !== []): ?>
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
                <?php foreach ($allDownloadCategories as $downloadCategory): ?>
                    <tr>
                        <td><?= (int) $downloadCategory->id ?></td>
                        <td><?= esc_html($downloadCategory->name) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="lp-admin__widget-placeholder">No categories yet &mdash; create one from <a href="<?= esc_url(admin_url('downloads/add-new')) ?>">Downloads &rsaquo; Add New</a>.</p>
    <?php endif; ?>
</section>
