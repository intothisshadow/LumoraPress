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
 */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;

if (!function_exists('lp_render_comment_form')) {
    function lp_render_comment_form(
        \LumoraPress\Models\Post $post,
        ?\LumoraPress\Models\User $currentUser,
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
                    <input type="text" id="<?= esc_attr($formId) ?>-name" name="guest_name" required>
                </p>

                <p class="lp-field">
                    <label for="<?= esc_attr($formId) ?>-email">Email <span class="lp-field__hint">(will not be published)</span></label>
                    <input type="email" id="<?= esc_attr($formId) ?>-email" name="guest_email" required>
                </p>

                <p class="lp-field">
                    <label for="<?= esc_attr($formId) ?>-url">Website <span class="lp-field__hint">(optional)</span></label>
                    <input type="url" id="<?= esc_attr($formId) ?>-url" name="guest_url">
                </p>
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
     */
    function lp_render_comment_thread(array $tree, \LumoraPress\Models\Post $post, ?\LumoraPress\Models\User $currentUser, int $depth = 0): void
    {
        if ($tree === []) {
            return;
        }
        ?>
        <ol class="lp-comment-list<?= $depth > 0 ? ' lp-comment-list--replies' : '' ?>">
            <?php foreach ($tree as $node): ?>
                <?php $comment = $node['comment']; ?>
                <li id="comment-<?= (int) $comment->id ?>" class="lp-comment">
                    <article class="lp-comment__body">
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
                            <?php lp_render_comment_form($post, $currentUser, $comment->id, 'Post Reply'); ?>
                        </details>
                    </article>
                    <?php lp_render_comment_thread($node['children'], $post, $currentUser, $depth + 1); ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }
}

$flag = is_string($_GET['comment'] ?? null) ? $_GET['comment'] : null;
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
    <?php elseif ($flag === 'error'): ?>
        <div class="lp-alert lp-alert--error">Please fill in your name, a valid email address, and a comment.</div>
    <?php endif; ?>

    <?php lp_render_comment_thread($comment_tree, $post, $current_user); ?>

    <?php if ($comments_open): ?>
        <h3 class="lp-comments__form-title">Leave a Comment</h3>
        <?php lp_render_comment_form($post, $current_user); ?>
    <?php else: ?>
        <p class="lp-comments__closed">Comments are closed.</p>
    <?php endif; ?>
</section>
