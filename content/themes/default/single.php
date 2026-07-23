<?php
/** @var \LumoraPress\Models\Post $post */
get_header();
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <article class="lp-post">
            <h1 class="lp-post__title"><?= esc_html($post->title) ?></h1>
            <p class="lp-post__meta">
                <?= $post->publishedAt !== null ? esc_html($post->publishedAt->format('F j, Y')) : '' ?>
            </p>
            <div class="lp-post__content"><?= nl2br(esc_html($post->content)) ?></div>
        </article>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
