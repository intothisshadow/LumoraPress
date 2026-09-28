<?php

/**
 * Loads the search box suggestion script on public pages.
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

use LumoraPress\Services\SearchService;

/**
 * Registered on `footer_assets`, so every theme's search boxes (header,
 * widget, and the search page's own form) get suggestions without the
 * theme knowing the script exists. Settings reach the script as data-*
 * attributes because the CSP forbids inline script.
 */
final class SearchAssets
{
    public function __construct(private readonly SearchService $search)
    {
    }

    public function render(): void
    {
        if (!$this->search->suggestionsEnabled()) {
            return;
        }

        printf(
            '<script defer data-lp-search-suggest data-endpoint="%s" data-min-length="%d" src="%s"></script>' . "\n",
            esc_url(site_url('search/suggestions')),
            $this->search->minimumQueryLength(),
            esc_url(core_asset_url('js/search-suggest.js')),
        );
    }
}
