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

use LumoraPress\Core\Notifications\NotificationTypes;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\SidebarMode;
use LumoraPress\Models\ThemePreference;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Every authenticated role reaches this page for their own account
// only — this form always operates on $currentUser->id and nothing else.
$locked = $kernel->editorPreferences->isLockedToDefault();
$error = null;

// Only the notification types this role could ever receive; showing an Author the
// "update available" switch would be noise.
$notificationTypesForUser = array_filter(
    NotificationTypes::all(),
    static fn (array $type): bool => $type['capability'] === null || $currentUser->can($type['capability']),
);
$mutedNotificationTypes = $notifications->mutedTypes($currentUser->id);

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
        $sidebarSelected = trim((string) ($_POST['sidebar_mode'] ?? ''));
        $sidebarMode = $sidebarSelected === '' ? null : SidebarMode::tryFrom($sidebarSelected);

        if ($preference === null) {
            $error = 'Please choose a valid theme.';
        } elseif ($sidebarSelected !== '' && $sidebarMode === null) {
            $error = 'Please choose a valid sidebar layout.';
        } else {
            $kernel->users->updateThemePreference($currentUser->id, $preference);

            if ($sidebarMode !== null) {
                $kernel->users->updateSidebarMode($currentUser->id, $sidebarMode);
            }

            header('Location: ' . admin_url('profile') . '?saved=1');
            exit;
        }
    } elseif ($form === 'notification_preferences' && Csrf::verify('notification_preferences', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        // Ticked boxes are the types the person wants; everything they could have ticked but
        // didn't becomes the muted list. Types hidden from this role keep their stored state.
        $wanted = is_array($_POST['notify'] ?? null) ? array_map('strval', $_POST['notify']) : [];
        $muted = array_diff($notifications->mutedTypes($currentUser->id), array_keys($notificationTypesForUser));

        foreach (array_keys($notificationTypesForUser) as $typeName) {
            if (!in_array($typeName, $wanted, true)) {
                $muted[] = $typeName;
            }
        }

        $notifications->setMutedTypes($currentUser->id, array_values($muted));

        header('Location: ' . admin_url('profile') . '?saved=1');
        exit;
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

        <p class="lp-field">
            <label for="sidebar-mode">Sidebar</label>
            <select id="sidebar-mode" name="sidebar_mode">
                <?php if ($currentUser->sidebarMode === null): ?>
                    <option value="" selected>Not set (collapsed unless you changed it in this browser)</option>
                <?php endif; ?>
                <?php foreach (SidebarMode::cases() as $sidebarOption): ?>
                    <option value="<?= esc_attr($sidebarOption->value) ?>" <?= $currentUser->sidebarMode === $sidebarOption ? 'selected' : '' ?>>
                        <?= esc_html($sidebarOption->label()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">Applies on every browser and device you sign in from. The &raquo; button at the bottom of the sidebar switches it quickly and saves the same setting.</span>
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

<?php if ($notificationTypesForUser !== []): ?>
    <section class="lp-admin__panel">
        <h2>Notifications</h2>

        <form method="post" action="<?= esc_url(admin_url('profile')) ?>">
            <?= Csrf::field('notification_preferences') ?>
            <input type="hidden" name="form" value="notification_preferences">

            <p class="lp-field__hint">Choose which events add a notification to your inbox. A kind you switch off is skipped entirely, so it never raises the unread count.</p>

            <ul class="lp-notifications__mute-list">
                <?php foreach ($notificationTypesForUser as $typeName => $typeInfo): ?>
                    <li>
                        <label class="lp-field--checkbox">
                            <input type="checkbox" name="notify[]" value="<?= esc_attr($typeName) ?>" <?= in_array($typeName, $mutedNotificationTypes, true) ? '' : 'checked' ?>>
                            <?= esc_html($typeInfo['description']) ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>

            <button type="submit" class="lp-button lp-button--primary">Save</button>
        </form>
    </section>
<?php endif; ?>
