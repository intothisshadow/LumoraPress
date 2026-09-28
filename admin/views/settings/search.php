<?php

/**
 * The admin Settings > Search screen: what site search matches and includes, its word list, and search statistics.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\Search\SearchStatistics;
use LumoraPress\Services\SearchService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$searchVocabulary = $kernel->search->vocabulary();
$searchStatistics = $kernel->search->statistics();

if ($form === 'search_settings' && Csrf::verify('search_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->config->setOption('search_min_length', (string) max(1, min(50, (int) ($_POST['search_min_length'] ?? 3))));
    $kernel->config->setOption('search_max_results', (string) max(1, min(500, (int) ($_POST['search_max_results'] ?? 50))));

    foreach ([
        SearchService::PARTIAL_MATCHING_OPTION,
        SearchService::FUZZY_MATCHING_OPTION,
        SearchService::SUGGESTIONS_OPTION,
        SearchService::LIVE_OPTION,
        SearchService::HISTORY_OPTION,
        SearchService::POPULAR_OPTION,
        SearchStatistics::ENABLED_OPTION,
    ] as $toggleOption) {
        $kernel->config->setOption($toggleOption, ($_POST[$toggleOption] ?? '') === '1' ? '1' : '0');
    }

    // Stored as exclusions rather than inclusions, so a content type a
    // plugin registers later is searchable until someone opts it out.
    $includedSearchTypes = is_array($_POST['search_types'] ?? null) ? array_filter($_POST['search_types'], 'is_string') : [];
    $kernel->config->setOption('search_excluded_types', implode(',', array_diff(array_keys($kernel->search->typeLabels()), $includedSearchTypes)));

    foreach (['search_excluded_category_ids', 'search_excluded_page_ids'] as $idListOption) {
        $submittedIds = is_array($_POST[$idListOption] ?? null) ? array_filter($_POST[$idListOption], 'is_string') : [];
        $kernel->config->setOption($idListOption, implode(',', SearchService::parseIdList(implode(',', $submittedIds))));
    }

    header('Location: ' . admin_url('settings/search') . '?saved=1');
    exit;
} elseif ($form === 'search_rebuild_vocabulary' && $searchVocabulary !== null && Csrf::verify('search_rebuild_vocabulary', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $searchVocabulary->rebuild();

    header('Location: ' . admin_url('settings/search') . '?rebuilt=1');
    exit;
} elseif ($form === 'search_clear_statistics' && $searchStatistics !== null && Csrf::verify('search_clear_statistics', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $searchStatistics->clear();

    header('Location: ' . admin_url('settings/search') . '?cleared=1');
    exit;
}

$statisticsTotals = $searchStatistics?->totals() ?? ['queries' => 0, 'searches' => 0, 'since' => null];
$popularSearches = $searchStatistics?->popular(20) ?? [];
$unansweredSearches = $searchStatistics?->withoutResults(20) ?? [];
?>
<h1 class="lp-admin__title">Search</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php elseif (isset($_GET['rebuilt'])): ?>
    <div class="lp-alert lp-alert--success">Search word list rebuilt.</div>
<?php elseif (isset($_GET['cleared'])): ?>
    <div class="lp-alert lp-alert--success">Search statistics cleared.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Search Settings</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/search')) ?>">
        <?= Csrf::field('search_settings') ?>
        <input type="hidden" name="form" value="search_settings">

        <p class="lp-field">
            <label for="search-min-length">Minimum search query length</label>
            <input type="number" id="search-min-length" name="search_min_length" min="1" max="50" value="<?= esc_attr((string) $kernel->config->option('search_min_length', '3')) ?>">
        </p>

        <p class="lp-field">
            <label for="search-max-results">Maximum results (combined across all content types)</label>
            <input type="number" id="search-max-results" name="search_max_results" min="1" max="500" value="<?= esc_attr((string) $kernel->config->option('search_max_results', '50')) ?>">
        </p>

        <fieldset class="lp-field lp-field--checklist">
            <legend>Matching</legend>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="<?= esc_attr(SearchService::PARTIAL_MATCHING_OPTION) ?>" value="1" <?= $kernel->config->option(SearchService::PARTIAL_MATCHING_OPTION, '0') === '1' ? 'checked' : '' ?>>
                Match parts of words (a search for "graph" also finds "photography")
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="<?= esc_attr(SearchService::FUZZY_MATCHING_OPTION) ?>" value="1" <?= $kernel->config->option(SearchService::FUZZY_MATCHING_OPTION, '0') === '1' ? 'checked' : '' ?>>
                Match close misspellings (a search for "pirncess" also finds "princess")
            </label>

            <span class="lp-field__hint">Both use the words in your post and page titles and your category, tag, and author names (see Search Word List below). Exact matches always rank above these.</span>
        </fieldset>

        <fieldset class="lp-field lp-field--checklist">
            <legend>Search box</legend>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="<?= esc_attr(SearchService::SUGGESTIONS_OPTION) ?>" value="1" <?= $kernel->search->suggestionsEnabled() ? 'checked' : '' ?>>
                Suggest matching post and page titles while visitors type
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="<?= esc_attr(SearchService::LIVE_OPTION) ?>" value="1" <?= $kernel->search->liveSearchEnabled() ? 'checked' : '' ?>>
                Show live results while visitors type (replaces the title suggestions above in the header and widget search boxes)
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="<?= esc_attr(SearchService::HISTORY_OPTION) ?>" value="1" <?= $kernel->search->historyEnabled() ? 'checked' : '' ?>>
                Show visitors their own recent searches
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="<?= esc_attr(SearchService::POPULAR_OPTION) ?>" value="1" <?= $kernel->config->option(SearchService::POPULAR_OPTION, '0') === '1' ? 'checked' : '' ?>>
                Show popular searches
            </label>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="<?= esc_attr(SearchStatistics::ENABLED_OPTION) ?>" value="1" <?= $searchStatistics?->isEnabled() === true ? 'checked' : '' ?>>
                Keep search statistics
            </label>

            <span class="lp-field__hint">Recent searches appear when a visitor clicks into an empty search box. A signed-in user's are saved to their account (the last <?= (int) SearchService::RECENT_SEARCH_LIMIT ?>); everyone else's stay in their own browser and never reach the site. Popular searches appear there too and on the search page when nothing is found, but only terms searched at least <?= (int) SearchStatistics::PUBLIC_MIN_SEARCHES ?> times that found something, and only while search statistics are kept.</span>
            <span class="lp-field__hint">Statistics count what visitors search for, shown below. Only the search text and how often it was searched are kept (never who searched), searches by signed-in users aren't counted, and a search nobody has repeated for <?= (int) SearchStatistics::RETENTION_DAYS ?> days is deleted.</span>
        </fieldset>

        <?php $excludedSearchTypes = $kernel->search->excludedTypes(); ?>
        <fieldset class="lp-field lp-field--checklist">
            <legend>Content to include in search results</legend>
            <?php foreach ($kernel->search->typeLabels() as $searchType => $searchTypeLabel): ?>
                <label class="lp-field--checkbox">
                    <input type="checkbox" name="search_types[]" value="<?= esc_attr($searchType) ?>" <?= in_array($searchType, $excludedSearchTypes, true) ? '' : 'checked' ?>>
                    <?= esc_html($searchTypeLabel) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <?php $excludedSearchCategoryIds = $kernel->search->excludedCategoryIds(); ?>
        <?php $searchCategoryChoices = $kernel->categories->listAllForParentPicker(); ?>
        <?php if ($searchCategoryChoices !== []): ?>
            <fieldset class="lp-field lp-field--checklist">
                <legend>Exclude categories from search</legend>
                <span class="lp-field__hint">Posts filed in a ticked category never appear in search results, even if they are also in another category. The category itself is hidden from search too.</span>
                <div class="lp-field__scroll-list">
                    <?php foreach ($searchCategoryChoices as $searchCategory): ?>
                        <label class="lp-field--checkbox">
                            <input type="checkbox" name="search_excluded_category_ids[]" value="<?= (int) $searchCategory['id'] ?>" <?= in_array($searchCategory['id'], $excludedSearchCategoryIds, true) ? 'checked' : '' ?>>
                            <?= esc_html(str_repeat('— ', $searchCategory['depth']) . $searchCategory['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>

        <?php $excludedSearchPageIds = $kernel->search->excludedPageIds(); ?>
        <?php $searchPageChoices = $kernel->pages->listAllForParentPicker(); ?>
        <?php if ($searchPageChoices !== []): ?>
            <fieldset class="lp-field lp-field--checklist">
                <legend>Exclude pages from search</legend>
                <span class="lp-field__hint">Ticked pages stay published and reachable by their address, but never appear in search results.</span>
                <div class="lp-field__scroll-list">
                    <?php foreach ($searchPageChoices as $searchPage): ?>
                        <label class="lp-field--checkbox">
                            <input type="checkbox" name="search_excluded_page_ids[]" value="<?= (int) $searchPage['id'] ?>" <?= in_array($searchPage['id'], $excludedSearchPageIds, true) ? 'checked' : '' ?>>
                            <?= esc_html(str_repeat('— ', $searchPage['depth']) . $searchPage['title']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<?php if ($searchVocabulary !== null): ?>
    <section class="lp-admin__panel">
        <h2>Search Word List</h2>
        <p>"Did you mean?" suggestions and the two matching options above draw on a list of the words in your public post and page titles and your category, tag, and author names. It stays up to date as you publish; rebuilding it also drops words you no longer use.</p>
        <?php $vocabularyBuiltAt = $searchVocabulary->builtAt(); ?>
        <p>
            <?php if ($vocabularyBuiltAt === null): ?>
                Not built yet. It will be built automatically the first time a search needs it.
            <?php else: ?>
                <?= esc_html(number_format($searchVocabulary->size())) ?> words, last rebuilt <?= esc_html(the_date($vocabularyBuiltAt)) ?>.
            <?php endif; ?>
        </p>
        <form method="post" action="<?= esc_url(admin_url('settings/search')) ?>">
            <?= Csrf::field('search_rebuild_vocabulary') ?>
            <input type="hidden" name="form" value="search_rebuild_vocabulary">
            <button type="submit" class="lp-button lp-button--secondary">Rebuild Word List</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($searchStatistics !== null): ?>
    <section class="lp-admin__panel">
        <h2>Search Statistics</h2>
        <?php if (!$searchStatistics->isEnabled()): ?>
            <p class="lp-admin__widget-placeholder">Search statistics are turned off. Existing statistics are kept until you clear them.</p>
        <?php endif; ?>

        <?php if ($statisticsTotals['queries'] === 0): ?>
            <p class="lp-admin__widget-placeholder">No searches recorded yet.</p>
        <?php else: ?>
            <p><?= esc_html(number_format($statisticsTotals['searches'])) ?> <?= $statisticsTotals['searches'] === 1 ? 'search' : 'searches' ?> for <?= esc_html(number_format($statisticsTotals['queries'])) ?> different <?= $statisticsTotals['queries'] === 1 ? 'term' : 'terms' ?><?= $statisticsTotals['since'] !== null ? ' since ' . esc_html(the_date($statisticsTotals['since'])) : '' ?>.</p>

            <?php foreach ([
                'Most searched' => $popularSearches,
                'Searches that found nothing' => $unansweredSearches,
            ] as $statisticsHeading => $statisticsRows): ?>
                <h3><?= esc_html($statisticsHeading) ?></h3>
                <?php if ($statisticsRows === []): ?>
                    <p class="lp-admin__widget-placeholder">None.</p>
                <?php else: ?>
                    <table class="lp-table">
                        <thead>
                            <tr>
                                <th scope="col">Search</th>
                                <th scope="col">Times searched</th>
                                <th scope="col">Results last time</th>
                                <th scope="col">Last searched</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statisticsRows as $statisticsRow): ?>
                                <tr>
                                    <td><a href="<?= esc_url(site_url('search') . '?' . http_build_query(['q' => $statisticsRow['query']])) ?>" target="_blank" rel="noopener"><?= esc_html($statisticsRow['query']) ?></a></td>
                                    <td><?= esc_html(number_format($statisticsRow['searches'])) ?></td>
                                    <td><?= esc_html(number_format($statisticsRow['lastResultCount'])) ?></td>
                                    <td><?= esc_html(the_date($statisticsRow['lastSearchedAt'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>

            <form method="post" action="<?= esc_url(admin_url('settings/search')) ?>" data-lp-confirm="Delete all search statistics? This cannot be undone.">
                <?= Csrf::field('search_clear_statistics') ?>
                <input type="hidden" name="form" value="search_clear_statistics">
                <button type="submit" class="lp-button lp-button--danger">Clear Statistics</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
