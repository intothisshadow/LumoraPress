<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * Feed settings (LP-013), Search settings (LP-014), and Maintenance Mode
 * settings (LP-033) live here — the natural home for future site-wide
 * settings too, since the 'settings' admin menu entry has existed since
 * the menu was defined but had no view file yet (fell through to
 * placeholder.php). Each section posts its own "form" value and CSRF
 * action name so they save independently, the same dispatch pattern
 * admin/views/comments.php uses for its settings/edit/moderate/delete
 * forms. "maintenance_toggle" is the one-click dashboard button — a
 * single global control, so unlike per-row buttons elsewhere it needs no
 * per-ID CSRF-action uniqueness.
 */
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

/**
 * Parses a <input type="datetime-local"> value into the app's stored
 * 'Y-m-d H:i:s' format, same try/catch-then-null pattern
 * admin/views/posts.php uses for published_at. Returns '' (meaning
 * "unset") for an empty or unparseable value rather than null, since
 * PressConfig options are always strings.
 */
$parseScheduleInput = static function (string $raw): string {
    $raw = trim($raw);

    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
    } catch (\Exception) {
        return '';
    }
};

if ($form === 'feed_settings' && Csrf::verify('feed_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('feeds_enabled', ($_POST['feeds_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_full_content', ($_POST['feed_full_content'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('feed_item_limit', (string) max(1, min(100, (int) ($_POST['feed_item_limit'] ?? 10))));
    $kernel->config->setOption('feed_cache_lifetime', (string) max(0, (int) ($_POST['feed_cache_lifetime'] ?? 900)));
    $kernel->config->setOption('feed_description', trim((string) ($_POST['feed_description'] ?? '')));

    header('Location: ' . admin_url('settings') . '?saved=1');
    exit;
} elseif ($form === 'search_settings' && Csrf::verify('search_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('search_min_length', (string) max(1, min(50, (int) ($_POST['search_min_length'] ?? 3))));
    $kernel->config->setOption('search_max_results', (string) max(1, min(500, (int) ($_POST['search_max_results'] ?? 50))));

    header('Location: ' . admin_url('settings') . '?saved=1');
    exit;
} elseif ($form === 'maintenance_settings' && Csrf::verify('maintenance_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $enabled = ($_POST['maintenance_mode_enabled'] ?? '') === '1';
    $kernel->config->setOption('maintenance_mode_enabled', $enabled ? '1' : '0');
    $kernel->config->setOption('maintenance_title', trim((string) ($_POST['maintenance_title'] ?? '')));
    $kernel->config->setOption('maintenance_message', trim((string) ($_POST['maintenance_message'] ?? '')));
    $kernel->config->setOption('maintenance_start_at', $parseScheduleInput((string) ($_POST['maintenance_start_at'] ?? '')));
    $kernel->config->setOption('maintenance_end_at', $parseScheduleInput((string) ($_POST['maintenance_end_at'] ?? '')));
    $kernel->config->setOption(
        'maintenance_bypass_capability',
        ($_POST['maintenance_allow_editors'] ?? '') === '1' ? 'moderate_comments' : 'manage_options',
    );
    $kernel->config->setOption('maintenance_retry_after_seconds', (string) max(0, (int) ($_POST['maintenance_retry_after_seconds'] ?? 3600)));

    do_action('maintenance_mode_toggled', $enabled);

    header('Location: ' . admin_url('settings') . '?saved=1');
    exit;
} elseif ($form === 'thumbnail_settings' && Csrf::verify('thumbnail_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    foreach (['small', 'medium', 'large'] as $sizeName) {
        $kernel->config->setOption("thumbnail_size_{$sizeName}_width", (string) max(1, (int) ($_POST["thumbnail_size_{$sizeName}_width"] ?? 150)));
        $kernel->config->setOption("thumbnail_size_{$sizeName}_height", (string) max(1, (int) ($_POST["thumbnail_size_{$sizeName}_height"] ?? 150)));
        $kernel->config->setOption("thumbnail_size_{$sizeName}_mode", ($_POST["thumbnail_size_{$sizeName}_mode"] ?? 'fit') === 'crop' ? 'crop' : 'fit');
        $kernel->config->setOption("thumbnail_size_{$sizeName}_enabled", ($_POST["thumbnail_size_{$sizeName}_enabled"] ?? '') === '1' ? '1' : '0');
    }
    $kernel->config->setOption('thumbnail_jpeg_quality', (string) max(0, min(100, (int) ($_POST['thumbnail_jpeg_quality'] ?? 82))));
    $kernel->config->setOption('thumbnail_webp_quality', (string) max(0, min(100, (int) ($_POST['thumbnail_webp_quality'] ?? 80))));
    $kernel->config->setOption('thumbnail_sharpen', ($_POST['thumbnail_sharpen'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('thumbnail_max_pixels', (string) max(1, (int) ($_POST['thumbnail_max_pixels'] ?? 25_000_000)));

    header('Location: ' . admin_url('settings') . '?saved=1');
    exit;
} elseif ($form === 'maintenance_toggle' && Csrf::verify('maintenance_toggle', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $enabled = $kernel->config->option('maintenance_mode_enabled', '0') === '0';
    $kernel->config->setOption('maintenance_mode_enabled', $enabled ? '1' : '0');

    do_action('maintenance_mode_toggled', $enabled);

    header('Location: ' . admin_url('dashboard') . '?saved=1');
    exit;
}
?>
<h1 class="lp-admin__title">Settings</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Feeds</h2>
    <form method="post" action="<?= esc_url(admin_url('settings')) ?>">
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
    <form method="post" action="<?= esc_url(admin_url('settings')) ?>">
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
    <h2>Thumbnails</h2>
    <form method="post" action="<?= esc_url(admin_url('settings')) ?>">
        <?= Csrf::field('thumbnail_settings') ?>
        <input type="hidden" name="form" value="thumbnail_settings">

        <?php foreach (['small' => ['150', '150', 'crop'], 'medium' => ['300', '300', 'fit'], 'large' => ['1024', '1024', 'fit']] as $sizeName => $defaults): ?>
            <fieldset class="lp-field">
                <legend><?= esc_html(ucfirst($sizeName)) ?></legend>

                <label class="lp-field--checkbox">
                    <input type="checkbox" name="thumbnail_size_<?= $sizeName ?>_enabled" value="1" <?= $kernel->config->option("thumbnail_size_{$sizeName}_enabled", '1') !== '0' ? 'checked' : '' ?>>
                    Enabled
                </label>

                <label for="thumbnail-<?= $sizeName ?>-width">Width</label>
                <input type="number" id="thumbnail-<?= $sizeName ?>-width" name="thumbnail_size_<?= $sizeName ?>_width" min="1" value="<?= esc_attr((string) $kernel->config->option("thumbnail_size_{$sizeName}_width", $defaults[0])) ?>">

                <label for="thumbnail-<?= $sizeName ?>-height">Height</label>
                <input type="number" id="thumbnail-<?= $sizeName ?>-height" name="thumbnail_size_<?= $sizeName ?>_height" min="1" value="<?= esc_attr((string) $kernel->config->option("thumbnail_size_{$sizeName}_height", $defaults[1])) ?>">

                <label for="thumbnail-<?= $sizeName ?>-mode">Mode</label>
                <select id="thumbnail-<?= $sizeName ?>-mode" name="thumbnail_size_<?= $sizeName ?>_mode">
                    <option value="fit" <?= $kernel->config->option("thumbnail_size_{$sizeName}_mode", $defaults[2]) === 'fit' ? 'selected' : '' ?>>Fit (preserve aspect ratio)</option>
                    <option value="crop" <?= $kernel->config->option("thumbnail_size_{$sizeName}_mode", $defaults[2]) === 'crop' ? 'selected' : '' ?>>Crop (exact dimensions)</option>
                </select>
            </fieldset>
        <?php endforeach; ?>

        <p class="lp-field">
            <label for="thumbnail-jpeg-quality">JPEG quality (0&ndash;100)</label>
            <input type="number" id="thumbnail-jpeg-quality" name="thumbnail_jpeg_quality" min="0" max="100" value="<?= esc_attr((string) $kernel->config->option('thumbnail_jpeg_quality', '82')) ?>">
        </p>

        <p class="lp-field">
            <label for="thumbnail-webp-quality">WebP quality (0&ndash;100)</label>
            <input type="number" id="thumbnail-webp-quality" name="thumbnail_webp_quality" min="0" max="100" value="<?= esc_attr((string) $kernel->config->option('thumbnail_webp_quality', '80')) ?>">
        </p>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="thumbnail_sharpen" value="1" <?= $kernel->config->option('thumbnail_sharpen', '0') === '1' ? 'checked' : '' ?>>
            Sharpen thumbnails after resizing
        </label>

        <p class="lp-field">
            <label for="thumbnail-max-pixels">Maximum source image pixels (memory safeguard)</label>
            <input type="number" id="thumbnail-max-pixels" name="thumbnail_max_pixels" min="1" value="<?= esc_attr((string) $kernel->config->option('thumbnail_max_pixels', '25000000')) ?>">
            <span class="lp-field__hint">Images larger than this are skipped (logged) rather than generating thumbnails for them.</span>
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Maintenance Mode</h2>
    <form method="post" action="<?= esc_url(admin_url('settings')) ?>">
        <?= Csrf::field('maintenance_settings') ?>
        <input type="hidden" name="form" value="maintenance_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="maintenance_mode_enabled" value="1" <?= $kernel->config->option('maintenance_mode_enabled', '0') === '1' ? 'checked' : '' ?>>
            Enable maintenance mode now
        </label>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="maintenance_allow_editors" value="1" <?= $kernel->config->option('maintenance_bypass_capability', 'manage_options') === 'moderate_comments' ? 'checked' : '' ?>>
            Also let Editors bypass maintenance mode (Administrators always can)
        </label>

        <p class="lp-field">
            <label for="maintenance-title">Maintenance page title</label>
            <input type="text" id="maintenance-title" name="maintenance_title" value="<?= esc_attr((string) $kernel->config->option('maintenance_title', 'Maintenance')) ?>">
        </p>

        <p class="lp-field">
            <label for="maintenance-message">Maintenance message</label>
            <textarea id="maintenance-message" name="maintenance_message" rows="3"><?= esc_html((string) $kernel->config->option('maintenance_message', 'We are currently performing scheduled maintenance. Please check back shortly.')) ?></textarea>
        </p>

        <p class="lp-field">
            <label for="maintenance-start-at">Scheduled start (optional)</label>
            <input
                type="datetime-local"
                id="maintenance-start-at"
                name="maintenance_start_at"
                value="<?= esc_attr(($rawStart = (string) $kernel->config->option('maintenance_start_at', '')) !== '' ? (new DateTimeImmutable($rawStart))->format('Y-m-d\TH:i') : '') ?>"
            >
        </p>

        <p class="lp-field">
            <label for="maintenance-end-at">Scheduled end (optional)</label>
            <input
                type="datetime-local"
                id="maintenance-end-at"
                name="maintenance_end_at"
                value="<?= esc_attr(($rawEnd = (string) $kernel->config->option('maintenance_end_at', '')) !== '' ? (new DateTimeImmutable($rawEnd))->format('Y-m-d\TH:i') : '') ?>"
            >
            <span class="lp-field__hint">Shown to visitors as the estimated return time, and used to compute the Retry-After header.</span>
        </p>

        <p class="lp-field">
            <label for="maintenance-retry-after">Retry-After header (seconds, 0 to omit unless a scheduled end is set)</label>
            <input type="number" id="maintenance-retry-after" name="maintenance_retry_after_seconds" min="0" value="<?= esc_attr((string) $kernel->config->option('maintenance_retry_after_seconds', '3600')) ?>">
        </p>

        <button type="submit" class="lp-button">Save</button>
    </form>
</section>
