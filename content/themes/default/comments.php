<?php

/**
 * Default theme partial rendering a post's or page's comment thread and comment form.
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
/**
 * @var \LumoraPress\Models\Post|null $post Set by single.php; exactly one of $post/$page is ever set.
 * @var \LumoraPress\Models\Page|null $page Set by page.php; exactly one of $post/$page is ever set.
 * @var array<int, array{comment: \LumoraPress\Models\Comment, children: array<mixed>}> $comment_tree
 * @var bool $comments_open
 * @var int $comment_count
 * @var \LumoraPress\Models\User|null $current_user
 * @var array{total: int, page: int, perPage: int, totalPages: int}|null $comment_pagination
 * @var bool $comment_pagination_enabled
 * @var int $comment_max_nesting_level
 * @var bool $avatars_enabled
 * @var string $avatar_rating
 * @var string $avatar_default
 * @var bool $comment_cookies_consent_enabled
 * @var string $comment_saved_guest_name
 * @var string $comment_saved_guest_email
 * @var string $comment_saved_guest_url
 * @var bool $comment_author_name_required
 * @var bool $comment_author_email_required
 */

// Exactly one of $post/$page is ever passed in (see the docblock above) —
// $content is whichever one it was, so the rest of this partial (and the
// comment_list()/comment_form() calls it makes) can stay type-agnostic.
$content = $post ?? $page;
$flag = is_string($_GET['comment'] ?? null) ? $_GET['comment'] : null;
$avatarsEnabled = $avatars_enabled ?? true;
$avatarRating = strtolower($avatar_rating ?? 'g');
$avatarDefault = $avatar_default ?? 'mp';
$maxNesting = $comment_max_nesting_level ?? 5;

$guestFieldOptions = [
    'cookieConsent' => $comment_cookies_consent_enabled ?? false,
    'savedName' => $comment_saved_guest_name ?? '',
    'savedEmail' => $comment_saved_guest_email ?? '',
    'savedUrl' => $comment_saved_guest_url ?? '',
    'nameRequired' => $comment_author_name_required ?? true,
    'emailRequired' => $comment_author_email_required ?? true,
];
?>
<section id="comments" class="lp-comments">
    <h2 class="lp-comments__title">
        <?= (int) $comment_count === 1 ? '1 Comment' : esc_html((string) $comment_count) . ' Comments' ?>
    </h2>

    <?php if ($flag === 'posted'): ?>
        <div class="lp-alert lp-alert--success">Your comment has been posted.</div>
    <?php elseif ($flag === 'pending'): ?>
        <div class="lp-alert lp-alert--success">Your comment has been submitted and is awaiting moderation.</div>
    <?php elseif ($flag === 'flood'): ?>
        <div class="lp-alert lp-alert--error">You're commenting too quickly. Please wait a moment and try again.</div>
    <?php elseif ($flag === 'login_required'): ?>
        <div class="lp-alert lp-alert--error">You must be logged in to post a comment.</div>
    <?php elseif ($flag === 'error'): ?>
        <div class="lp-alert lp-alert--error">Please fill in your name, a valid email address, and a comment.</div>
    <?php endif; ?>

    <?php comment_list($comment_tree, $content, $current_user, $guestFieldOptions, 0, $maxNesting, $avatarsEnabled, $avatarRating, $avatarDefault); ?>

    <?php
    $contentPermalink = isset($page) ? page_permalink($content) : post_permalink($content);
    ?>
    <?php if (($comment_pagination_enabled ?? false) && ($comment_pagination['totalPages'] ?? 1) > 1): ?>
        <nav class="lp-comments__pagination" aria-label="Comments pagination">
            <?php if ($comment_pagination['page'] > 1): ?>
                <a class="lp-button lp-button--secondary" href="<?= esc_url($contentPermalink . '?cpage=' . ($comment_pagination['page'] - 1) . '#comments') ?>">&larr; Newer Comments</a>
            <?php endif; ?>
            <span class="lp-comments__pagination-status">Page <?= (int) $comment_pagination['page'] ?> of <?= (int) $comment_pagination['totalPages'] ?></span>
            <?php if ($comment_pagination['page'] < $comment_pagination['totalPages']): ?>
                <a class="lp-button lp-button--secondary" href="<?= esc_url($contentPermalink . '?cpage=' . ($comment_pagination['page'] + 1) . '#comments') ?>">Older Comments &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <?php if ($comments_open): ?>
        <h3 class="lp-comments__form-title">Leave a Comment</h3>
        <?php comment_form($content, $current_user, $guestFieldOptions); ?>
    <?php else: ?>
        <p class="lp-comments__closed">Comments are closed.</p>
    <?php endif; ?>
</section>
