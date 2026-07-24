<?php
/** @var string $query */
/** @var array<int, \LumoraPress\Models\SearchResult> $results */
/** @var array{page: int, totalPages: int}|null $pagination */
get_header();
$results ??= [];
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title">Search Results<?= $query !== '' ? ' for “' . esc_html($query) . '”' : '' ?></h1>

        <?php if ($results === []): ?>
            <p class="lp-empty-state">No results found.</p>
        <?php else: ?>
            <div class="lp-search-results">
                <?php foreach ($results as $result): ?>
                    <article class="lp-search-results__item">
                        <p class="lp-search-results__type"><?= esc_html($result->type === 'page' ? 'Page' : 'Post') ?></p>
                        <h2 class="lp-search-results__title">
                            <a href="<?= esc_url(site_url(($result->type === 'page' ? 'page/' : 'post/') . $result->slug)) ?>">
                                <?= highlight_terms(esc_html($result->title), $query) ?>
                            </a>
                        </h2>
                        <?php if ($result->publishedAt !== null): ?>
                            <p class="lp-search-results__meta"><?= esc_html($result->publishedAt->format('F j, Y')) ?></p>
                        <?php endif; ?>
                        <?php if ($result->excerpt !== ''): ?>
                            <p class="lp-search-results__excerpt"><?= highlight_terms(esc_html($result->excerpt), $query) ?></p>
                        <?php endif; ?>
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
