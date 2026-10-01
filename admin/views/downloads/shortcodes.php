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
/** @var bool $wordPressImporterActive */

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
    <ul class="lp-admin__meta-list lp-admin__meta-list--attributes">
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

<section class="lp-admin__panel">
    <h2><code>[lumora_recent_downloads]</code></h2>
    <p class="lp-field__hint">
        Lists the newest live downloads first and pages through them, for a
        &ldquo;latest downloads&rdquo; page that keeps growing. The current page is
        chosen with a <code>downloads_page</code> address parameter, and page links
        appear below the list when there is more than one page. Trashed
        downloads, and downloads with no file or link attached yet, never appear.
    </p>

    <h3>Attributes</h3>
    <ul class="lp-admin__meta-list lp-admin__meta-list--attributes">
        <li><span><code>per_page</code></span><span>How many downloads to show per page, from 1 to 100. Defaults to 10.</span></li>
        <li><span><code>category</code></span><span>A category name, matched case-insensitively, to list only that category. Leave out for every category.</span></li>
        <li><span><code>category_id</code></span><span>A category's exact numeric ID. Wins over <code>category</code> when both are given.</span></li>
        <li><span><code>show_size</code></span><span>Set to <code>"1"</code> to show each file download's size next to its link.</span></li>
    </ul>

    <h3>Examples</h3>
    <p class="lp-field__hint">Every download, newest first, 10 per page:</p>
    <pre><code>[lumora_recent_downloads]</code></pre>

    <p class="lp-field__hint">One category, 5 per page, with file sizes:</p>
    <pre><code>[lumora_recent_downloads category_id="<?= (int) ($exampleCategory->id ?? 1) ?>" per_page="5" show_size="1"]</code></pre>
</section>

<?php if ($wordPressImporterActive ?? false): ?>
    <section class="lp-admin__panel">
        <h2>Imported Simple Download Monitor shortcodes</h2>
        <p class="lp-field__hint">
            Provided by the WordPress Importer plugin, so content imported from a WordPress site that used
            Simple Download Monitor keeps working. They read the same downloads, Media folders and counts the
            import brought across. New content should use <code>[lumora_downloads]</code> or
            <code>[lumora_recent_downloads]</code> above instead.
        </p>

        <ul class="lp-admin__meta-list lp-admin__meta-list--attributes">
            <li><span><code>[sdm_show_dl_from_category category_slug="game-of-thrones" show_size="1"]</code></span><span>Every download filed in the Media folder whose name matches <code>category_slug</code> (the folder name in lower case with dashes). <code>show_size="1"</code> adds each file's size.</span></li>
            <li><span><code>[sdm_download id="12"]</code></span><span>A single imported download, by its original WordPress ID.</span></li>
            <li><span><code>[sdm_latest_downloads number="5" category_slug="game-of-thrones"]</code></span><span>The newest <em>number</em> imported downloads (default 5), optionally limited to one folder.</span></li>
        </ul>
    </section>
<?php endif; ?>
