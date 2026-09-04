<?php

/**
 * The admin Settings > Writing > Emoji Picker screen, provided by the bundled Emoji Picker plugin (LPP-006).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\EmojiPicker\EmojiPickerService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the plugin is active, so EmojiPickerService's
// class is guaranteed to already be loaded.
$service = EmojiPickerService::instance();
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'emoji_picker_settings' && Csrf::verify('emoji_picker_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $service->saveSettings([
        'enabled' => isset($_POST['enabled']),
        'wysiwyg' => isset($_POST['wysiwyg']),
        'markdown' => isset($_POST['markdown']),
        'recent_limit' => max(0, min(100, (int) ($_POST['recent_limit'] ?? 24))),
        'default_category' => trim((string) ($_POST['default_category'] ?? '')),
    ]);

    header('Location: ' . admin_url('settings/writing') . '?saved=1');
    exit;
}

$settings = $service->settings();
$categories = $service->categories();
?>
<h1 class="lp-admin__title">Writing</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Emoji Picker</h2>
    <p class="lp-field__hint">
        Adds an emoji picker button to the Post/Page/Downloads editor
        toolbar — search or browse by category, with a per-user
        recently-used list. Inserts a plain Unicode character, no images
        or shortcodes, and never makes a network request once the editor
        screen has loaded.
    </p>

    <form method="post" action="<?= esc_url(admin_url('settings/writing')) ?>">
        <?= Csrf::field('emoji_picker_settings') ?>
        <input type="hidden" name="form" value="emoji_picker_settings">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
                Enable the Emoji Picker
            </label>
        </p>

        <fieldset class="lp-field">
            <legend>Show the picker in</legend>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="wysiwyg" value="1" <?= $settings['wysiwyg'] ? 'checked' : '' ?>>
                Visual/HTML editor
            </label>
            <label class="lp-field--checkbox">
                <input type="checkbox" name="markdown" value="1" <?= $settings['markdown'] ? 'checked' : '' ?>>
                Markdown editor
            </label>
        </fieldset>

        <p class="lp-field">
            <label for="emoji-recent-limit">Recently-used emoji to remember</label>
            <input type="number" id="emoji-recent-limit" name="recent_limit" min="0" max="100" value="<?= (int) $settings['recent_limit'] ?>">
            <span class="lp-field__hint">Set to 0 to hide the Recently Used category entirely.</span>
        </p>

        <p class="lp-field">
            <label for="emoji-default-category">Default category shown on open</label>
            <select id="emoji-default-category" name="default_category">
                <option value="">First available category</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= esc_attr($category) ?>" <?= $settings['default_category'] === $category ? 'selected' : '' ?>><?= esc_html($category) ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
    </form>
</section>
