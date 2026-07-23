<?php
/** @var array<int, \LumoraPress\Models\Post> $posts */
/** @var array{page: int, totalPages: int} $pagination */
get_header();
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title">Welcome to Lumora Press</h1>

        <?php if ($posts === []): ?>
            <p class="lp-empty-state">No posts have been published yet.</p>
        <?php else: ?>
            <div class="lp-post-list">
                <?php foreach ($posts as $post): ?>
                    <article class="lp-post-list__item">
                        <h2 class="lp-post-list__title">
                            <a href="<?= esc_url(site_url('post/' . $post->slug)) ?>"><?= esc_html($post->title) ?></a>
                        </h2>
                        <p class="lp-post-list__meta">
                            <?= $post->publishedAt !== null ? esc_html($post->publishedAt->format('F j, Y')) : '' ?>
                        </p>
                        <?php if ($post->excerpt !== ''): ?>
                            <p class="lp-post-list__excerpt"><?= esc_html($post->excerpt) ?></p>
                        <?php endif; ?>
                        <a class="lp-post-list__more" href="<?= esc_url(site_url('post/' . $post->slug)) ?>">Continue reading &rarr;</a>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php render_pagination($pagination); ?>
        <?php endif; ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
