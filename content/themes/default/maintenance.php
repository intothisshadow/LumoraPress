<?php
/** @var string $page_title */
/** @var string $message */
/** @var \DateTimeImmutable|null $return_at */
get_header();
?>
<div id="lp-content" class="lp-content lp-layout">
    <main class="lp-main">
        <h1 class="lp-page-title"><?= esc_html($page_title) ?></h1>
        <p class="lp-maintenance__message"><?= esc_html($message) ?></p>

        <?php if ($return_at !== null): ?>
            <p class="lp-maintenance__return-at">
                We expect to be back around <?= esc_html($return_at->format('F j, Y g:i A')) ?>.
            </p>
        <?php endif; ?>
    </main>
</div>
<?php get_footer(); ?>
