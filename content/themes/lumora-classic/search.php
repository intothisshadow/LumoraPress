<?php

/**
 * Default theme template for search results.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var string $query */
/** @var array<int, \LumoraPress\Models\SearchResult> $results */
/** @var array{page: int, totalPages: int}|null $pagination */
/** @var array<string, mixed> $search */
get_header();
$results ??= [];
$search ??= [];
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title">Search Results<?= $query !== '' ? ' for “' . esc_html($query) . '”' : '' ?></h1>

        <?php search_filters_form($search); ?>

        <?php if ($results === []): ?>
            <?php search_empty_state($search); ?>
        <?php else: ?>
            <div class="lp-search-results lp-gallery">
                <?php foreach ($results as $result): ?>
                    <article class="lp-search-results__item">
                        <?php if (has_post_thumbnail($result)): ?>
                            <div class="lp-search-results__thumbnail">
                                <?php the_post_thumbnail_lightbox($result, 'small'); ?>
                            </div>
                        <?php endif; ?>
                        <div class="lp-search-results__body">
                            <p class="lp-search-results__type"><?= esc_html(search_result_type_label($result)) ?></p>
                            <h2 class="lp-search-results__title">
                                <a href="<?= esc_url(search_result_permalink($result)) ?>">
                                    <?= highlight_terms(esc_html($result->title), $query) ?>
                                </a>
                            </h2>
                            <?php if ($result->publishedAt !== null): ?>
                                <p class="lp-search-results__meta"><?= esc_html(the_date($result->publishedAt)) ?></p>
                            <?php endif; ?>
                            <?php if ($result->excerpt !== ''): ?>
                                <p class="lp-search-results__excerpt"><?= highlight_terms(esc_html($result->excerpt), $query) ?></p>
                            <?php endif; ?>
                            <?php if ($result->type === 'post' && post_has_tags($result->id)): ?>
                                <ul class="lp-search-results__tags">
                                    <?php foreach (get_the_tags($result->id) as $resultTag): ?>
                                        <li class="lp-search-results__tags-item"><a href="<?= esc_url(tag_permalink($resultTag)) ?>"><?= esc_html($resultTag->name) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if (isset($pagination)) {
                render_pagination($pagination, 'Search results pagination');
            } ?>
        <?php endif; ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
