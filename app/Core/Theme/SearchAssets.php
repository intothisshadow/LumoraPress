<?php

/**
 * Loads the site search script (suggestions, live results, recent and popular searches, in-place results) on public pages.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use LumoraPress\Core\Security\Auth;
use LumoraPress\Services\SearchService;

/**
 * Registered on `footer_assets`, so every theme's search boxes (header,
 * widget, and the search page's own form) get these features without the
 * theme knowing the script exists. Settings reach the script as data-*
 * attributes because the CSP forbids inline script. Pages rendered for a
 * signed-in user are never cached, so data-signed-in is always accurate.
 */
final class SearchAssets
{
    public function __construct(
        private readonly SearchService $search,
        private readonly Auth $auth,
    ) {
    }

    public function render(): void
    {
        $attributes = [
            'data-lp-search' => '',
            'data-min-length' => (string) $this->search->minimumQueryLength(),
            'data-search-url' => site_url('search'),
            'data-suggestions-endpoint' => $this->search->suggestionsEnabled() ? site_url('search/suggestions') : '',
            'data-live-endpoint' => $this->search->liveSearchEnabled() ? site_url('search/live') : '',
            'data-panel-endpoint' => $this->search->historyEnabled() || $this->search->popularSearchesEnabled() ? site_url('search/panel') : '',
            'data-clear-endpoint' => site_url('search/history/clear'),
            'data-history' => $this->search->historyEnabled() ? '1' : '0',
            'data-signed-in' => $this->auth->check() ? '1' : '0',
        ];

        $html = '<script defer';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . esc_attr($value) . '"';
        }

        echo $html . ' src="' . esc_url(core_asset_url('js/search.js')) . '"></script>' . "\n";
    }
}
