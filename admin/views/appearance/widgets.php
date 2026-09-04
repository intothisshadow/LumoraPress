<?php

/**
 * The admin Appearance > Widgets screen (LP-048): assign widgets to sidebars.
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
use LumoraPress\Core\Widgets\WidgetManager;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Widget assignments persist as one JSON option ("widgets_config", keyed by
// sidebar id). Each form loads the full config, mutates the one
// sidebar/widget it targets, saves it back, and updates the in-memory
// WidgetManager too so the page re-renders with the change immediately.
// INACTIVE_SIDEBAR_ID is a reserved "sidebar" id in this same structure
// that holds deactivated widgets until reactivated or deleted.
$loadWidgetsConfig = static function () use ($kernel): array {
    $decoded = json_decode((string) $kernel->config->option('widgets_config', '{}'), true);

    return is_array($decoded) ? $decoded : [];
};

$saveWidgetsConfig = static function (array $widgetsConfig) use ($kernel): void {
    $kernel->config->setOption('widgets_config', json_encode($widgetsConfig));
};

/**
 * Field definitions per widget type, shared by the render form and the
 * save handler so they can't drift out of sync.
 *
 * @return array<int, array{key: string, label: string, type: string, options?: array<string, string>, hint?: string}>
 */
$settingsFieldsFor = static function (string $widgetType) use ($kernel): array {
    $titleField = ['key' => 'title', 'label' => 'Title', 'type' => 'text'];

    return match ($widgetType) {
        'text' => [$titleField, ['key' => 'text', 'label' => 'Content', 'type' => 'wysiwyg']],
        'custom_html' => [$titleField, ['key' => 'html', 'label' => 'Content', 'type' => 'code']],
        'custom_php' => [$titleField, [
            'key' => 'code',
            'label' => 'PHP Code',
            'type' => 'code',
            'hint' => 'Runs on the server with the same trust as editing a theme file directly — anyone who can add this widget already has that level of access. Enter the code body only, without a leading <?php tag.',
        ]],
        'search' => [$titleField],
        'nav_menu' => [$titleField, ['key' => 'location', 'label' => 'Menu', 'type' => 'select', 'options' => $kernel->menus->locations()]],
        // No "number to show" limit — a hard cut on a tree is ambiguous
        // (can orphan or strand children), so it always shows the full tree.
        'pages' => [$titleField],
        'categories' => [$titleField, ['key' => 'show_count', 'label' => 'Show post counts', 'type' => 'checkbox']],
        'recent_posts' => [$titleField, ['key' => 'limit', 'label' => 'Number of posts to show', 'type' => 'number']],
        'recent_comments' => [$titleField, ['key' => 'limit', 'label' => 'Number of comments to show', 'type' => 'number']],
        'archives' => [$titleField, ['key' => 'limit', 'label' => 'Number of months to show', 'type' => 'number']],
        'tag_cloud', 'meta', 'statistics' => [$titleField],
        'social_links' => [
            $titleField,
            ['key' => 'website', 'label' => 'Website URL', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email address', 'type' => 'text'],
            ['key' => 'mastodon', 'label' => 'Mastodon URL', 'type' => 'text'],
            ['key' => 'bluesky', 'label' => 'Bluesky URL', 'type' => 'text'],
            ['key' => 'twitter', 'label' => 'Twitter/X URL', 'type' => 'text'],
            ['key' => 'github', 'label' => 'GitHub URL', 'type' => 'text'],
            ['key' => 'youtube', 'label' => 'YouTube URL', 'type' => 'text'],
            ['key' => 'instagram', 'label' => 'Instagram URL', 'type' => 'text'],
            ['key' => 'discord', 'label' => 'Discord invite/URL', 'type' => 'text'],
        ],
        default => [$titleField],
    };
};

/**
 * Renders one widget's settings fields — shared by the per-sidebar list
 * and the Inactive Widgets list below.
 *
 * @param array{id: string, type: string, settings: array<string, mixed>} $widget
 * @param array<int, array{key: string, label: string, type: string, options?: array<string, string>, hint?: string}> $fields
 */
$renderWidgetSettingsFields = static function (array $widget, array $fields): void {
    foreach ($fields as $field) {
        $fieldId = 'widget-' . $widget['id'] . '-' . $field['key'];
        $value = $widget['settings'][$field['key']] ?? '';
        ?>
        <p class="lp-field">
            <?php if ($field['type'] !== 'checkbox'): ?>
                <label for="<?= esc_attr($fieldId) ?>"><?= esc_html($field['label']) ?></label>
            <?php endif; ?>

            <?php if ($field['type'] === 'textarea'): ?>
                <textarea id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]" rows="4"><?= esc_html((string) $value) ?></textarea>
            <?php elseif ($field['type'] === 'wysiwyg'): ?>
                <div class="lp-widget-wysiwyg" data-lp-widget-wysiwyg-container data-theme-stylesheet="<?= esc_url(theme_url('style.css')) ?>">
                    <textarea id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]" rows="6" data-lp-widget-wysiwyg><?= esc_html((string) $value) ?></textarea>
                </div>
            <?php elseif ($field['type'] === 'code'): ?>
                <textarea id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]" rows="6" class="lp-code-textarea"><?= esc_html((string) $value) ?></textarea>
            <?php elseif ($field['type'] === 'number'): ?>
                <input type="number" id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]" min="1" value="<?= esc_attr((string) $value) ?>">
            <?php elseif ($field['type'] === 'checkbox'): ?>
                <label class="lp-field--checkbox">
                    <input type="checkbox" id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                    <?= esc_html($field['label']) ?>
                </label>
            <?php elseif ($field['type'] === 'select'): ?>
                <select id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]">
                    <option value="">(None)</option>
                    <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                        <option value="<?= esc_attr($optionValue) ?>" <?= $value === $optionValue ? 'selected' : '' ?>><?= esc_html($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]" value="<?= esc_attr((string) $value) ?>">
            <?php endif; ?>

            <?php if (($field['hint'] ?? '') !== ''): ?>
                <span class="lp-field__hint"><?= esc_html($field['hint']) ?></span>
            <?php endif; ?>
        </p>
        <?php
    }
};

