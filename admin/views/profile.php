<?php

/**
 * The "My Profile" admin screen: a user's own account settings.
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
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\ThemePreference;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Every authenticated role reaches this page for their own account
// only — this form always operates on $currentUser->id and nothing else.
$locked = $kernel->editorPreferences->isLockedToDefault();
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'editor_preference' && Csrf::verify('editor_preference', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        if ($locked) {
            $error = 'The site administrator has locked everyone to the default editor.';
        } else {
            $selected = trim((string) ($_POST['preferred_editor'] ?? ''));
            $format = $selected === '' ? null : ContentFormat::tryFrom($selected);

            if ($selected !== '' && $format === null) {
                $error = 'Please choose a valid editor.';
            } else {
                $kernel->users->updateEditorPreference($currentUser->id, $format);

                header('Location: ' . admin_url('profile') . '?saved=1');
                exit;
            }
        }
    } elseif ($form === 'theme_preference' && Csrf::verify('theme_preference', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $selected = trim((string) ($_POST['theme_preference'] ?? ''));
        $preference = ThemePreference::tryFrom($selected);

        if ($preference === null) {
            $error = 'Please choose a valid theme.';
        } else {
            $kernel->users->updateThemePreference($currentUser->id, $preference);

            header('Location: ' . admin_url('profile') . '?saved=1');
            exit;
        }
    }
}
?>
<h1 class="lp-admin__title">My Profile</h1>

<?php if (isset($_GET['saved']) && $error === null): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Appearance</h2>

    <form method="post" action="<?= esc_url(admin_url('profile')) ?>">
        <?= Csrf::field('theme_preference') ?>
        <input type="hidden" name="form" value="theme_preference">

        <p class="lp-field">
            <label for="theme-preference">Admin color scheme</label>
            <select id="theme-preference" name="theme_preference">
                <?php foreach (ThemePreference::cases() as $themeOption): ?>
                    <option value="<?= esc_attr($themeOption->value) ?>" <?= $currentUser->themePreference === $themeOption ? 'selected' : '' ?>>
                        <?= esc_html($themeOption->label()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">"Follow System" matches your OS or browser's own light/dark setting.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Editor</h2>

    <?php if ($locked): ?>
        <p class="lp-field__hint">
            The site administrator has locked every account to the site-wide
            default editor. Your own preference below is kept, not deleted
            &mdash; it takes effect again if this restriction is ever lifted.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= esc_url(admin_url('profile')) ?>">
        <?= Csrf::field('editor_preference') ?>
        <input type="hidden" name="form" value="editor_preference">

        <p class="lp-field">
            <label for="preferred-editor">Default editor</label>
            <select id="preferred-editor" name="preferred_editor" <?= $locked ? 'disabled' : '' ?>>
                <option value="">Use site default (<?= esc_html($kernel->editorPreferences->defaultEditor()->label()) ?>)</option>
                <?php foreach ($kernel->editorPreferences->registeredEditors() as $editorOption): ?>
                    <option value="<?= esc_attr($editorOption['value']) ?>" <?= $currentUser->preferredEditor?->value === $editorOption['value'] ? 'selected' : '' ?>>
                        <?= esc_html($editorOption['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">New posts and pages you create open in this editor.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary" <?= $locked ? 'disabled' : '' ?>>Save</button>
    </form>
</section>
