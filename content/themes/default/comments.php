<?php

/**
 * Default theme partial rendering a post's comment thread and comment form.
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
 * @var \LumoraPress\Models\Post $post
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

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;

if (!function_exists('lp_comment_avatar_url')) {
    /**
     * A Gravatar URL honoring Settings > Discussion's rating/default
     * (LP-047) — a plain pure function (no config access of its own) so
     * this partial stays self-contained; SiteController resolves
     * $avatar_rating/$avatar_default from PressConfig before rendering.
     */
    function lp_comment_avatar_url(string $email, string $rating, string $default, int $size = 48): string
    {
        $hash = hash('sha256', strtolower(trim($email)));

        return 'https://www.gravatar.com/avatar/' . $hash . '?s=' . $size . '&r=' . rawurlencode($rating) . '&d=' . rawurlencode($default);
    }
}

if (!function_exists('lp_render_comment_form')) {
    /**
     * @param array{cookieConsent: bool, savedName: string, savedEmail: string, savedUrl: string, nameRequired: bool, emailRequired: bool} $guestFieldOptions
     */
    function lp_render_comment_form(
        \LumoraPress\Models\Post $post,
        ?\LumoraPress\Models\User $currentUser,
        array $guestFieldOptions,
        ?int $parentId = null,
        string $submitLabel = 'Post Comment',
    ): void {
        $formId = $parentId === null ? 'comment-form' : 'comment-form-reply-' . $parentId;
        // Each form on the page (the top-level form plus one per reply)
        // needs its own CSRF action name — Csrf::token() overwrites the
        // previous token for a given action, so reusing one name across
        // multiple forms on the same page would leave every form but the
        // last-rendered one with a stale, already-invalid token.
        $csrfAction = 'comment_submit_' . $post->id . '_' . ($parentId ?? 'root');
        ?>
        <form id="<?= esc_attr($formId) ?>" class="lp-comment-form" method="post" action="<?= esc_url(home_url('post/' . $post->slug . '/comment')) ?>">
            <?= Csrf::field($csrfAction) ?>
            <input type="hidden" name="parent_id" value="<?= (int) ($parentId ?? 0) ?>">

            <p class="lp-comment-form__honeypot" aria-hidden="true">
                <label for="<?= esc_attr($formId) ?>-trap">Leave this field blank</label>
                <input type="text" id="<?= esc_attr($formId) ?>-trap" name="comment_website" tabindex="-1" autocomplete="off">
            </p>
            <?= FormTiming::field() ?>

            <?php if ($currentUser !== null): ?>
                <p class="lp-field__hint">Commenting as <?= esc_html($currentUser->displayName) ?>.</p>
            <?php else: ?>
                <p class="lp-field">
                    <label for="<?= esc_attr($formId) ?>-name">Name</label>
                    <input type="text" id="<?= esc_attr($formId) ?>-name" name="guest_name" value="<?= esc_attr($guestFieldOptions['savedName']) ?>" <?= $guestFieldOptions['nameRequired'] ? 'required' : '' ?>>
                </p>

                <p class="lp-field">
                    <label for="<?= esc_attr($formId) ?>-email">Email <span class="lp-field__hint">(will not be published)</span></label>
                    <input type="email" id="<?= esc_attr($formId) ?>-email" name="guest_email" value="<?= esc_attr($guestFieldOptions['savedEmail']) ?>" <?= $guestFieldOptions['emailRequired'] ? 'required' : '' ?>>
                </p>

                <p class="lp-field">
                    <label for="<?= esc_attr($formId) ?>-url">Website <span class="lp-field__hint">(optional)</span></label>
                    <input type="url" id="<?= esc_attr($formId) ?>-url" name="guest_url" value="<?= esc_attr($guestFieldOptions['savedUrl']) ?>">
                </p>

                <?php if ($guestFieldOptions['cookieConsent']): ?>
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="comment_save_info" value="1" checked>
                        Save my name and email in this browser for the next time I comment.
                    </label>
                <?php endif; ?>
            <?php endif; ?>

            <p class="lp-field">
                <label for="<?= esc_attr($formId) ?>-content">Comment</label>
                <textarea id="<?= esc_attr($formId) ?>-content" name="content" rows="5" required></textarea>
            </p>

            <button type="submit" class="lp-button lp-button--primary"><?= esc_html($submitLabel) ?></button>
        </form>
        <?php
    }
}

