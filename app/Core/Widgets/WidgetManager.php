<?php

declare(strict_types=1);

namespace LumoraPress\Core\Widgets;

/**
 * Widget area (sidebar) and widget type registration, plus rendering of
 * whatever widgets have been assigned to a given area.
 */
final class WidgetManager
{
    /** @var array<string, array{name: string, description: string}> */
    private array $sidebars = [];

    /** @var array<string, array{label: string, render: callable}> */
    private array $widgetTypes = [];

    /** @var array<string, array<int, array{type: string, settings: array<string, mixed>}>> */
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
    public function assign(string $sidebarId, string $widgetType, array $settings = []): void
    {
        $this->assignments[$sidebarId][] = ['type' => $widgetType, 'settings' => $settings];
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
}