$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$postedWidgetId = trim((string) ($_POST['widget_id'] ?? ''));
$postedSidebarId = trim((string) ($_POST['sidebar_id'] ?? ''));
$postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

// Many forms render on this page load; Csrf::field() overwrites the
// session's token per action name on every call, so each name below is
// scoped to the specific widget/sidebar id it acts on.
$postedDirection = (string) ($_POST['direction'] ?? '');
$postedTargetId = trim((string) ($_POST['target_id'] ?? ''));
$postedPosition = (string) ($_POST['position'] ?? 'before');
$postedTargetSidebarId = trim((string) ($_POST['target_sidebar_id'] ?? ''));
$csrfAction = match ($form) {
    'add_widget' => 'widget_add_' . $postedSidebarId,
    'update_widget' => 'widget_update_' . $postedWidgetId,
    // Move Up/Down render as two forms for the same widget id, so scoped
    // by direction too or the second Csrf::field() call would overwrite
    // the first's token.
    'move_widget' => 'widget_move_' . $postedDirection . '_' . $postedWidgetId,
    // One reposition form per sidebar, not per widget — sortable.js fills
    // its dragged/target/position fields and submits on drop.
    'reposition_widget' => 'widget_reposition_' . $postedSidebarId,
    'deactivate_widget' => 'widget_deactivate_' . $postedWidgetId,
    'activate_widget' => 'widget_activate_' . $postedWidgetId,
    'delete_widget' => 'widget_delete_' . $postedWidgetId,
    default => 'widget_unknown_form',
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($csrfAction, $postedToken)) {
    $sidebarId = $postedSidebarId;
    $knownSidebars = $kernel->widgets->sidebars();
    $isInactiveBucket = $sidebarId === WidgetManager::INACTIVE_SIDEBAR_ID;

    if (!$isInactiveBucket && !array_key_exists($sidebarId, $knownSidebars)) {
        $error = 'Unknown widget area.';
    } elseif ($form === 'add_widget') {
        $widgetType = trim((string) ($_POST['widget_type'] ?? ''));

        if (!array_key_exists($widgetType, $kernel->widgets->widgetTypes())) {
            $error = 'Unknown widget type.';
        } else {
            $widgetsConfig = $loadWidgetsConfig();
            $widgetsConfig[$sidebarId] ??= [];
            $widgetsConfig[$sidebarId][] = ['type' => $widgetType, 'settings' => []];
            $kernel->widgets->setWidgets($sidebarId, $widgetsConfig[$sidebarId]);
            $widgetsConfig[$sidebarId] = $kernel->widgets->widgetsFor($sidebarId);
            $saveWidgetsConfig($widgetsConfig);

            header('Location: ' . admin_url('appearance/widgets') . '?saved=1');
            exit;
        }
    } elseif ($form === 'update_widget') {
        $widgetId = $postedWidgetId;
        $widgetsConfig = $loadWidgetsConfig();
        $sidebarWidgets = $widgetsConfig[$sidebarId] ?? [];
        $matched = false;

        foreach ($sidebarWidgets as $index => $widget) {
            if (($widget['id'] ?? null) === $widgetId) {
                $fields = $settingsFieldsFor($widget['type']);
                $settings = [];

                foreach ($fields as $field) {
                    $raw = $_POST['settings'][$field['key']] ?? null;
                    $settings[$field['key']] = $field['type'] === 'checkbox' ? ($raw === '1' ? '1' : '0') : trim((string) $raw);
                }

                $sidebarWidgets[$index]['settings'] = $settings;
                $matched = true;

                break;
            }
        }

        if ($matched) {
            $widgetsConfig[$sidebarId] = $sidebarWidgets;
            $saveWidgetsConfig($widgetsConfig);
            $kernel->widgets->setWidgets($sidebarId, $sidebarWidgets);

            header('Location: ' . admin_url('appearance/widgets') . '?saved=1');
            exit;
        }

        $error = 'That widget no longer exists.';
    } elseif ($form === 'deactivate_widget') {
        // Moves the widget into the reserved INACTIVE_SIDEBAR_ID bucket
        // instead of deleting it, preserving settings for later reactivation.
        $widgetId = $postedWidgetId;
        $widgetsConfig = $loadWidgetsConfig();
        $sidebarWidgets = $widgetsConfig[$sidebarId] ?? [];
        $moving = null;
        $remaining = [];

        foreach ($sidebarWidgets as $widget) {
            if (($widget['id'] ?? null) === $widgetId) {
                $moving = $widget;
            } else {
                $remaining[] = $widget;
            }
        }

        if ($moving !== null) {
            $widgetsConfig[$sidebarId] = $remaining;
            $widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID] ??= [];
            $widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID][] = $moving;
            $saveWidgetsConfig($widgetsConfig);
            $kernel->widgets->setWidgets($sidebarId, $remaining);
            $kernel->widgets->setWidgets(WidgetManager::INACTIVE_SIDEBAR_ID, $widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID]);
        }

        header('Location: ' . admin_url('appearance/widgets') . '?deactivated=1');
        exit;
    } elseif ($form === 'activate_widget') {
        // $sidebarId here is always INACTIVE_SIDEBAR_ID (the source);
        // target_sidebar_id is the destination the admin picked.
        if (!array_key_exists($postedTargetSidebarId, $knownSidebars)) {
            $error = 'Choose a widget area to activate this widget into.';
        } else {
            $widgetId = $postedWidgetId;
            $widgetsConfig = $loadWidgetsConfig();
            $inactiveWidgets = $widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID] ?? [];
            $moving = null;
            $remaining = [];

            foreach ($inactiveWidgets as $widget) {
                if (($widget['id'] ?? null) === $widgetId) {
                    $moving = $widget;
                } else {
                    $remaining[] = $widget;
                }
            }

            if ($moving !== null) {
                $widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID] = $remaining;
                $widgetsConfig[$postedTargetSidebarId] ??= [];
                $widgetsConfig[$postedTargetSidebarId][] = $moving;
                $saveWidgetsConfig($widgetsConfig);
                $kernel->widgets->setWidgets(WidgetManager::INACTIVE_SIDEBAR_ID, $remaining);
                $kernel->widgets->setWidgets($postedTargetSidebarId, $widgetsConfig[$postedTargetSidebarId]);
            }

            header('Location: ' . admin_url('appearance/widgets') . '?activated=1');
            exit;
        }
    } elseif ($form === 'delete_widget') {
        // Permanent removal — used from the Inactive Widgets list once a
        // widget's settings are no longer wanted at all.
        $widgetId = $postedWidgetId;
        $widgetsConfig = $loadWidgetsConfig();
        $widgetsConfig[$sidebarId] = array_values(array_filter(
            $widgetsConfig[$sidebarId] ?? [],
            static fn (array $widget): bool => ($widget['id'] ?? null) !== $widgetId,
        ));
        $saveWidgetsConfig($widgetsConfig);
        $kernel->widgets->setWidgets($sidebarId, $widgetsConfig[$sidebarId]);

        header('Location: ' . admin_url('appearance/widgets') . '?deleted=1');
        exit;
    } elseif ($form === 'move_widget') {
        $widgetId = $postedWidgetId;
        $direction = $postedDirection;
        $widgetsConfig = $loadWidgetsConfig();
        $sidebarWidgets = $widgetsConfig[$sidebarId] ?? [];
        $position = null;

        foreach ($sidebarWidgets as $index => $widget) {
            if (($widget['id'] ?? null) === $widgetId) {
                $position = $index;

                break;
            }
        }

        $swapWith = $direction === 'up' ? $position - 1 : $position + 1;

        if ($position !== null && $swapWith >= 0 && $swapWith < count($sidebarWidgets)) {
            [$sidebarWidgets[$position], $sidebarWidgets[$swapWith]] = [$sidebarWidgets[$swapWith], $sidebarWidgets[$position]];
            $widgetsConfig[$sidebarId] = $sidebarWidgets;
            $saveWidgetsConfig($widgetsConfig);
            $kernel->widgets->setWidgets($sidebarId, $sidebarWidgets);
        }

        header('Location: ' . admin_url('appearance/widgets') . '?saved=1');
        exit;
    } elseif ($form === 'reposition_widget') {
        // Splice out the dragged widget, then splice it back in at the
        // target's position — works for a drag to any position, not just
        // an adjacent swap.
        $widgetId = $postedWidgetId;
        $targetId = $postedTargetId;
        $widgetsConfig = $loadWidgetsConfig();
        $sidebarWidgets = $widgetsConfig[$sidebarId] ?? [];

        $dragged = null;
        $remaining = [];

        foreach ($sidebarWidgets as $widget) {
            if (($widget['id'] ?? null) === $widgetId) {
                $dragged = $widget;
            } else {
                $remaining[] = $widget;
            }
        }

        $targetIndex = null;

        foreach ($remaining as $index => $widget) {
            if (($widget['id'] ?? null) === $targetId) {
                $targetIndex = $index;

                break;
            }
        }

        if ($dragged !== null && $targetIndex !== null) {
            $insertAt = $postedPosition === 'after' ? $targetIndex + 1 : $targetIndex;
            array_splice($remaining, $insertAt, 0, [$dragged]);
            $widgetsConfig[$sidebarId] = $remaining;
            $saveWidgetsConfig($widgetsConfig);
            $kernel->widgets->setWidgets($sidebarId, $remaining);
        }

        header('Location: ' . admin_url('appearance/widgets') . '?saved=1');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Widgets</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['deactivated'])): ?>
    <div class="lp-alert lp-alert--success">Widget moved to Inactive Widgets.</div>
