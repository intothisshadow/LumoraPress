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
use LumoraPress\Services\Search\SearchIndex;
use LumoraPress\Services\Search\SearchResultCache;
use LumoraPress\Services\Search\SearchStatistics;
use LumoraPress\Services\SearchService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$searchVocabulary = $kernel->search->vocabulary();
$searchStatistics = $kernel->search->statistics();
$searchIndex = $kernel->search->index();
$searchResultCache = $kernel->search->resultCache();

// search-index.js runs the rebuild one step per request and asks for JSON;
// without it the same form falls back to running every step in one request.
$isAjaxIndexRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

$respondIndexJson = static function (array $payload, int $status = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
};

if ($form === 'search_index_rebuild' && $searchIndex !== null) {
    $indexToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $indexStage = is_string($_POST['stage'] ?? null) ? $_POST['stage'] : SearchIndex::STAGES[0];

    if (!Csrf::verify('search_index_rebuild', $indexToken)) {
        if ($isAjaxIndexRequest) {
            $respondIndexJson(['error' => 'Your session expired. Reload the page and try again.'], 403);
        }
    } elseif ($isAjaxIndexRequest) {
        $stageResult = $searchIndex->runStage($indexStage);
        $stagePosition = (int) array_search($indexStage, SearchIndex::STAGES, true);

        $respondIndexJson([
            'done' => $stageResult['next'] === null && $stageResult['error'] === null,
            'error' => $stageResult['error'],
            'next' => $stageResult['next'],
            'label' => $stageResult['next'] !== null ? SearchIndex::STAGE_LABELS[$stageResult['next']] : '',
            'percent' => (int) round(($stagePosition + 1) / count(SearchIndex::STAGES) * 100),
            'csrf_token' => Csrf::token('search_index_rebuild'),
            'redirect' => admin_url('settings/search') . '?indexed=1',
        ]);
    } else {
        header('Location: ' . admin_url('settings/search') . '?' . ($searchIndex->rebuildAll() ? 'indexed=1' : 'indexfailed=1'));
        exit;
    }
}

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
        SearchResultCache::ENABLED_OPTION,
    ] as $toggleOption) {
        $kernel->config->setOption($toggleOption, ($_POST[$toggleOption] ?? '') === '1' ? '1' : '0');
    }

    $kernel->config->setOption(SearchResultCache::LIFETIME_OPTION, (string) (max(1, min(1440, (int) ($_POST['search_cache_minutes'] ?? 5))) * 60));

    $taxonomyWeight = (int) ($_POST[SearchService::TAXONOMY_WEIGHT_OPTION] ?? SearchService::DEFAULT_TAXONOMY_WEIGHT);
    $kernel->config->setOption(SearchService::TAXONOMY_WEIGHT_OPTION, (string) (array_key_exists($taxonomyWeight, SearchService::TAXONOMY_WEIGHT_LABELS) ? $taxonomyWeight : SearchService::DEFAULT_TAXONOMY_WEIGHT));

    $indexSchedule = is_string($_POST[SearchIndex::SCHEDULE_OPTION] ?? null) ? $_POST[SearchIndex::SCHEDULE_OPTION] : 'off';
    $kernel->config->setOption(SearchIndex::SCHEDULE_OPTION, array_key_exists($indexSchedule, SearchIndex::SCHEDULES) ? $indexSchedule : 'off');

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
} elseif ($form === 'search_clear_cache' && $searchResultCache !== null && Csrf::verify('search_clear_cache', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $searchResultCache->clear();

    header('Location: ' . admin_url('settings/search') . '?cachecleared=1');
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
<?php elseif (isset($_GET['indexed'])): ?>
    <div class="lp-alert lp-alert--success">Search index rebuilt.</div>
<?php elseif (isset($_GET['indexfailed'])): ?>
    <div class="lp-alert lp-alert--error">The search index could not be rebuilt. Details were written to the server error log.</div>
<?php elseif (isset($_GET['cachecleared'])): ?>
    <div class="lp-alert lp-alert--success">Saved search results cleared.</div>
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

        <p class="lp-field">
            <label for="search-taxonomy-weight">Boost posts whose tags or categories match</label>
            <select id="search-taxonomy-weight" name="<?= esc_attr(SearchService::TAXONOMY_WEIGHT_OPTION) ?>">
                <?php foreach (SearchService::TAXONOMY_WEIGHT_LABELS as $weightValue => $weightLabel): ?>
                    <option value="<?= (int) $weightValue ?>" <?= $kernel->search->taxonomyWeight() === $weightValue ? 'selected' : '' ?>><?= esc_html($weightLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="lp-field__hint">A post ranks higher when a tag or category on it starts with a word searched for. Title matches already count double; this only re-orders posts the search found, and never adds posts on its own.</span>
        </p>

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

        <?php if ($searchResultCache !== null && $searchIndex !== null): ?>
            <fieldset class="lp-field lp-field--checklist">
                <legend>Performance</legend>

                <label class="lp-field--checkbox">
                    <input type="checkbox" name="<?= esc_attr(SearchResultCache::ENABLED_OPTION) ?>" value="1" <?= $searchResultCache->isEnabled() ? 'checked' : '' ?>>
                    Keep recent search results so repeated searches are answered without the database
                </label>

                <span class="lp-field">
                    <label for="search-cache-minutes">Keep them for (minutes)</label>
                    <input type="number" id="search-cache-minutes" name="search_cache_minutes" min="1" max="1440" value="<?= (int) round($searchResultCache->lifetime() / 60) ?>">
                </span>

                <span class="lp-field__hint">Saved results are cleared whenever you publish, edit, or delete a post, page, category, or tag, or change a search setting. A post scheduled to appear or disappear can take up to this long to show up in search.</span>

                <span class="lp-field">
                    <label for="search-index-schedule">Refresh the search index automatically</label>
                    <select id="search-index-schedule" name="<?= esc_attr(SearchIndex::SCHEDULE_OPTION) ?>">
                        <?php foreach (['off' => 'Never', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $scheduleValue => $scheduleLabel): ?>
                            <option value="<?= esc_attr($scheduleValue) ?>" <?= $searchIndex->schedule() === $scheduleValue ? 'selected' : '' ?>><?= esc_html($scheduleLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>

                <span class="lp-field__hint">
                    <?php if (SearchIndex::backgroundRunSupported()): ?>
                        Runs the same steps as Rebuild Search Index below, in the background right after a visitor's page has been sent, so nobody waits for it.
                    <?php else: ?>
                        This server cannot run work after a page has been sent (it needs PHP-FPM or LiteSpeed), so the index is never refreshed on its own here. Use Rebuild Search Index below instead.
                    <?php endif; ?>
                </span>
            </fieldset>
        <?php endif; ?>

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

<?php if ($searchIndex !== null): ?>
    <?php
    $indexReport = $searchIndex->diagnostics();
    $indexLastRun = $searchIndex->lastRun();
    $cacheStats = $searchResultCache?->stats() ?? ['entries' => 0, 'bytes' => 0];
    ?>
    <section class="lp-admin__panel">
        <h2>Search Index</h2>
        <p>Searching posts and pages uses indexes your database keeps up to date every time you save. Rebuilding recreates any that are missing and compacts the ones that deleted content leaves bloated. It also refreshes the search word list, and can take a while on a large site.</p>

        <?php if ($indexReport['problems'] === []): ?>
            <div class="lp-alert lp-alert--success">The search index is healthy.</div>
        <?php else: ?>
            <div class="lp-alert lp-alert--error">
                <p>The search index needs attention:</p>
                <ul>
                    <?php foreach ($indexReport['problems'] as $indexProblem): ?>
                        <li><?= esc_html($indexProblem) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($indexReport['tables'] !== []): ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Content</th>
                        <th scope="col">Rows</th>
                        <th scope="col">Table type</th>
                        <th scope="col">Search index</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($indexReport['tables'] as $indexTable): ?>
                        <?php $indexesWorking = array_reduce($indexTable['indexes'], static fn (bool $carry, array $state): bool => $carry && $state['working'], true); ?>
                        <tr>
                            <th scope="row"><?= esc_html($indexTable['label']) ?></th>
                            <td><?= esc_html(number_format($indexTable['rows'])) ?></td>
                            <td><?= esc_html($indexTable['engine'] !== '' ? $indexTable['engine'] : 'Unknown') ?></td>
                            <td><?= $indexesWorking ? 'Working' : 'Needs attention' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <ul>
            <?php if ($indexReport['minTokenSize'] !== null): ?>
                <li>Words shorter than <?= (int) $indexReport['minTokenSize'] ?> characters aren't indexed by your database, so a search for one only matches longer words that start with it. This is a database setting; your host controls it.</li>
            <?php endif; ?>
            <?php if ($indexReport['stopwords'] === true): ?>
                <li>Very common English words ("the", "and") are ignored by the database and never affect results.</li>
            <?php endif; ?>
            <li>
                <?php if ($indexLastRun === null): ?>
                    The index has not been rebuilt from this screen yet.
                <?php elseif ($indexLastRun['ok']): ?>
                    Last rebuilt <?= esc_html(the_date($indexLastRun['at'])) ?>, with <?= esc_html(number_format($indexLastRun['words'])) ?> words in the word list.
                <?php else: ?>
                    The last rebuild, on <?= esc_html(the_date($indexLastRun['at'])) ?>, did not finish.
                <?php endif; ?>
            </li>
            <li>
                <?php if ($searchResultCache === null || !$searchResultCache->isEnabled()): ?>
                    Saved search results are turned off.
                <?php elseif (!$searchResultCache->isWritable()): ?>
                    Saved search results are on, but the storage folder is not writable, so every search runs live.
                <?php else: ?>
                    <?= esc_html(number_format($cacheStats['entries'])) ?> saved <?= $cacheStats['entries'] === 1 ? 'search' : 'searches' ?> (<?= esc_html(number_format($cacheStats['bytes'] / 1024, 1)) ?> KB).
                <?php endif; ?>
            </li>
        </ul>

        <form method="post" action="<?= esc_url(admin_url('settings/search')) ?>" id="search-index-rebuild">
            <?= Csrf::field('search_index_rebuild') ?>
            <input type="hidden" name="form" value="search_index_rebuild">
            <input type="hidden" name="stage" value="<?= esc_attr(SearchIndex::STAGES[0]) ?>">
            <button type="submit" class="lp-button lp-button--secondary">Rebuild Search Index</button>
        </form>

        <div class="lp-thumbnails__progress" data-lp-search-index-progress-wrap role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" hidden>
            <div class="lp-thumbnails__progress-bar" data-lp-search-index-progress-bar></div>
        </div>
        <p data-lp-search-index-status role="status" aria-live="polite"></p>

        <?php if ($searchResultCache !== null && $cacheStats['entries'] > 0): ?>
            <form method="post" action="<?= esc_url(admin_url('settings/search')) ?>">
                <?= Csrf::field('search_clear_cache') ?>
                <input type="hidden" name="form" value="search_clear_cache">
                <button type="submit" class="lp-button lp-button--secondary">Clear Saved Search Results</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

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
