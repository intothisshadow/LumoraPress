<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-046: new Settings > Reading sub-page — homepage display mode, blog
 * pagination size, and search engine visibility. Feed item count/content
 * (posts per RSS/Atom feed) already live on Settings > General's Feeds
 * section (LP-013/LP-042) and are intentionally left there rather than
 * duplicated here.
 */
$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'homepage_settings' && Csrf::verify('homepage_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $homepageDisplayInput = ($_POST['homepage_display'] ?? '') === 'page' ? 'page' : 'posts';
    $homepagePageIdInput = (int) ($_POST['homepage_page_id'] ?? 0);
    $homepagePostsPageIdInput = (int) ($_POST['homepage_posts_page_id'] ?? 0);

    if ($homepageDisplayInput === 'page' && $homepagePageIdInput <= 0) {
        $error = 'Please choose a page to use as the homepage.';
    } elseif ($homepagePageIdInput > 0 && $homepagePageIdInput === $homepagePostsPageIdInput) {
        $error = 'The Homepage and Posts page must be different pages.';
    } else {
        $kernel->config->setOption('homepage_display', $homepageDisplayInput);
        $kernel->config->setOption('homepage_page_id', $homepagePageIdInput > 0 ? (string) $homepagePageIdInput : '');
        $kernel->config->setOption('homepage_posts_page_id', $homepagePostsPageIdInput > 0 ? (string) $homepagePostsPageIdInput : '');

        header('Location: ' . admin_url('settings/reading') . '?saved=1');
        exit;
    }
} elseif ($form === 'posts_settings' && Csrf::verify('posts_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('posts_per_page', (string) max(1, min(200, (int) ($_POST['posts_per_page'] ?? 10))));

    header('Location: ' . admin_url('settings/reading') . '?saved=1');
    exit;
} elseif ($form === 'search_visibility_settings' && Csrf::verify('search_visibility_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('discourage_search_engines', ($_POST['discourage_search_engines'] ?? '') === '1' ? '1' : '0');

    header('Location: ' . admin_url('settings/reading') . '?saved=1');
    exit;
}

$homepageDisplay = (string) $kernel->config->option('homepage_display', 'posts');
$homepagePageId = (int) $kernel->config->option('homepage_page_id', '0');
$homepagePostsPageId = (int) $kernel->config->option('homepage_posts_page_id', '0');
$pageOptions = $kernel->pages->listAllForParentSelect();
$postsPerPage = (string) $kernel->config->option('posts_per_page', '10');
$discourageSearchEngines = $kernel->config->option('discourage_search_engines', '0') !== '0';
?>
<h1 class="lp-admin__title">Reading</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Homepage</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/reading')) ?>">
        <?= Csrf::field('homepage_settings') ?>
        <input type="hidden" name="form" value="homepage_settings">

        <fieldset class="lp-field">
            <legend>Your homepage displays</legend>

            <label class="lp-field--checkbox">
                <input type="radio" name="homepage_display" value="posts" <?= $homepageDisplay !== 'page' ? 'checked' : '' ?>>
                Your latest posts
            </label>
            <label class="lp-field--checkbox">
                <input type="radio" name="homepage_display" value="page" <?= $homepageDisplay === 'page' ? 'checked' : '' ?>>
                A static page
            </label>
        </fieldset>

        <p class="lp-field">
            <label for="homepage-page-id">Homepage</label>
            <select id="homepage-page-id" name="homepage_page_id">
                <option value="0">(Select a page)</option>
                <?php foreach ($pageOptions as $pageOption): ?>
                    <option value="<?= (int) $pageOption['id'] ?>" <?= $homepagePageId === (int) $pageOption['id'] ? 'selected' : '' ?>>
                        <?= esc_html($pageOption['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">Only used when "A static page" is selected above.</span>
        </p>

        <p class="lp-field">
            <label for="homepage-posts-page-id">Posts page</label>
            <select id="homepage-posts-page-id" name="homepage_posts_page_id">
                <option value="0">(None)</option>
                <?php foreach ($pageOptions as $pageOption): ?>
                    <option value="<?= (int) $pageOption['id'] ?>" <?= $homepagePostsPageId === (int) $pageOption['id'] ? 'selected' : '' ?>>
                        <?= esc_html($pageOption['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">Optional — a page whose own URL shows your latest posts instead of that page's content. Only used when "A static page" is selected above, and must be a different page from the Homepage.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Posts</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/reading')) ?>">
        <?= Csrf::field('posts_settings') ?>
        <input type="hidden" name="form" value="posts_settings">

        <p class="lp-field">
            <label for="posts-per-page">Blog pages show at most</label>
            <input type="number" id="posts-per-page" name="posts_per_page" min="1" max="200" value="<?= esc_attr($postsPerPage) ?>"> posts
            <span class="lp-field__hint">Applies to the homepage post listing and every category/tag/date archive. Posts per RSS/Atom feed is a separate setting, on <a href="<?= esc_url(admin_url('settings/general')) ?>">Settings &rsaquo; General</a>.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Search Engine Visibility</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/reading')) ?>">
        <?= Csrf::field('search_visibility_settings') ?>
        <input type="hidden" name="form" value="search_visibility_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="discourage_search_engines" value="1" <?= $discourageSearchEngines ? 'checked' : '' ?>>
            Discourage search engines from indexing this site
        </label>
        <span class="lp-field__hint">It is up to search engines to honor this request. Adds a site-wide <code>Disallow: /</code> to <a href="<?= esc_url(home_url('robots.txt')) ?>" target="_blank" rel="noopener noreferrer"><code>robots.txt</code></a> and a <code>&lt;meta name="robots" content="noindex,nofollow"&gt;</code> tag to every page.</span>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
