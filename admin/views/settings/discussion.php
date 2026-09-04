<?php

/**
 * The admin Settings > Discussion screen: comment defaults, moderation, notifications, and avatar configuration.
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

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Comment *moderation itself* (approve/spam/trash individual comments)
// stays on its own separate Comments admin screen; this page is
// configuration only.
$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'default_post_settings' && Csrf::verify('default_post_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('comment_default_status_for_new_posts', ($_POST['comment_default_status_for_new_posts'] ?? '') === '1' ? 'open' : 'closed');

    header('Location: ' . admin_url('settings/discussion') . '?saved=1');
    exit;
} elseif ($form === 'comment_settings' && Csrf::verify('comment_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('comment_author_name_required', ($_POST['comment_author_name_required'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_author_email_required', ($_POST['comment_author_email_required'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_require_registration', ($_POST['comment_require_registration'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_close_after_days', (string) max(0, (int) ($_POST['comment_close_after_days'] ?? 0)));
    $kernel->config->setOption('comment_cookies_consent_enabled', ($_POST['comment_cookies_consent_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_threading_enabled', ($_POST['comment_threading_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_max_nesting_level', (string) max(1, min(20, (int) ($_POST['comment_max_nesting_level'] ?? 5))));
    $kernel->config->setOption('comment_pagination_enabled', ($_POST['comment_pagination_enabled'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_per_page', (string) max(1, min(500, (int) ($_POST['comment_per_page'] ?? 50))));
    $kernel->config->setOption('comment_default_page', ($_POST['comment_default_page'] ?? '') === 'first' ? 'first' : 'last');
    $kernel->config->setOption('comment_order', ($_POST['comment_order'] ?? '') === 'desc' ? 'desc' : 'asc');

    header('Location: ' . admin_url('settings/discussion') . '?saved=1');
    exit;
} elseif ($form === 'notification_settings' && Csrf::verify('notification_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('comment_notify_admin_new', ($_POST['comment_notify_admin_new'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_notify_admin_moderation', ($_POST['comment_notify_admin_moderation'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_notify_author', ($_POST['comment_notify_author'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_notify_recipients', trim((string) ($_POST['comment_notify_recipients'] ?? '')));

    header('Location: ' . admin_url('settings/discussion') . '?saved=1');
    exit;
} elseif ($form === 'moderation_settings' && Csrf::verify('moderation_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('comment_moderation_manual_all', ($_POST['comment_moderation_manual_all'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_moderation_auto_approve_previous', ($_POST['comment_moderation_auto_approve_previous'] ?? '') === '1' ? '1' : '0');
    $kernel->config->setOption('comment_moderation_link_limit', (string) max(0, (int) ($_POST['comment_moderation_link_limit'] ?? 0)));
    $kernel->config->setOption('comment_moderation_keywords', trim((string) ($_POST['comment_moderation_keywords'] ?? '')));
    $kernel->config->setOption('comment_disallowed_keywords', trim((string) ($_POST['comment_disallowed_keywords'] ?? '')));

    header('Location: ' . admin_url('settings/discussion') . '?saved=1');
    exit;
} elseif ($form === 'avatar_settings' && Csrf::verify('avatar_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('avatars_enabled', ($_POST['avatars_enabled'] ?? '') === '1' ? '1' : '0');
    $rating = strtoupper((string) ($_POST['avatar_max_rating'] ?? 'G'));
    $kernel->config->setOption('avatar_max_rating', in_array($rating, ['G', 'PG', 'R', 'X'], true) ? $rating : 'G');
    $default = (string) ($_POST['avatar_default'] ?? 'mp');
    $kernel->config->setOption('avatar_default', in_array($default, ['mp', 'identicon', 'wavatar', 'retro', 'monsterid', 'robohash', 'blank'], true) ? $default : 'mp');

    if (($_POST['remove_avatar_default_media'] ?? '') === '1') {
        $kernel->config->setOption('avatar_default_media_id', '');
    } elseif (isset($_FILES['avatar_default_upload']) && $_FILES['avatar_default_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploaded = $kernel->media->upload($_FILES['avatar_default_upload'], $currentUser->id);
            $kernel->config->setOption('avatar_default_media_id', (string) $uploaded['id']);
        } catch (\Throwable $exception) {
            $error = 'Default avatar upload failed: ' . $exception->getMessage();
        }
    }

    if ($error === null) {
        header('Location: ' . admin_url('settings/discussion') . '?saved=1');
        exit;
    }
}

$defaultCommentsOpenForNewPosts = $kernel->config->option('comment_default_status_for_new_posts', 'open') !== 'closed';
$authorNameRequired = $kernel->config->option('comment_author_name_required', '1') !== '0';
$authorEmailRequired = $kernel->config->option('comment_author_email_required', '1') !== '0';
$requireRegistration = $kernel->config->option('comment_require_registration', '0') === '1';
$closeAfterDays = (string) $kernel->config->option('comment_close_after_days', '0');
$cookiesConsentEnabled = $kernel->config->option('comment_cookies_consent_enabled', '0') === '1';
$threadingEnabled = $kernel->config->option('comment_threading_enabled', '1') !== '0';
$maxNestingLevel = (string) $kernel->config->option('comment_max_nesting_level', '5');
$paginationEnabled = $kernel->config->option('comment_pagination_enabled', '0') === '1';
$commentsPerPage = (string) $kernel->config->option('comment_per_page', '50');
$defaultPage = (string) $kernel->config->option('comment_default_page', 'last');
$commentOrder = (string) $kernel->config->option('comment_order', 'asc');

$notifyAdminNew = $kernel->config->option('comment_notify_admin_new', '1') !== '0';
$notifyAdminModeration = $kernel->config->option('comment_notify_admin_moderation', '1') !== '0';
$notifyAuthor = $kernel->config->option('comment_notify_author', '0') === '1';
$notifyRecipients = (string) $kernel->config->option('comment_notify_recipients', '');

$manualApprovalForAll = $kernel->config->option('comment_moderation_manual_all', '0') === '1';
$autoApprovePrevious = $kernel->config->option('comment_moderation_auto_approve_previous', '1') !== '0';
$linkLimit = (string) $kernel->config->option('comment_moderation_link_limit', '0');
$moderationKeywords = (string) $kernel->config->option('comment_moderation_keywords', '');
$disallowedKeywords = (string) $kernel->config->option('comment_disallowed_keywords', '');
$akismetEnabled = $kernel->akismet->isEnabled();

$avatarsEnabled = $kernel->config->option('avatars_enabled', '1') !== '0';
$avatarMaxRating = (string) $kernel->config->option('avatar_max_rating', 'G');
$avatarDefault = (string) $kernel->config->option('avatar_default', 'mp');
$avatarDefaultMediaId = (int) $kernel->config->option('avatar_default_media_id', '0');
$avatarDefaultMedia = $avatarDefaultMediaId > 0 ? $kernel->media->find($avatarDefaultMediaId) : null;
?>
<h1 class="lp-admin__title">Discussion</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Default Post Settings</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/discussion')) ?>">
        <?= Csrf::field('default_post_settings') ?>
        <input type="hidden" name="form" value="default_post_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_default_status_for_new_posts" value="1" <?= $defaultCommentsOpenForNewPosts ? 'checked' : '' ?>>
            Allow comments on new posts
        </label>
        <span class="lp-field__hint">Only sets the default for a brand-new post — editing an existing post's own "Allow comments" checkbox on <a href="<?= esc_url(admin_url('posts/new')) ?>">Posts &rsaquo; New Post</a> always wins.</span>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Comment Settings</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/discussion')) ?>">
        <?= Csrf::field('comment_settings') ?>
        <input type="hidden" name="form" value="comment_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_author_name_required" value="1" <?= $authorNameRequired ? 'checked' : '' ?>>
            Comment author must fill out name
        </label>
        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_author_email_required" value="1" <?= $authorEmailRequired ? 'checked' : '' ?>>
            Comment author must fill out email
        </label>
        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_require_registration" value="1" <?= $requireRegistration ? 'checked' : '' ?>>
            Users must be registered and logged in to comment
        </label>
        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_cookies_consent_enabled" value="1" <?= $cookiesConsentEnabled ? 'checked' : '' ?>>
            Show a "Save my name and email in this browser" checkbox on the comment form
        </label>

        <p class="lp-field">
            <label for="comment-close-after-days">Automatically close comments on posts older than</label>
            <input type="number" id="comment-close-after-days" name="comment_close_after_days" min="0" value="<?= esc_attr($closeAfterDays) ?>"> days
            <span class="lp-field__hint">0 disables auto-closing.</span>
        </p>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_threading_enabled" value="1" <?= $threadingEnabled ? 'checked' : '' ?>>
            Enable threaded (nested) comments
        </label>

        <p class="lp-field">
            <label for="comment-max-nesting-level">Maximum nesting level</label>
            <input type="number" id="comment-max-nesting-level" name="comment_max_nesting_level" min="1" max="20" value="<?= esc_attr($maxNestingLevel) ?>">
        </p>

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_pagination_enabled" value="1" <?= $paginationEnabled ? 'checked' : '' ?>>
            Break comments into pages
        </label>

        <p class="lp-field">
            <label for="comment-per-page">Comments per page</label>
            <input type="number" id="comment-per-page" name="comment_per_page" min="1" max="500" value="<?= esc_attr($commentsPerPage) ?>">
        </p>

        <fieldset class="lp-field">
            <legend>On the last page, show the</legend>
            <label class="lp-field--checkbox">
                <input type="radio" name="comment_default_page" value="last" <?= $defaultPage !== 'first' ? 'checked' : '' ?>>
                Last page by default
            </label>
            <label class="lp-field--checkbox">
                <input type="radio" name="comment_default_page" value="first" <?= $defaultPage === 'first' ? 'checked' : '' ?>>
                First page by default
            </label>
        </fieldset>

        <fieldset class="lp-field">
            <legend>Comments display with the</legend>
            <label class="lp-field--checkbox">
                <input type="radio" name="comment_order" value="asc" <?= $commentOrder !== 'desc' ? 'checked' : '' ?>>
                Oldest comments first
            </label>
            <label class="lp-field--checkbox">
                <input type="radio" name="comment_order" value="desc" <?= $commentOrder === 'desc' ? 'checked' : '' ?>>
                Newest comments first
            </label>
        </fieldset>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Notifications</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/discussion')) ?>">
        <?= Csrf::field('notification_settings') ?>
        <input type="hidden" name="form" value="notification_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_notify_admin_new" value="1" <?= $notifyAdminNew ? 'checked' : '' ?>>
            Email me whenever anyone posts a comment
        </label>
        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_notify_admin_moderation" value="1" <?= $notifyAdminModeration ? 'checked' : '' ?>>
            Email me whenever a comment is held for moderation
        </label>
        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_notify_author" value="1" <?= $notifyAuthor ? 'checked' : '' ?>>
            Email the post's author when their post receives a comment
        </label>

        <p class="lp-field">
            <label for="comment-notify-recipients">Additional recipients</label>
            <input type="text" id="comment-notify-recipients" name="comment_notify_recipients" value="<?= esc_attr($notifyRecipients) ?>" placeholder="editor@example.com, moderator@example.com">
            <span class="lp-field__hint">Comma-separated email addresses, notified in addition to the administrator/author above.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Moderation</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/discussion')) ?>">
        <?= Csrf::field('moderation_settings') ?>
        <input type="hidden" name="form" value="moderation_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_moderation_manual_all" value="1" <?= $manualApprovalForAll ? 'checked' : '' ?>>
            Comment must be manually approved
        </label>
        <label class="lp-field--checkbox">
            <input type="checkbox" name="comment_moderation_auto_approve_previous" value="1" <?= $autoApprovePrevious ? 'checked' : '' ?>>
            Comment author must have a previously approved comment to be auto-approved
        </label>

        <p class="lp-field">
            <label for="comment-moderation-link-limit">Hold a comment for moderation if it contains more than</label>
            <input type="number" id="comment-moderation-link-limit" name="comment_moderation_link_limit" min="0" value="<?= esc_attr($linkLimit) ?>"> link(s)
            <span class="lp-field__hint">0 disables this check.</span>
        </p>

        <p class="lp-field">
            <label for="comment-moderation-keywords">Comment Moderation keywords</label>
            <textarea id="comment-moderation-keywords" name="comment_moderation_keywords" rows="5"><?= esc_html($moderationKeywords) ?></textarea>
            <span class="lp-field__hint">One word or phrase per line. A comment (or its author name/email/website) matching any of these is held for moderation instead of being auto-approved.</span>
        </p>

        <p class="lp-field">
            <label for="comment-disallowed-keywords">Disallowed Comment keywords</label>
            <textarea id="comment-disallowed-keywords" name="comment_disallowed_keywords" rows="5"><?= esc_html($disallowedKeywords) ?></textarea>
            <span class="lp-field__hint">One word or phrase per line. A match marks the comment as Spam outright, regardless of the author's trust level.</span>
        </p>

        <p class="lp-field">
            <span class="lp-field__hint">
                Spam protection integration: Akismet is
                <?= $akismetEnabled ? 'enabled' : 'not enabled' ?>
                (configure the API key on <a href="<?= esc_url(admin_url('settings/general')) ?>">Settings &rsaquo; General</a>).
                Every submitted comment also passes through the <code>comment_is_spam</code> filter, which a spam-detection
                plugin (e.g. Lumora Shield) can hook to mark a comment as Spam without needing its own settings page.
            </span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Avatars</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/discussion')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('avatar_settings') ?>
        <input type="hidden" name="form" value="avatar_settings">

        <label class="lp-field--checkbox">
            <input type="checkbox" name="avatars_enabled" value="1" <?= $avatarsEnabled ? 'checked' : '' ?>>
            Show avatars next to comments
        </label>

        <p class="lp-field">
            <label for="avatar-max-rating">Maximum rating</label>
            <select id="avatar-max-rating" name="avatar_max_rating">
                <option value="G" <?= $avatarMaxRating === 'G' ? 'selected' : '' ?>>G — Suitable for all audiences</option>
                <option value="PG" <?= $avatarMaxRating === 'PG' ? 'selected' : '' ?>>PG — May contain mild content</option>
                <option value="R" <?= $avatarMaxRating === 'R' ? 'selected' : '' ?>>R — May contain harsh content</option>
                <option value="X" <?= $avatarMaxRating === 'X' ? 'selected' : '' ?>>X — May contain explicit content</option>
            </select>
        </p>

        <p class="lp-field">
            <label for="avatar-default">Default avatar</label>
            <select id="avatar-default" name="avatar_default">
                <option value="mp" <?= $avatarDefault === 'mp' ? 'selected' : '' ?>>Mystery Person</option>
                <option value="identicon" <?= $avatarDefault === 'identicon' ? 'selected' : '' ?>>Identicon (generated)</option>
                <option value="wavatar" <?= $avatarDefault === 'wavatar' ? 'selected' : '' ?>>Wavatar (generated)</option>
                <option value="retro" <?= $avatarDefault === 'retro' ? 'selected' : '' ?>>Retro (generated)</option>
                <option value="monsterid" <?= $avatarDefault === 'monsterid' ? 'selected' : '' ?>>MonsterID (generated)</option>
                <option value="robohash" <?= $avatarDefault === 'robohash' ? 'selected' : '' ?>>RoboHash (generated)</option>
                <option value="blank" <?= $avatarDefault === 'blank' ? 'selected' : '' ?>>Blank</option>
            </select>
            <span class="lp-field__hint">Used whenever a commenter's email has no Gravatar. Overridden by a locally uploaded default avatar below, if one is set.</span>
        </p>

        <p class="lp-field">
            <label for="avatar-default-upload">Locally uploaded default avatar</label>
            <?php if ($avatarDefaultMedia !== null): ?>
                <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($avatarDefaultMedia)) ?>" alt="Current default avatar">
                <label class="lp-field--checkbox"><input type="checkbox" name="remove_avatar_default_media" value="1"> Remove current default avatar</label>
            <?php endif; ?>
            <input type="file" id="avatar-default-upload" name="avatar_default_upload" accept="image/*">
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
