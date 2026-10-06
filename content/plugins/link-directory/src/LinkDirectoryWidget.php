<?php

/**
 * The Links widget: a sidebar/footer list of Link Directory entries, as plain text links or a row of 100×50 buttons.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.2.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use LumoraPress\Services\MediaService;

/**
 * Settings come from the Widgets screen as free text, so every value is
 * checked against its allowed set here rather than trusted.
 */
final class LinkDirectoryWidget
{
    public const TYPE = 'link_directory_links';

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 100;

    private const ORDERS = ['title', 'newest', 'random'];

    /**
     * Services are given together in tests; the plugin's bootstrap passes
     * none, so they're opened on first render.
     */
    public function __construct(
        private readonly ?LinkService $injectedLinks = null,
        private readonly ?MediaService $injectedMedia = null,
    ) {
    }

    /**
     * Settings fields for the Widgets screen, in the shape
     * `widget_settings_fields` expects.
     *
     * @param array<string, string> $categoryChoices category id => name
     * @return array<int, array{key: string, label: string, type: string, options?: array<string, string>, empty_label?: string, hint?: string}>
     */
    public static function settingsFields(array $categoryChoices): array
    {
        return [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['key' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => $categoryChoices, 'empty_label' => '(All categories)'],
            ['key' => 'limit', 'label' => 'Number of links to show', 'type' => 'number'],
            ['key' => 'order', 'label' => 'Order', 'type' => 'select', 'options' => ['newest' => 'Newest first', 'random' => 'Random'], 'empty_label' => 'Alphabetical'],
            [
                'key' => 'display',
                'label' => 'Display',
                'type' => 'select',
                'options' => ['buttons' => '100×50 buttons'],
                'empty_label' => 'Plain text links',
                'hint' => 'A link without a button image shows its title instead.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function render(array $settings): void
    {
        echo $this->html($settings);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function html(array $settings): string
    {
        $links = $this->links();
        $categoryId = (int) ($settings['category_id'] ?? 0);
        $limit = (int) ($settings['limit'] ?? 0);
        $order = (string) ($settings['order'] ?? '');

        $items = $links->listForWidget(
            $categoryId > 0 ? $categoryId : null,
            $limit > 0 ? min($limit, self::MAX_LIMIT) : self::DEFAULT_LIMIT,
            in_array($order, self::ORDERS, true) ? $order : 'title',
        );

        if ($items === []) {
            return '';
        }

        $title = trim((string) ($settings['title'] ?? ''));
        $asButtons = ($settings['display'] ?? '') === 'buttons';

        $html = '<section class="lp-widget lp-widget--link-directory">';

        if ($title !== '') {
            $html .= '<h3 class="lp-widget__title">' . esc_html($title) . '</h3>';
        }

        if ($asButtons) {
            $renderer = new LinkButtonRenderer($this->media());
            $html .= '<div class="lp-link-buttons">';

            foreach ($items as $item) {
                $html .= $renderer->badge($item);
            }

            $html .= '</div>';
        } else {
            $html .= '<ul class="lp-widget__list lp-widget__link-directory-list">';

            foreach ($items as $item) {
                $html .= '<li><a href="' . esc_url($item->url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($item->title) . '</a></li>';
            }

            $html .= '</ul>';
        }

        return $html . '</section>';
    }

    private function links(): LinkService
    {
        if ($this->injectedLinks !== null) {
            return $this->injectedLinks;
        }

        [$database, $tablePrefix] = LinkDirectoryConnection::open();

        return new LinkService($database, $tablePrefix);
    }

    private function media(): MediaService
    {
        return $this->injectedMedia ?? LinkDirectoryConnection::media();
    }
}
