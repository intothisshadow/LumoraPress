<?php
/** @var string $query */
get_header();
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title">Search Results<?= $query !== '' ? ' for “' . esc_html($query) . '”' : '' ?></h1>
        <p class="lp-empty-state">No results found.</p>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
