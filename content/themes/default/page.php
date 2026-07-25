<?php
/** @var \LumoraPress\Models\Page $page */
get_header(['page' => $page]);
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <article class="lp-page">
            <h1 class="lp-page-title"><?= esc_html($page->title) ?></h1>
            <?php if (has_post_thumbnail($page)): ?>
                <div class="lp-post__thumbnail lp-gallery">
                    <?php the_post_thumbnail_lightbox($page, size: 'large', largeSize: 'large'); ?>
                </div>
            <?php endif; ?>
            <div class="lp-post__content"><?= render_content($page->content, $page->contentFormat) ?></div>
        </article>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
