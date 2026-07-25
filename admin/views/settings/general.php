<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-042: Feeds, Search, and REST API were previously three of several
 * sections on one big admin/views/settings.php — moved here verbatim
 * (same option keys, same CSRF action names) as Settings > General now
 * that Settings has sub-pages. Site identity (title, logo, favicon,
 * custom CSS) intentionally stays on the Appearance > Branding screen
 * rather than duplicating that working upload flow here; the rest of
 * LP-042's General checklist (admin email, registration, default role,
 * language, timezone, date/time format, first day of week, footer
 * copyright, meta description, default OG image) has no backing
 * implementation yet and is deferred — see TODO.md.
 */
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'feed_settings' && Csrf::verify('feed_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('feeds_enabled', ($_POST['feeds_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_full_content', ($_POST['feed_full_content'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_item_limit', (string) max(1, min(100, (int) ($_POST['feed_item_limit'] ?? 10))));
    $kernel->config->setOption('feed_cache_lifetime', (string) max(0, (int) ($_POST['feed_cache_lifetime'] ?? 900)));
    $kernel->config->setOption('feed_description', trim((string) ($_POST['feed_description'] ?? '')));
    $kernel->config->setOption('feed_featured_images', ($_POST['feed_featured_images'] ?? '') === '1' ? '1' : '0');

    header('Location: ' . admin_url('settings/general') . '?saved=1');
    exit;
} elseif ($form === 'search_settings' && Csrf::verify('search_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('search_min_length', (string) max(1, min(50, (int) ($_POST['search_min_length'] ?? 3))));
    $kernel->config->setOption('search_max_results', (string) max(1, min(500, (int) ($_POST['search_max_results'] ?? 50))));

    header('Location: ' . admin_url('settings/general') . '?saved=1');
    exit;
} elseif ($form === 'rest_api_settings' && Csrf::verify('rest_api_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('rest_api_enabled', ($_POST['rest_api_enabled'] ?? '') === '1' ? '1' : '0');

    foreach (['posts', 'pages', 'categories', 'tags', 'comments', 'search'] as $resource) {
        $kernel->config->setOption("rest_api_resource_{$resource}_enabled", ($_POST["rest_api_resource_{$resource}_enabled"] ?? '') === '1' ? '1' : '0');
    }

    $kernel->config->setOption('rest_api_comments_public_submission_enabled', ($_POST['rest_api_comments_public_submission_enabled'] ?? '') === '1' ? '1' : '0');

    header('Location: ' . admin_url('settings/general') . '?saved=1');
    exit;
}
?>
<h1 class="lp-admin__title">General</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Feeds</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('feed_settings') ?>
        <input type="hidden" name="form" value="feed_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="feeds_enabled" value="1" <?= $kernel->config->option('feeds_enabled', '1') !== '0' ? 'checked' : '' ?>>
            Enable RSS/Atom feeds
        </label>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="feed_full_content" value="1" <?= $kernel->config->option('feed_full_content', '1') !== '0' ? 'checked' : '' ?>>
            Include full post content in feeds (unchecked shows excerpts only)
        </label>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="feed_featured_images" value="1" <?= $kernel->config->option('feed_featured_images', '1') !== '0' ? 'checked' : '' ?>>
            Include featured images in feed items (as an enclosure)
        </label>

        <p class="lp-field">
            <label for="feed-item-limit">Number of items per feed</label>
            <input type="number" id="feed-item-limit" name="feed_item_limit" min="1" max="100" value="<?= esc_attr((string) $kernel->config->option('feed_item_limit', '10')) ?>">
        </p>

        <p class="lp-field">
            <label for="feed-cache-lifetime">Feed cache lifetime (seconds)</label>
            <input type="number" id="feed-cache-lifetime" name="feed_cache_lifetime" min="0" value="<?= esc_attr((string) $kernel->config->option('feed_cache_lifetime', '900')) ?>">
        </p>

        <p class="lp-field">
            <label for="feed-description">Feed description</label>
            <input type="text" id="feed-description" name="feed_description" value="<?= esc_attr((string) $kernel->config->option('feed_description', '')) ?>">
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Search</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('search_settings') ?>
        <input type="hidden" name="form" value="search_settings">

        <p class="lp-field">
            <label for="search-min-length">Minimum search query length</label>
            <input type="number" id="search-min-length" name="search_min_length" min="1" max="50" value="<?= esc_attr((string) $kernel->config->option('search_min_length', '3')) ?>">
        </p>

        <p class="lp-field">
            <label for="search-max-results">Maximum results (combined across posts and pages)</label>
            <input type="number" id="search-max-results" name="search_max_results" min="1" max="500" value="<?= esc_attr((string) $kernel->config->option('search_max_results', '50')) ?>">
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>REST API</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('rest_api_settings') ?>
        <input type="hidden" name="form" value="rest_api_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="rest_api_enabled" value="1" <?= $kernel->config->option('rest_api_enabled', '1') !== '0' ? 'checked' : '' ?>>
            Enable the REST API (<code>/api/v1/...</code>)
        </label>

        <fieldset class="lp-field">
            <legend>Enabled resources</legend>
            <?php foreach (['posts' => 'Posts', 'pages' => 'Pages', 'categories' => 'Categories', 'tags' => 'Tags', 'comments' => 'Comments', 'search' => 'Search'] as $resource => $label): ?>
                <label class="lp-field--checkbox">
                    <input type="checkbox" name="rest_api_resource_<?= esc_attr($resource) ?>_enabled" value="1" <?= $kernel->config->option("rest_api_resource_{$resource}_enabled", '1') !== '0' ? 'checked' : '' ?>>
                    <?= esc_html($label) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="rest_api_comments_public_submission_enabled" value="1" <?= $kernel->config->option('rest_api_comments_public_submission_enabled', '1') !== '0' ? 'checked' : '' ?>>
            Allow public (no API token) comment submission via the API
        </label>
        <span class="lp-field__hint">Reading and moderating comments via the API is controlled by the "Comments" resource toggle above; this only affects anonymous submissions.</span>

        <p class="lp-field__hint">Manage your own API tokens on the <a href="<?= esc_url(admin_url('api-tokens')) ?>">API Tokens</a> page.</p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Site Identity</h2>
    <p>Site title, logo, favicon, and custom CSS are managed on the <a href="<?= esc_url(admin_url('appearance')) ?>">Appearance</a> page.</p>
</section>
