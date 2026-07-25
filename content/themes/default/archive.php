<?php
/** @var string|null $page_title */
/** @var array<int, \LumoraPress\Models\Post> $posts */
/** @var array{page: int, totalPages: int}|null $pagination */
/** @var string|null $archive_description */
get_header();
$posts ??= [];
$archive_description ??= null;
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title"><?= esc_html($page_title ?? 'Archive') ?></h1>

        <?php if ($archive_description !== null && $archive_description !== ''): ?>
            <p class="lp-archive__description"><?= esc_html($archive_description) ?></p>
        <?php endif; ?>

        <?php if ($posts === []): ?>
            <p class="lp-empty-state">No content found in this archive yet.</p>
        <?php else: ?>
            <div class="lp-post-list lp-gallery">
                <?php foreach ($posts as $post): ?>
                    <article class="lp-post-list__item">
                        <?php if (has_post_thumbnail($post)): ?>
                            <div class="lp-post-list__thumbnail">
                                <?php the_post_thumbnail_lightbox($post, 'small'); ?>
                            </div>
                        <?php endif; ?>
                        <div class="lp-post-list__body">
                            <h2 class="lp-post-list__title">
                                <a href="<?= esc_url(site_url('post/' . $post->slug)) ?>"><?= esc_html($post->title) ?></a>
                            </h2>
                            <p class="lp-post-list__meta">
                                <?= $post->publishedAt !== null ? esc_html($post->publishedAt->format('F j, Y')) : '' ?>
                            </p>
                            <?php if ($post->excerpt !== ''): ?>
                                <p class="lp-post-list__excerpt"><?= esc_html($post->excerpt) ?></p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if (isset($pagination)) {
                render_pagination($pagination);
            } ?>
        <?php endif; ?>
    </main>
    <?php get_sidebar(); ?>
</div>
<?php get_footer(); ?>
