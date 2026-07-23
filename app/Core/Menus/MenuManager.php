<?php

declare(strict_types=1);

namespace LumoraPress\Core\Menus;

/**
 * Navigation menu locations (primary, footer, social, secondary) and the
 * items assigned to each. Item assignment will move to the admin UI in a
 * later phase; for now menus are assigned programmatically or by themes.
 */
final class MenuManager
{
    /** @var array<string, string> */
    private array $locations = [];

    /** @var array<string, array<int, array{label: string, url: string, target: string}>> */
    private array $assignments = [];

    public function registerLocation(string $slug, string $label): void
    {
        $this->locations[$slug] = $label;
    }

    /**
     * @return array<string, string>
     */
    public function locations(): array
    {
        return $this->locations;
    }

    /**
     * @param array<int, array{label: string, url: string, target?: string}> $items
     */
    public function assign(string $location, array $items): void
    {
        $this->assignments[$location] = array_map(
            static fn (array $item): array => [
                'label' => $item['label'],
                'url' => $item['url'],
                'target' => $item['target'] ?? '_self',
            ],
            $items,
        );
    }

    /**
     * @return array<int, array{label: string, url: string, target: string}>
     */
    public function items(string $location): array
    {
        return $this->assignments[$location] ?? [];
    }

    public function hasItems(string $location): bool
    {
        return !empty($this->assignments[$location]);
    }
}