if (!function_exists('lp_render_comment_thread')) {
    /**
     * @param array<int, array{comment: \LumoraPress\Models\Comment, children: array<mixed>}> $tree
     * @param array{cookieConsent: bool, savedName: string, savedEmail: string, savedUrl: string, nameRequired: bool, emailRequired: bool} $guestFieldOptions
     */
    function lp_render_comment_thread(
        array $tree,
        \LumoraPress\Models\Post $post,
        ?\LumoraPress\Models\User $currentUser,
        array $guestFieldOptions,
        int $depth = 0,
        int $maxDepth = 5,
        bool $avatarsEnabled = true,
        string $avatarRating = 'g',
        string $avatarDefault = 'mp',
    ): void {
        if ($tree === []) {
            return;
        }
        ?>
        <ol class="lp-comment-list<?= $depth > 0 ? ' lp-comment-list--replies' : '' ?>">
            <?php foreach ($tree as $node): ?>
                <?php $comment = $node['comment']; ?>
                <li id="comment-<?= (int) $comment->id ?>" class="lp-comment">
                    <article class="lp-comment__body">
                        <?php if ($avatarsEnabled): ?>
                            <img class="lp-comment__avatar" src="<?= esc_url(lp_comment_avatar_url($comment->guestEmail, $avatarRating, $avatarDefault)) ?>" alt="" width="48" height="48" loading="lazy">
                        <?php endif; ?>
                        <p class="lp-comment__meta">
                            <span class="lp-comment__author"><?= $comment->guestUrl !== null
                                ? '<a href="' . esc_url($comment->guestUrl) . '" rel="nofollow ugc noopener" target="_blank">' . esc_html($comment->guestName) . '</a>'
                                : esc_html($comment->guestName) ?></span>
                            <time class="lp-comment__date" datetime="<?= esc_attr($comment->createdAt->format(DATE_ATOM)) ?>">
                                <?= esc_html(the_date($comment->createdAt)) ?> at <?= esc_html(the_time($comment->createdAt)) ?>
                            </time>
                        </p>
                        <div class="lp-comment__content"><?= format_comment_content($comment->content) ?></div>
                        <details class="lp-comment__reply">
                            <summary>Reply</summary>
                            <?php lp_render_comment_form($post, $currentUser, $guestFieldOptions, $comment->id, 'Post Reply'); ?>
                        </details>
                    </article>
                    <?php
                    // "Maximum nesting level" (LP-047) caps visual
                    // indentation, not the data — replies past the cap
                    // still render (nothing is dropped), just as siblings
                    // at the deepest allowed depth rather than nesting
                    // further, matching classic WordPress's own behavior.
                    $nextDepth = $depth < $maxDepth ? $depth + 1 : $depth;
                    lp_render_comment_thread($node['children'], $post, $currentUser, $guestFieldOptions, $nextDepth, $maxDepth, $avatarsEnabled, $avatarRating, $avatarDefault);
                    ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }
}

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

    <?php lp_render_comment_thread($comment_tree, $post, $current_user, $guestFieldOptions, 0, $maxNesting, $avatarsEnabled, $avatarRating, $avatarDefault); ?>

    <?php if (($comment_pagination_enabled ?? false) && ($comment_pagination['totalPages'] ?? 1) > 1): ?>
        <nav class="lp-comments__pagination" aria-label="Comments pagination">
            <?php if ($comment_pagination['page'] > 1): ?>
                <a class="lp-button lp-button--secondary" href="<?= esc_url(home_url('post/' . $post->slug) . '?cpage=' . ($comment_pagination['page'] - 1) . '#comments') ?>">&larr; Newer Comments</a>
            <?php endif; ?>
            <span class="lp-comments__pagination-status">Page <?= (int) $comment_pagination['page'] ?> of <?= (int) $comment_pagination['totalPages'] ?></span>
            <?php if ($comment_pagination['page'] < $comment_pagination['totalPages']): ?>
                <a class="lp-button lp-button--secondary" href="<?= esc_url(home_url('post/' . $post->slug) . '?cpage=' . ($comment_pagination['page'] + 1) . '#comments') ?>">Older Comments &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <?php if ($comments_open): ?>
        <h3 class="lp-comments__form-title">Leave a Comment</h3>
        <?php lp_render_comment_form($post, $current_user, $guestFieldOptions); ?>
    <?php else: ?>
        <p class="lp-comments__closed">Comments are closed.</p>
    <?php endif; ?>
</section>