<?php endif; ?>

<?php if (isset($_GET['activated'])): ?>
    <div class="lp-alert lp-alert--success">Widget activated.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Widget permanently deleted.</div>
<?php endif; ?>

<?php if ($kernel->widgets->sidebars() === []): ?>
    <p class="lp-admin__widget-placeholder">The active theme doesn't register any widget areas.</p>
<?php endif; ?>

<?php foreach ($kernel->widgets->sidebars() as $sidebarId => $sidebar): ?>
    <section class="lp-admin__panel lp-widgets-area">
        <h2><?= esc_html($sidebar['name']) ?></h2>
        <?php if ($sidebar['description'] !== ''): ?>
            <p class="lp-field__hint"><?= esc_html($sidebar['description']) ?></p>
        <?php endif; ?>

        <?php $sidebarWidgets = $kernel->widgets->widgetsFor($sidebarId); ?>

        <?php if ($sidebarWidgets === []): ?>
            <p class="lp-admin__widget-placeholder">No widgets in this area yet.</p>
        <?php else: ?>
            <div data-lp-sortable-group="widgets-<?= esc_attr($sidebarId) ?>">
            <ul class="lp-widgets-list">
                <?php foreach ($sidebarWidgets as $position => $widget): ?>
                    <?php
                    $widgetType = $kernel->widgets->widgetTypes()[$widget['type']] ?? null;
                    $fields = $settingsFieldsFor($widget['type']);
                    ?>
                    <li class="lp-widgets-list__item" data-lp-sortable-item data-lp-sortable-id="<?= esc_attr($widget['id']) ?>">
                        <details class="lp-widgets-list__details">
                            <summary class="lp-widgets-list__summary">
                                <span class="lp-drag-handle" data-lp-drag-handle aria-hidden="true" title="Drag to reorder">&#10303;</span>
                                <?= esc_html($widgetType['label'] ?? $widget['type']) ?>
                                <?php if (($widget['settings']['title'] ?? '') !== ''): ?>
                                    <span class="lp-widgets-list__instance-title">&mdash; <?= esc_html((string) $widget['settings']['title']) ?></span>
                                <?php endif; ?>
                            </summary>

                            <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-widgets-list__settings">
                                <?= Csrf::field('widget_update_' . $widget['id']) ?>
                                <input type="hidden" name="form" value="update_widget">
                                <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                                <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">

                                <?php $renderWidgetSettingsFields($widget, $fields); ?>

                                <button type="submit" class="lp-button lp-button--primary">Save</button>
                            </form>

                            <div class="lp-widgets-list__actions">
                                <?php if ($position > 0): ?>
                                    <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-admin__inline-form">
                                        <?= Csrf::field('widget_move_up_' . $widget['id']) ?>
                                        <input type="hidden" name="form" value="move_widget">
                                        <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                                        <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="lp-button lp-button--secondary">Move Up</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($position < count($sidebarWidgets) - 1): ?>
                                    <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-admin__inline-form">
                                        <?= Csrf::field('widget_move_down_' . $widget['id']) ?>
                                        <input type="hidden" name="form" value="move_widget">
                                        <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                                        <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="lp-button lp-button--secondary">Move Down</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('widget_deactivate_' . $widget['id']) ?>
                                    <input type="hidden" name="form" value="deactivate_widget">
                                    <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                                    <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">
                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Deactivate</button>
                                </form>
                                <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-admin__inline-form" data-lp-confirm="Permanently delete this widget? This cannot be undone.">
                                    <?= Csrf::field('widget_delete_' . $widget['id']) ?>
                                    <input type="hidden" name="form" value="delete_widget">
                                    <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                                    <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">
                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                                </form>
                            </div>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" data-lp-sortable-reposition-form>
                <?= Csrf::field('widget_reposition_' . $sidebarId) ?>
                <input type="hidden" name="form" value="reposition_widget">
                <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                <input type="hidden" name="widget_id" data-lp-sortable-field="dragged_id">
                <input type="hidden" name="target_id" data-lp-sortable-field="target_id">
                <input type="hidden" name="position" data-lp-sortable-field="position">
            </form>
            </div>
        <?php endif; ?>

        <?php if ($kernel->widgets->widgetTypes() !== []): ?>
            <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-widgets-add-form">
                <?= Csrf::field('widget_add_' . $sidebarId) ?>
                <input type="hidden" name="form" value="add_widget">
                <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">

                <label class="lp-visually-hidden" for="add-widget-type-<?= esc_attr($sidebarId) ?>">Widget type</label>
                <select id="add-widget-type-<?= esc_attr($sidebarId) ?>" name="widget_type">
                    <?php foreach ($kernel->widgets->widgetTypes() as $type => $info): ?>
                        <option value="<?= esc_attr($type) ?>"><?= esc_html($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="lp-button lp-button--primary">Add Widget</button>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php
// Always rendered (even when empty) so the feature is discoverable before
// a widget has ever been deactivated.
$inactiveWidgets = $kernel->widgets->widgetsFor(WidgetManager::INACTIVE_SIDEBAR_ID);
?>
<section class="lp-admin__panel lp-widgets-area lp-widgets-area--inactive">
    <h2>Inactive Widgets</h2>
    <p class="lp-field__hint">Widgets deactivated from a widget area above are kept here with their settings intact. Activate one into any widget area, or delete it permanently.</p>

    <?php if ($inactiveWidgets === []): ?>
        <p class="lp-admin__widget-placeholder">No inactive widgets.</p>
    <?php else: ?>
        <ul class="lp-widgets-list">
            <?php foreach ($inactiveWidgets as $widget): ?>
                <?php
                $widgetType = $kernel->widgets->widgetTypes()[$widget['type']] ?? null;
                $fields = $settingsFieldsFor($widget['type']);
                ?>
                <li class="lp-widgets-list__item">
                    <details class="lp-widgets-list__details">
                        <summary class="lp-widgets-list__summary">
                            <?= esc_html($widgetType['label'] ?? $widget['type']) ?>
                            <?php if (($widget['settings']['title'] ?? '') !== ''): ?>
                                <span class="lp-widgets-list__instance-title">&mdash; <?= esc_html((string) $widget['settings']['title']) ?></span>
                            <?php endif; ?>
                        </summary>

                        <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-widgets-list__settings">
                            <?= Csrf::field('widget_update_' . $widget['id']) ?>
                            <input type="hidden" name="form" value="update_widget">
                            <input type="hidden" name="sidebar_id" value="<?= esc_attr(WidgetManager::INACTIVE_SIDEBAR_ID) ?>">
                            <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">

                            <?php $renderWidgetSettingsFields($widget, $fields); ?>

                            <button type="submit" class="lp-button lp-button--primary">Save</button>
                        </form>

                        <div class="lp-widgets-list__actions">
                            <?php if ($kernel->widgets->sidebars() !== []): ?>
                                <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-widgets-list__activate-form">
                                    <?= Csrf::field('widget_activate_' . $widget['id']) ?>
                                    <input type="hidden" name="form" value="activate_widget">
                                    <input type="hidden" name="sidebar_id" value="<?= esc_attr(WidgetManager::INACTIVE_SIDEBAR_ID) ?>">
                                    <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">
                                    <label class="lp-visually-hidden" for="activate-target-<?= esc_attr($widget['id']) ?>">Widget area</label>
                                    <select id="activate-target-<?= esc_attr($widget['id']) ?>" name="target_sidebar_id">
                                        <?php foreach ($kernel->widgets->sidebars() as $targetSidebarId => $targetSidebar): ?>
                                            <option value="<?= esc_attr($targetSidebarId) ?>"><?= esc_html($targetSidebar['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="lp-button lp-button--secondary">Activate</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-admin__inline-form" data-lp-confirm="Permanently delete this widget? This cannot be undone.">
                                <?= Csrf::field('widget_delete_' . $widget['id']) ?>
                                <input type="hidden" name="form" value="delete_widget">
                                <input type="hidden" name="sidebar_id" value="<?= esc_attr(WidgetManager::INACTIVE_SIDEBAR_ID) ?>">
                                <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">
                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete Permanently</button>
                            </form>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
