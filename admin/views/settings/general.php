<?php

/**
 * The admin Settings > General screen: site identity, branding, and core SEO defaults.
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

use LumoraPress\Controllers\Admin\BrandingController;
use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// User registration, default language, and first day of week are
// deliberately not offered — each needs infrastructure that doesn't
// exist yet, not just a settings field.
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
        $kernel->config->setOption('comments_enabled', ($_POST['comments_enabled'] ?? '') === '1' ? '1' : '0');

        do_action('general_settings_saved', 'site_settings');

        // The other fields are already saved, so an upload problem is
        // reported on this page instead of redirecting past it.
        $error = (new BrandingController($kernel->config, $kernel->media))->save($_POST, $_FILES, $currentUser->id);

        if ($error === null) {
            header('Location: ' . admin_url('settings/general') . '?saved=1');
            exit;
        }
    }
} elseif ($form === 'date_time_settings' && Csrf::verify('date_time_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $dateFormatPresetInput = trim((string) ($_POST['date_format_preset'] ?? ''));
    $dateFormatCustomInput = trim((string) ($_POST['date_format_custom'] ?? ''));
    $dateFormatInput = $dateFormatPresetInput === 'custom' ? $dateFormatCustomInput : $dateFormatPresetInput;

    $timeFormatPresetInput = trim((string) ($_POST['time_format_preset'] ?? ''));
    $timeFormatCustomInput = trim((string) ($_POST['time_format_custom'] ?? ''));
    $timeFormatInput = $timeFormatPresetInput === 'custom' ? $timeFormatCustomInput : $timeFormatPresetInput;

    $kernel->config->setOption('date_format', $dateFormatInput !== '' ? $dateFormatInput : 'F j, Y');
    $kernel->config->setOption('time_format', $timeFormatInput !== '' ? $timeFormatInput : 'g:i a');

    do_action('general_settings_saved', 'date_time_settings');

    header('Location: ' . admin_url('settings/general') . '?saved=1');
    exit;
} elseif ($form === 'seo_settings' && Csrf::verify('seo_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('meta_description', trim((string) ($_POST['meta_description'] ?? '')));

    // Upload wins over the existing-image select, which wins over
    // "remove", which wins over the current value.
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
    $kernel->config->setOption('feed_include_comments', ($_POST['feed_include_comments'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_type_posts_enabled', ($_POST['feed_type_posts_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_type_categories_enabled', ($_POST['feed_type_categories_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_type_tags_enabled', ($_POST['feed_type_tags_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_type_authors_enabled', ($_POST['feed_type_authors_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_type_pages_enabled', ($_POST['feed_type_pages_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_type_comments_enabled', ($_POST['feed_type_comments_enabled'] ?? '') === '1' ? '1' : '0');

    header('Location: ' . admin_url('settings/general') . '?saved=1');
    exit;
} elseif ($form === 'revision_settings' && Csrf::verify('revision_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('revision_retention', (string) max(0, (int) ($_POST['revision_retention'] ?? 25)));

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
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('site_settings') ?>
        <input type="hidden" name="form" value="site_settings">

        <p class="lp-field">
            <label for="site-name">Site name</label>
            <input type="text" id="site-name" name="site_name" value="<?= esc_attr((string) $kernel->config->option('site_name', '')) ?>">
        </p>

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

        <?php
        $currentLogoId = (int) $kernel->config->option('site_logo_media_id', '');
        $currentFaviconId = (int) $kernel->config->option('favicon_media_id', '');
        $currentLogo = $currentLogoId > 0 ? $kernel->media->find($currentLogoId) : null;
        $currentFavicon = $currentFaviconId > 0 ? $kernel->media->find($currentFaviconId) : null;
        ?>

        <p class="lp-field">
            <label for="site-logo">Site logo</label>
            <?php if ($currentLogo !== null): ?>
                <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentLogo)) ?>" alt="Current site logo">
                <label class="lp-field--checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
            <?php endif; ?>
            <input type="file" id="site-logo" name="logo" accept="image/*">
        </p>

        <p class="lp-field">
            <label for="site-favicon">Favicon</label>
            <?php if ($currentFavicon !== null): ?>
                <img class="lp-branding-preview lp-branding-preview--favicon" src="<?= esc_url($kernel->media->url($currentFavicon)) ?>" alt="Current favicon">
                <label class="lp-field--checkbox"><input type="checkbox" name="remove_favicon" value="1"> Remove current favicon</label>
            <?php endif; ?>
            <input type="file" id="site-favicon" name="favicon" accept="image/png,image/x-icon,.ico">
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

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comments_enabled" value="1" <?= $kernel->config->option('comments_enabled', '1') !== '0' ? 'checked' : '' ?>>
            Allow comments site-wide
        </label>
        <span class="lp-field__hint">When unchecked, comments are closed on every post and page, whatever each one's own setting says. Existing comments stay visible.</span>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Date &amp; Time</h2>
    <?php
    $dateTimeSample = new \DateTimeImmutable();
    $dateFormatPresets = ['F j, Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'];
    $timeFormatPresets = ['g:i a', 'g:i A', 'H:i'];
    $currentDateFormat = (string) $kernel->config->option('date_format', 'F j, Y');
    $currentTimeFormat = (string) $kernel->config->option('time_format', 'g:i a');
    $isCustomDateFormat = !in_array($currentDateFormat, $dateFormatPresets, true);
    $isCustomTimeFormat = !in_array($currentTimeFormat, $timeFormatPresets, true);
    ?>
    <form method="post" action="<?= esc_url(admin_url('settings/general')) ?>">
        <?= Csrf::field('date_time_settings') ?>
        <input type="hidden" name="form" value="date_time_settings">

        <p class="lp-field" data-lp-format-field>
            <label for="date-format-preset">Date format</label>
            <select id="date-format-preset" name="date_format_preset" data-lp-format-preset-select>
                <?php foreach ($dateFormatPresets as $dateFormatPreset): ?>
                    <option value="<?= esc_attr($dateFormatPreset) ?>" <?= (!$isCustomDateFormat && $currentDateFormat === $dateFormatPreset) ? 'selected' : '' ?>><?= esc_html($dateTimeSample->format($dateFormatPreset)) ?></option>
                <?php endforeach; ?>
                <option value="custom" <?= $isCustomDateFormat ? 'selected' : '' ?>>Custom</option>
            </select>
            <input type="text" id="date-format-custom" name="date_format_custom" value="<?= esc_attr($currentDateFormat) ?>" data-lp-format-custom-input <?= $isCustomDateFormat ? '' : 'hidden' ?>>
            <span class="lp-field__hint">
                Custom values use PHP <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener">date()</a> format.
                Currently: <?= esc_html(the_date($dateTimeSample)) ?>
            </span>
        </p>

        <p class="lp-field" data-lp-format-field>
            <label for="time-format-preset">Time format</label>
            <select id="time-format-preset" name="time_format_preset" data-lp-format-preset-select>
                <?php foreach ($timeFormatPresets as $timeFormatPreset): ?>
                    <option value="<?= esc_attr($timeFormatPreset) ?>" <?= (!$isCustomTimeFormat && $currentTimeFormat === $timeFormatPreset) ? 'selected' : '' ?>><?= esc_html($dateTimeSample->format($timeFormatPreset)) ?></option>
                <?php endforeach; ?>
                <option value="custom" <?= $isCustomTimeFormat ? 'selected' : '' ?>>Custom</option>
            </select>
            <input type="text" id="time-format-custom" name="time_format_custom" value="<?= esc_attr($currentTimeFormat) ?>" data-lp-format-custom-input <?= $isCustomTimeFormat ? '' : 'hidden' ?>>
            <span class="lp-field__hint">Currently: <?= esc_html(the_time($dateTimeSample)) ?></span>
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
                        <?= esc_html($kernel->media->describeFile($ogImageOption)) ?>
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

        <label class="lp-field--checkbox">
            <input type="checkbox" name="feed_include_comments" value="1" <?= $kernel->config->option('feed_include_comments', '0') === '1' ? 'checked' : '' ?>>
            Include each post's approved comments in its feed item (appended after the post content)
        </label>

        <fieldset class="lp-field">
            <legend>Enable specific feed types</legend>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="feed_type_posts_enabled" value="1" <?= $kernel->config->option('feed_type_posts_enabled', '1') !== '0' ? 'checked' : '' ?>>
                Site-wide posts feed (<code>/feed</code>)
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="feed_type_categories_enabled" value="1" <?= $kernel->config->option('feed_type_categories_enabled', '1') !== '0' ? 'checked' : '' ?>>
                Per-category feeds
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="feed_type_tags_enabled" value="1" <?= $kernel->config->option('feed_type_tags_enabled', '1') !== '0' ? 'checked' : '' ?>>
                Per-tag feeds
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="feed_type_authors_enabled" value="1" <?= $kernel->config->option('feed_type_authors_enabled', '1') !== '0' ? 'checked' : '' ?>>
                Per-author feeds
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="feed_type_pages_enabled" value="1" <?= $kernel->config->option('feed_type_pages_enabled', '1') !== '0' ? 'checked' : '' ?>>
                Pages feed (<code>/pages/feed</code>)
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="feed_type_comments_enabled" value="1" <?= $kernel->config->option('feed_type_comments_enabled', '1') !== '0' ? 'checked' : '' ?>>
                Comment feeds (site-wide and per-post)
            </label>
        </fieldset>

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
