<?php
/** @var \LumoraPress\Models\Page $page */
get_header();
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <article class="lp-page">
            <h1 class="lp-page-title"><?= esc_html($page->title) ?></h1>
            <div class="lp-post__content"><?= nl2br(esc_html($page->content)) ?></div>
        </article>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
