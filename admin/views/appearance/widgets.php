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

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-048: widget assignments are persisted as one JSON option
 * ("widgets_config", keyed by sidebar id) rather than a dedicated table —
 * same "structured value as JSON in the options table" approach
 * active_plugins already uses. Every form below loads the full config,
 * mutates the one sidebar/widget it targets, and saves the whole thing
 * back; WidgetManager itself (in-memory for this request only) is kept in
 * sync too so the page re-renders with the change reflected immediately
 * without needing a redirect round-trip through bootstrap.php's own
 * loader.
 */
$loadWidgetsConfig = static function () use ($kernel): array {
    $decoded = json_decode((string) $kernel->config->option('widgets_config', '{}'), true);

    return is_array($decoded) ? $decoded : [];
};

$saveWidgetsConfig = static function (array $widgetsConfig) use ($kernel): void {
    $kernel->config->setOption('widgets_config', json_encode($widgetsConfig));
};

/**
 * Field definitions per widget type, used to both render each widget's
 * settings form and to know which POSTed settings[] keys to keep on
 * save. Keeping this in one place avoids the render form and the save
 * handler drifting out of sync with each other.
 *
 * @return array<int, array{key: string, label: string, type: string, options?: array<string, string>}>
 */
$settingsFieldsFor = static function (string $widgetType) use ($kernel): array {
    $titleField = ['key' => 'title', 'label' => 'Title', 'type' => 'text'];

    return match ($widgetType) {
        'text' => [$titleField, ['key' => 'text', 'label' => 'Content', 'type' => 'textarea']],
        'custom_html' => [$titleField, ['key' => 'html', 'label' => 'Content', 'type' => 'code']],
        'search' => [$titleField],
        'nav_menu' => [$titleField, ['key' => 'location', 'label' => 'Menu', 'type' => 'select', 'options' => $kernel->menus->locations()]],
        'pages' => [$titleField, ['key' => 'limit', 'label' => 'Number of pages to show', 'type' => 'number']],
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

$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$postedWidgetId = trim((string) ($_POST['widget_id'] ?? ''));
$postedSidebarId = trim((string) ($_POST['sidebar_id'] ?? ''));
$postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

/*
 * Every widget on this page renders its own Save/Move Up/Move Down/Remove
 * form, and one "Add Widget" form per sidebar — many forms on one page
 * load. Csrf::field()/verify() are keyed by action *name*, and
 * Csrf::field() overwrites the session's token for a given name on every
 * call, so reusing one shared name across all of them would leave every
 * form but the last-rendered one silently submitting an
 * already-invalidated token (see CommentService's/SiteController's own
 * docblocks for the LP-012 incident this exact mistake caused). Each
 * action name below is scoped to the specific widget/sidebar id it acts
 * on instead.
 */
$postedDirection = (string) ($_POST['direction'] ?? '');
$csrfAction = match ($form) {
    'add_widget' => 'widget_add_' . $postedSidebarId,
    'update_widget' => 'widget_update_' . $postedWidgetId,
    // Move Up and Move Down render as two separate forms for the same
    // widget id — scoped by direction too, or the second-rendered form's
    // Csrf::field() call would overwrite the first's token (same
    // per-form-not-just-per-page scoping this whole action-name scheme
    // exists for).
    'move_widget' => 'widget_move_' . $postedDirection . '_' . $postedWidgetId,
    'remove_widget' => 'widget_remove_' . $postedWidgetId,
    default => 'widget_unknown_form',
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($csrfAction, $postedToken)) {
    $sidebarId = $postedSidebarId;
    $knownSidebars = $kernel->widgets->sidebars();

    if (!array_key_exists($sidebarId, $knownSidebars)) {
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
    } elseif ($form === 'remove_widget') {
        $widgetId = $postedWidgetId;
        $widgetsConfig = $loadWidgetsConfig();
        $widgetsConfig[$sidebarId] = array_values(array_filter(
            $widgetsConfig[$sidebarId] ?? [],
            static fn (array $widget): bool => ($widget['id'] ?? null) !== $widgetId,
        ));
        $saveWidgetsConfig($widgetsConfig);
        $kernel->widgets->setWidgets($sidebarId, $widgetsConfig[$sidebarId]);

        header('Location: ' . admin_url('appearance/widgets') . '?removed=1');
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

<?php if (isset($_GET['removed'])): ?>
    <div class="lp-alert lp-alert--success">Widget removed.</div>
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
            <ul class="lp-widgets-list">
                <?php foreach ($sidebarWidgets as $position => $widget): ?>
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
                                <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                                <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">

                                <?php foreach ($fields as $field): ?>
                                    <?php $fieldId = 'widget-' . $widget['id'] . '-' . $field['key']; ?>
                                    <?php $value = $widget['settings'][$field['key']] ?? ''; ?>
                                    <p class="lp-field">
                                        <?php if ($field['type'] !== 'checkbox'): ?>
                                            <label for="<?= esc_attr($fieldId) ?>"><?= esc_html($field['label']) ?></label>
                                        <?php endif; ?>

                                        <?php if ($field['type'] === 'textarea'): ?>
                                            <textarea id="<?= esc_attr($fieldId) ?>" name="settings[<?= esc_attr($field['key']) ?>]" rows="4"><?= esc_html((string) $value) ?></textarea>
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
                                    </p>
                                <?php endforeach; ?>

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
                                <form method="post" action="<?= esc_url(admin_url('appearance/widgets')) ?>" class="lp-admin__inline-form" data-lp-confirm="Remove this widget?">
                                    <?= Csrf::field('widget_remove_' . $widget['id']) ?>
                                    <input type="hidden" name="form" value="remove_widget">
                                    <input type="hidden" name="sidebar_id" value="<?= esc_attr($sidebarId) ?>">
                                    <input type="hidden" name="widget_id" value="<?= esc_attr($widget['id']) ?>">
                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Remove</button>
                                </form>
                            </div>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
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
                <button type="submit" class="lp-button">Add Widget</button>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
