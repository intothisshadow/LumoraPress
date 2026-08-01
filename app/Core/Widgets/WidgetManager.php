<?php

declare(strict_types=1);

namespace LumoraPress\Core\Widgets;

/**
 * Widget area (sidebar) and widget type registration, plus rendering of
 * whatever widgets have been assigned to a given area.
 *
 * Each assigned widget carries a stable string `id` (LP-048), generated
 * automatically by assign() if the caller doesn't supply one — themes
 * assigning widgets programmatically never need to think about ids, but
 * the admin Widgets screen (which needs to add/remove/reorder individual
 * instances of the same widget type) does, and setWidgets()/removeWidget()
 * operate on that id.
 */
final class WidgetManager
{
    /** @var array<string, array{name: string, description: string}> */
    private array $sidebars = [];

    /** @var array<string, array{label: string, render: callable}> */
    private array $widgetTypes = [];

    /** @var array<string, array<int, array{id: string, type: string, settings: array<string, mixed>}>> */
    private array $assignments = [];

    public function registerSidebar(string $id, string $name, string $description = ''): void
    {
        $this->sidebars[$id] = ['name' => $name, 'description' => $description];
    }

    /**
     * @return array<string, array{name: string, description: string}>
     */
    public function sidebars(): array
    {
        return $this->sidebars;
    }

    public function registerWidget(string $type, string $label, callable $render): void
    {
        $this->widgetTypes[$type] = ['label' => $label, 'render' => $render];
    }

    /**
     * @return array<string, array{label: string, render: callable}>
     */
    public function widgetTypes(): array
    {
        return $this->widgetTypes;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function assign(string $sidebarId, string $widgetType, array $settings = [], ?string $id = null): void
    {
        $this->assignments[$sidebarId][] = [
            'id' => $id ?? self::generateId(),
            'type' => $widgetType,
            'settings' => $settings,
        ];
    }

    /**
     * Replaces every widget assigned to $sidebarId with $widgets — used to
     * load persisted assignments at bootstrap and to save the admin
     * Widgets screen's form. Each entry must have 'id', 'type', and
     * 'settings' keys; entries missing 'id' get one generated, so callers
     * restoring from storage that predates ids still work.
     *
     * @param array<int, array{id?: string, type: string, settings: array<string, mixed>}> $widgets
     */
    public function setWidgets(string $sidebarId, array $widgets): void
    {
        $this->assignments[$sidebarId] = array_values(array_map(
            static fn (array $widget): array => [
                'id' => $widget['id'] ?? self::generateId(),
                'type' => $widget['type'],
                'settings' => $widget['settings'],
            ],
            $widgets,
        ));
    }

    /**
     * The raw, ordered widget list assigned to $sidebarId — for the admin
     * Widgets screen to list/edit, unlike renderSidebar() which produces
     * HTML output instead.
     *
     * @return array<int, array{id: string, type: string, settings: array<string, mixed>}>
     */
    public function widgetsFor(string $sidebarId): array
    {
        return $this->assignments[$sidebarId] ?? [];
    }

    public function removeWidget(string $sidebarId, string $id): void
    {
        $this->assignments[$sidebarId] = array_values(array_filter(
            $this->assignments[$sidebarId] ?? [],
            static fn (array $widget): bool => $widget['id'] !== $id,
        ));
    }

    public function renderSidebar(string $sidebarId): void
    {
        foreach ($this->assignments[$sidebarId] ?? [] as $widget) {
            $type = $this->widgetTypes[$widget['type']] ?? null;

            if ($type === null) {
                continue;
            }

            ($type['render'])($widget['settings']);
        }
    }

    public function hasWidgets(string $sidebarId): bool
    {
        return !empty($this->assignments[$sidebarId]);
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(6));
    }
}
