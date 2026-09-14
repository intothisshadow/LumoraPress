<?php

/**
 * POST-handling logic for the admin Appearance > Widgets screen.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Widgets\WidgetManager;

/**
 * POST-handling logic for the admin Appearance > Widgets screen. The view
 * stays a thin wrapper: reads $_POST, calls a method here, and turns the
 * returned AdminActionResult into a redirect or an error.
 *
 * Widget assignments persist as one JSON option ("widgets_config", keyed
 * by sidebar id) on PressConfig, mirroring the view's own pre-extraction
 * load/save closures. INACTIVE_SIDEBAR_ID is a reserved "sidebar" id in
 * that same structure that holds deactivated widgets until reactivated
 * or deleted.
 *
 * Every CSRF action name is scoped per widget/sidebar/direction id — a
 * shared name across every widget's form would let one widget's request
 * invalidate another's token, the same reasoning ThemesController's own
 * docblock gives for per-slug scoping.
 */
final class WidgetsController
{
    public function __construct(
        private readonly WidgetManager $widgets,
        private readonly PressConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function addWidget(array $post, ?string $csrfToken): AdminActionResult
    {
        $sidebarId = trim((string) ($post['sidebar_id'] ?? ''));

        if (!Csrf::verify('widget_add_' . $sidebarId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!$this->isKnownSidebar($sidebarId)) {
            return AdminActionResult::error('Unknown widget area.');
        }

        $widgetType = trim((string) ($post['widget_type'] ?? ''));

        if (!array_key_exists($widgetType, $this->widgets->widgetTypes())) {
            return AdminActionResult::error('Unknown widget type.');
        }

        $widgetsConfig = $this->loadConfig();
        $widgetsConfig[$sidebarId] ??= [];
        $widgetsConfig[$sidebarId][] = ['type' => $widgetType, 'settings' => []];
        $this->widgets->setWidgets($sidebarId, $widgetsConfig[$sidebarId]);
        $widgetsConfig[$sidebarId] = $this->widgets->widgetsFor($sidebarId);
        $this->saveConfig($widgetsConfig);

        return AdminActionResult::redirect(admin_url('appearance/widgets') . '?saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function updateWidget(array $post, ?string $csrfToken): AdminActionResult
    {
        $sidebarId = trim((string) ($post['sidebar_id'] ?? ''));
        $widgetId = trim((string) ($post['widget_id'] ?? ''));

        if (!Csrf::verify('widget_update_' . $widgetId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!$this->isKnownSidebar($sidebarId)) {
            return AdminActionResult::error('Unknown widget area.');
        }

        $widgetsConfig = $this->loadConfig();
        $sidebarWidgets = $widgetsConfig[$sidebarId] ?? [];
        $matched = false;

        foreach ($sidebarWidgets as $index => $widget) {
            if (($widget['id'] ?? null) === $widgetId) {
                $fields = self::fieldTypesFor($widget['type']);
                $settings = [];

                foreach ($fields as $key => $type) {
                    $raw = $post['settings'][$key] ?? null;
                    $settings[$key] = $type === 'checkbox' ? ($raw === '1' ? '1' : '0') : trim((string) $raw);
                }

                $sidebarWidgets[$index]['settings'] = $settings;
                $matched = true;

                break;
            }
        }

        if (!$matched) {
            return AdminActionResult::error('That widget no longer exists.');
        }

        $widgetsConfig[$sidebarId] = $sidebarWidgets;
        $this->saveConfig($widgetsConfig);
        $this->widgets->setWidgets($sidebarId, $sidebarWidgets);

        return AdminActionResult::redirect(admin_url('appearance/widgets') . '?saved=1');
    }

    /**
     * Moves the widget into the reserved INACTIVE_SIDEBAR_ID bucket
     * instead of deleting it, preserving settings for later reactivation.
     *
     * @param array<string, mixed> $post
     */
    public function deactivateWidget(array $post, ?string $csrfToken): AdminActionResult
    {
        $sidebarId = trim((string) ($post['sidebar_id'] ?? ''));
        $widgetId = trim((string) ($post['widget_id'] ?? ''));

        if (!Csrf::verify('widget_deactivate_' . $widgetId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!$this->isKnownSidebar($sidebarId)) {
            return AdminActionResult::error('Unknown widget area.');
        }

        $widgetsConfig = $this->loadConfig();
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
            $this->saveConfig($widgetsConfig);
            $this->widgets->setWidgets($sidebarId, $remaining);
            $this->widgets->setWidgets(WidgetManager::INACTIVE_SIDEBAR_ID, $widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID]);
        }

        return AdminActionResult::redirect(admin_url('appearance/widgets') . '?deactivated=1');
    }

    /**
     * $sidebarId in $post is always INACTIVE_SIDEBAR_ID (the source);
     * target_sidebar_id is the destination the admin picked.
     *
     * @param array<string, mixed> $post
     */
    public function activateWidget(array $post, ?string $csrfToken): AdminActionResult
    {
        $widgetId = trim((string) ($post['widget_id'] ?? ''));
        $targetSidebarId = trim((string) ($post['target_sidebar_id'] ?? ''));

        if (!Csrf::verify('widget_activate_' . $widgetId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!array_key_exists($targetSidebarId, $this->widgets->sidebars())) {
            return AdminActionResult::error('Choose a widget area to activate this widget into.');
        }

        $widgetsConfig = $this->loadConfig();
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
            $widgetsConfig[$targetSidebarId] ??= [];
            $widgetsConfig[$targetSidebarId][] = $moving;
            $this->saveConfig($widgetsConfig);
            $this->widgets->setWidgets(WidgetManager::INACTIVE_SIDEBAR_ID, $remaining);
            $this->widgets->setWidgets($targetSidebarId, $widgetsConfig[$targetSidebarId]);
        }

        return AdminActionResult::redirect(admin_url('appearance/widgets') . '?activated=1');
    }

    /**
     * Permanent removal — used from the Inactive Widgets list once a
     * widget's settings are no longer wanted at all.
     *
     * @param array<string, mixed> $post
     */
    public function deleteWidget(array $post, ?string $csrfToken): AdminActionResult
    {
        $sidebarId = trim((string) ($post['sidebar_id'] ?? ''));
        $widgetId = trim((string) ($post['widget_id'] ?? ''));

        if (!Csrf::verify('widget_delete_' . $widgetId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!$this->isKnownSidebar($sidebarId)) {
            return AdminActionResult::error('Unknown widget area.');
        }

        $widgetsConfig = $this->loadConfig();
        $widgetsConfig[$sidebarId] = array_values(array_filter(
            $widgetsConfig[$sidebarId] ?? [],
            static fn (array $widget): bool => ($widget['id'] ?? null) !== $widgetId,
        ));
        $this->saveConfig($widgetsConfig);
        $this->widgets->setWidgets($sidebarId, $widgetsConfig[$sidebarId]);

        return AdminActionResult::redirect(admin_url('appearance/widgets') . '?deleted=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function moveWidget(array $post, ?string $csrfToken): AdminActionResult
    {
        $sidebarId = trim((string) ($post['sidebar_id'] ?? ''));
        $widgetId = trim((string) ($post['widget_id'] ?? ''));
        $direction = (string) ($post['direction'] ?? '');

        if (!Csrf::verify('widget_move_' . $direction . '_' . $widgetId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!$this->isKnownSidebar($sidebarId)) {
            return AdminActionResult::error('Unknown widget area.');
        }

        $widgetsConfig = $this->loadConfig();
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
            $this->saveConfig($widgetsConfig);
            $this->widgets->setWidgets($sidebarId, $sidebarWidgets);
        }

        return AdminActionResult::redirect(admin_url('appearance/widgets') . '?saved=1');
    }

    /**
     * Splices out the dragged widget, then splices it back in at the
     * target's position — works for a drag to any position, not just an
     * adjacent swap.
     *
     * @param array<string, mixed> $post
     */
    public function repositionWidget(array $post, ?string $csrfToken): AdminActionResult
    {
        $sidebarId = trim((string) ($post['sidebar_id'] ?? ''));
        $widgetId = trim((string) ($post['widget_id'] ?? ''));
        $targetId = trim((string) ($post['target_id'] ?? ''));
        $position = (string) ($post['position'] ?? 'before');

        if (!Csrf::verify('widget_reposition_' . $sidebarId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!$this->isKnownSidebar($sidebarId)) {
            return AdminActionResult::error('Unknown widget area.');
        }

        $widgetsConfig = $this->loadConfig();
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
            $insertAt = $position === 'after' ? $targetIndex + 1 : $targetIndex;
            array_splice($remaining, $insertAt, 0, [$dragged]);
            $widgetsConfig[$sidebarId] = $remaining;
            $this->saveConfig($widgetsConfig);
            $this->widgets->setWidgets($sidebarId, $remaining);
        }

        return AdminActionResult::redirect(admin_url('appearance/widgets') . '?saved=1');
    }

    private function isKnownSidebar(string $sidebarId): bool
    {
        return $sidebarId === WidgetManager::INACTIVE_SIDEBAR_ID || array_key_exists($sidebarId, $this->widgets->sidebars());
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $decoded = json_decode((string) $this->config->option('widgets_config', '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $widgetsConfig
     */
    private function saveConfig(array $widgetsConfig): void
    {
        $this->config->setOption('widgets_config', json_encode($widgetsConfig));
    }

    /**
     * Field key => field type per widget type, used only to decide how a
     * posted setting value is normalized (a checkbox becomes '1'/'0',
     * everything else is trimmed) — kept in sync with the render-side
     * field definitions in admin/views/appearance/widgets.php.
     *
     * @return array<string, string>
     */
    private static function fieldTypesFor(string $widgetType): array
    {
        $title = ['title' => 'text'];

        return match ($widgetType) {
            'text' => [...$title, 'text' => 'wysiwyg'],
            'custom_html' => [...$title, 'html' => 'code'],
            'custom_php' => [...$title, 'code' => 'code'],
            'search' => $title,
            'nav_menu' => [...$title, 'location' => 'select'],
            'pages' => $title,
            'categories' => [...$title, 'show_count' => 'checkbox'],
            'recent_posts' => [...$title, 'limit' => 'number'],
            'recent_comments' => [...$title, 'limit' => 'number'],
            'archives' => [...$title, 'limit' => 'number'],
            'tag_cloud', 'meta', 'statistics' => $title,
            'social_links' => [
                ...$title,
                'website' => 'text',
                'email' => 'text',
                'mastodon' => 'text',
                'bluesky' => 'text',
                'twitter' => 'text',
                'github' => 'text',
                'youtube' => 'text',
                'instagram' => 'text',
                'discord' => 'text',
            ],
            default => $title,
        };
    }

    /**
     * A failed CSRF check gets an actual error message rather than
     * silently falling through to a normal re-render.
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
