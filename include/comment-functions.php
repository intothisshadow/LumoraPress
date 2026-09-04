<?php

/**
 * Comment theme API: comment_avatar_url(), comment_form(), and comment_list() — the CSRF/anti-spam-sensitive rendering every theme's comments.php partial used to reimplement for itself.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use LumoraPress\Models\User;

/**
 * Comment theme API, mirroring classic WordPress's comment_form()/
 * wp_list_comments() split. Centralizing this here means a theme's
 * comments.php only owns its wrapper markup and calls these two
 * functions for the security-sensitive parts.
 */

if (!function_exists('comment_avatar_url')) {
    /**
     * A Gravatar URL honoring Settings > Discussion's rating/default — a
     * plain pure function (no config access of its own), so callers pass
     * the resolved rating/default in rather than this reaching into
     * PressConfig itself.
     */
    function comment_avatar_url(string $email, string $rating = 'g', string $default = 'mp', int $size = 48): string
    {
        $hash = hash('sha256', strtolower(trim($email)));

        return 'https://www.gravatar.com/avatar/' . $hash . '?s=' . $size . '&r=' . rawurlencode($rating) . '&d=' . rawurlencode($default);
    }
}

if (!function_exists('comments_link')) {
    /**
     * The classic "X Comments" link for a post listing card, linking
     * through to the post's own comment thread. Renders plain closed
     * text with no link only when there's nothing to see and no way to
     * add to it (closed and zero comments) — a closed thread that
     * already has comments still links through so they remain readable.
     */
    function comments_link(Post $post, int $commentCount): void
    {
        if ($commentCount === 0 && !$post->commentsOpen) {
            echo '<span class="lp-post-list__comments lp-post-list__comments--closed">Comments Closed</span>';

            return;
        }

        $label = match ($commentCount) {
            0 => 'No Comments',
            1 => '1 Comment',
            default => $commentCount . ' Comments',
        };
        ?>
        <a class="lp-post-list__comments" href="<?= esc_url(post_permalink($post) . '#comments') ?>"><?= esc_html($label) ?></a>
        <?php
    }
}

if (!function_exists('comment_form')) {
    /**
     * Renders one comment submission form — the top-level "Leave a
     * Comment" form when $parentId is null, or an inline reply form when
     * it isn't (comment_list() calls this once per node in the tree).
     *
     * Each form needs its own CSRF action name — Csrf::field() overwrites
     * the session's token per action name, so forms sharing one name would
     * invalidate each other. Scoped per post/page id and parent id; pages
     * get a 'page_' prefix since a post and page can share a numeric id.
     *
     * @param array{cookieConsent?: bool, savedName?: string, savedEmail?: string, savedUrl?: string, nameRequired?: bool, emailRequired?: bool} $guestFieldOptions
     */
    function comment_form(Post|Page $content, ?User $currentUser, array $guestFieldOptions = [], ?int $parentId = null, string $submitLabel = 'Post Comment'): void
    {
        $guestFieldOptions += [
            'cookieConsent' => false,
            'savedName' => '',
            'savedEmail' => '',
            'savedUrl' => '',
            'nameRequired' => true,
            'emailRequired' => true,
        ];

        $isPage = $content instanceof Page;
        $formId = $parentId === null ? 'comment-form' : 'comment-form-reply-' . $parentId;
        $csrfAction = ($isPage ? 'comment_submit_page_' : 'comment_submit_') . $content->id . '_' . ($parentId ?? 'root');
        $permalink = $isPage ? page_permalink($content) : post_permalink($content);
        ?>
        <form id="<?= esc_attr($formId) ?>" class="lp-comment-form" method="post" action="<?= esc_url($permalink . '/comment') ?>">
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

if (!function_exists('comment_list')) {
    /**
     * Renders a nested comment thread. $maxDepth caps visual indentation
     * only, not the data — replies past the cap still render, flattened
     * as siblings at the deepest depth, matching classic WordPress.
     *
     * @param array<int, array{comment: \LumoraPress\Models\Comment, children: array<mixed>}> $tree
     * @param array{cookieConsent?: bool, savedName?: string, savedEmail?: string, savedUrl?: string, nameRequired?: bool, emailRequired?: bool} $guestFieldOptions
     */
    function comment_list(
        array $tree,
        Post|Page $content,
        ?User $currentUser,
        array $guestFieldOptions = [],
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
                            <img class="lp-comment__avatar" src="<?= esc_url(comment_avatar_url($comment->guestEmail, $avatarRating, $avatarDefault)) ?>" alt="" width="48" height="48" loading="lazy">
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
                            <?php comment_form($content, $currentUser, $guestFieldOptions, $comment->id, 'Post Reply'); ?>
                        </details>
                    </article>
                    <?php
                    $nextDepth = $depth < $maxDepth ? $depth + 1 : $depth;
                    comment_list($node['children'], $content, $currentUser, $guestFieldOptions, $nextDepth, $maxDepth, $avatarsEnabled, $avatarRating, $avatarDefault);
                    ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }
}
