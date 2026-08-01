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
 * that Settings has sub-pages. Site title, logo, favicon, and custom CSS
 * intentionally stay on the Appearance > Branding screen rather than
 * duplicating that working upload flow here.
 *
 * Of LP-042's original General checklist, this session added: Website
 * URL, Administration email, Tagline, Timezone (plus the bootstrap.php
 * fix that makes it actually apply — see that file's docblock), Date/Time
 * format (see LumoraPress\Core\Theme\SiteBranding::dateFormat()/
 * timeFormat() and the the_date()/the_time() theme helpers), Footer
 * copyright text, Meta description, and Default Open Graph image.
 * Still deferred, each needing real infrastructure that doesn't exist yet
 * rather than just a settings field: user registration and its default
 * role (no public sign-up flow exists), default language (no i18n string
 * system consumes `locale` yet), and first day of week (nothing in this
 * app — no calendar widget — reads it).
 */
$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'site_settings' && Csrf::verify('site_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $siteUrlInput = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
    $adminEmailInput = trim((string) ($_POST['admin_email'] ?? ''));
    $timezoneInput = trim((string) ($_POST['timezone'] ?? 'UTC'));

    if ($siteUrlInput === '' || filter_var($siteUrlInput, FILTER_VALIDATE_URL) === false) {
        $error = 'Please enter a valid website URL.';
    } elseif ($adminEmailInput !== '' && filter_var($adminEmailInput, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Please enter a valid administration email address.';
    } elseif (!in_array($timezoneInput, DateTimeZone::listIdentifiers(), true)) {
        $error = 'Please select a valid timezone.';
    } else {
        $kernel->config->setOption('site_url', $siteUrlInput);
        $kernel->config->setOption('admin_email', $adminEmailInput);
        $kernel->config->setOption('site_tagline', trim((string) ($_POST['site_tagline'] ?? '')));
        $kernel->config->setOption('timezone', $timezoneInput);

        do_action('general_settings_saved', 'site_settings');

        header('Location: ' . admin_url('settings/general') . '?saved=1');
        exit;
    }
} elseif ($form === 'date_time_settings' && Csrf::verify('date_time_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $dateFormatInput = trim((string) ($_POST['date_format'] ?? ''));
    $timeFormatInput = trim((string) ($_POST['time_format'] ?? ''));

    $kernel->config->setOption('date_format', $dateFormatInput !== '' ? $dateFormatInput : 'F j, Y');
    $kernel->config->setOption('time_format', $timeFormatInput !== '' ? $timeFormatInput : 'g:i a');

    do_action('general_settings_saved', 'date_time_settings');

    header('Location: ' . admin_url('settings/general') . '?saved=1');
    exit;
} elseif ($form === 'footer_settings' && Csrf::verify('footer_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('footer_copyright_text', trim((string) ($_POST['footer_copyright_text'] ?? '')));

    do_action('general_settings_saved', 'footer_settings');

    header('Location: ' . admin_url('settings/general') . '?saved=1');
    exit;
} elseif ($form === 'seo_settings' && Csrf::verify('seo_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('meta_description', trim((string) ($_POST['meta_description'] ?? '')));

    // Upload wins over the existing-image select, which wins over
    // "remove", which wins over just keeping the current value — same
    // precedence admin/views/posts.php's Featured Image field uses.
    if (($_POST['remove_default_og_image'] ?? '') === '1') {
        $kernel->config->setOption('default_og_image_media_id', '');
    }

    $selectedOgImageId = (int) ($_POST['default_og_image_media_id'] ?? 0);

    if ($selectedOgImageId > 0) {
        $kernel->config->setOption('default_og_image_media_id', (string) $selectedOgImageId);
    }

    if (isset($_FILES['default_og_image_upload']) && $_FILES['default_og_image_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploadedOgImage = $kernel->media->upload($_FILES['default_og_image_upload'], $currentUser->id);
            $kernel->config->setOption('default_og_image_media_id', (string) $uploadedOgImage['id']);
        } catch (\Throwable $exception) {
            $error = 'Default Open Graph image upload failed: ' . $exception->getMessage();
        }
    }

    if ($error === null) {
        do_action('general_settings_saved', 'seo_settings');

        header('Location: ' . admin_url('settings/general') . '?saved=1');
        exit;
    }
} elseif ($form === 'feed_settings' && Csrf::verify('feed_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
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
} elseif ($form === 'revision_settings' && Csrf::verify('revision_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('revision_retention', (string) max(0, (int) ($_POST['revision_retention'] ?? 25)));

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

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Site</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('site_settings') ?>
        <input type="hidden" name="form" value="site_settings">

        <p class="lp-field">
            <label for="site-url">Website URL</label>
            <input type="url" id="site-url" name="site_url" value="<?= esc_attr((string) $kernel->config->option('site_url', home_url())) ?>" required>
            <span class="lp-field__hint">Only change this if you've moved the site to a new domain or address — an incorrect value can make the site unreachable.</span>
        </p>

        <p class="lp-field">
            <label for="admin-email">Administration email address</label>
            <input type="email" id="admin-email" name="admin_email" value="<?= esc_attr((string) $kernel->config->option('admin_email', '')) ?>">
        </p>

        <p class="lp-field">
            <label for="site-tagline">Tagline</label>
            <input type="text" id="site-tagline" name="site_tagline" value="<?= esc_attr((string) $kernel->config->option('site_tagline', '')) ?>" placeholder="A short description of the site">
        </p>

        <p class="lp-field">
            <label for="site-timezone">Timezone</label>
            <select id="site-timezone" name="timezone">
                <?php $currentTimezone = (string) $kernel->config->option('timezone', 'UTC'); ?>
                <?php foreach (DateTimeZone::listIdentifiers() as $timezoneOption): ?>
                    <option value="<?= esc_attr($timezoneOption) ?>" <?= $currentTimezone === $timezoneOption ? 'selected' : '' ?>><?= esc_html($timezoneOption) ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Date &amp; Time</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('date_time_settings') ?>
        <input type="hidden" name="form" value="date_time_settings">

        <p class="lp-field">
            <label for="date-format">Date format</label>
            <input type="text" id="date-format" name="date_format" value="<?= esc_attr((string) $kernel->config->option('date_format', 'F j, Y')) ?>">
            <span class="lp-field__hint">
                PHP <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener">date()</a> format.
                Currently: <?= esc_html(the_date(new \DateTimeImmutable())) ?>
            </span>
        </p>

        <p class="lp-field">
            <label for="time-format">Time format</label>
            <input type="text" id="time-format" name="time_format" value="<?= esc_attr((string) $kernel->config->option('time_format', 'g:i a')) ?>">
            <span class="lp-field__hint">Currently: <?= esc_html(the_time(new \DateTimeImmutable())) ?></span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Footer</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('footer_settings') ?>
        <input type="hidden" name="form" value="footer_settings">

        <p class="lp-field">
            <label for="footer-copyright-text">Footer copyright text</label>
            <input type="text" id="footer-copyright-text" name="footer_copyright_text" value="<?= esc_attr((string) $kernel->config->option('footer_copyright_text', '')) ?>">
            <span class="lp-field__hint">Leave blank to keep the theme's default "&copy; <?= esc_html((string) date('Y')) ?> <?= esc_html(site_name()) ?>." line.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>SEO &amp; Social Sharing</h2>
    <?php
    $ogImageOptions = $kernel->media->query(['type' => 'image'], 500, 0)['items'];
    $currentOgImageId = (int) $kernel->config->option('default_og_image_media_id', '');
    $currentOgImage = $currentOgImageId > 0 ? $kernel->media->find($currentOgImageId) : null;
    ?>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('seo_settings') ?>
        <input type="hidden" name="form" value="seo_settings">

        <p class="lp-field">
            <label for="meta-description">Site meta description</label>
            <textarea id="meta-description" name="meta_description" rows="3"><?= esc_html((string) $kernel->config->option('meta_description', '')) ?></textarea>
            <span class="lp-field__hint">Used on the homepage and other views with no post/page of their own to describe; a single post or page's own excerpt is always preferred over this.</span>
        </p>

        <fieldset class="lp-field">
            <legend>Default Open Graph / Twitter Card image</legend>

            <?php if ($currentOgImage !== null): ?>
                <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentOgImage)) ?>" alt="">
                <label class="lp-field--checkbox">
                    <input type="checkbox" name="remove_default_og_image" value="1"> Remove current default image
                </label>
            <?php endif; ?>

            <label for="default-og-image-select">Choose from Media Manager</label>
            <select id="default-og-image-select" name="default_og_image_media_id">
                <option value="0">(None)</option>
                <?php foreach ($ogImageOptions as $ogImageOption): ?>
                    <option value="<?= (int) $ogImageOption['id'] ?>" <?= $currentOgImageId === (int) $ogImageOption['id'] ? 'selected' : '' ?>>
                        <?= esc_html((string) $ogImageOption['file_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="default-og-image-upload">Or upload a new image</label>
            <input type="file" id="default-og-image-upload" name="default_og_image_upload" accept="image/*">
            <span class="lp-field__hint">Used when a shared post or page has no featured image of its own.</span>
        </fieldset>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

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

        <button type="submit" class="lp-button lp-button--primary">Save</button>
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

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Revisions</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('revision_settings') ?>
        <input type="hidden" name="form" value="revision_settings">

        <p class="lp-field">
            <label for="revision-retention">Revisions to keep per post/page</label>
            <input type="number" id="revision-retention" name="revision_retention" min="0" value="<?= esc_attr((string) $kernel->config->option('revision_retention', '25')) ?>">
            <span class="lp-field__hint">A new revision is saved automatically each time a post or page is updated. Set to 0 to keep every revision (unlimited).</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
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

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Site Identity</h2>
    <p>Site title, logo, favicon, and custom CSS are managed on the <a href="<?= esc_url(admin_url('appearance/themes')) ?>">Appearance</a> page.</p>
</section>
