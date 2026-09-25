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
use LumoraPress\Core\Theme\ActiveAuth;
use LumoraPress\Core\Theme\Authors;
use LumoraPress\Core\Theme\CommentExtras;
use LumoraPress\Core\Security\FormTiming;
use LumoraPress\Models\Comment;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use LumoraPress\Models\User;
use LumoraPress\Services\CommentReportService;

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
     * @param array{cookieConsent?: bool, savedName?: string, savedEmail?: string, savedUrl?: string, nameRequired?: bool, emailRequired?: bool, urlEnabled?: bool} $guestFieldOptions
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
            'urlEnabled' => true,
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

                <?php if ($guestFieldOptions['urlEnabled']): ?>
                    <p class="lp-field">
                        <label for="<?= esc_attr($formId) ?>-url">Website <span class="lp-field__hint">(optional)</span></label>
                        <input type="url" id="<?= esc_attr($formId) ?>-url" name="guest_url" value="<?= esc_attr($guestFieldOptions['savedUrl']) ?>">
                    </p>
                <?php endif; ?>

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
                <?= lp_emoji_picker_button(['target' => '#' . $formId . '-content']) ?>
            </p>

            <?= apply_filters('comment_form_fields_after', '', $content, $currentUser, $parentId, $formId) ?>

            <button type="submit" class="lp-button lp-button--primary"><?= esc_html($submitLabel) ?></button>
        </form>
        <?php
    }
}

if (!function_exists('comment_descendant_count')) {
    /**
     * Total replies anywhere under $children, not just the direct count —
     * comment_list()'s own measure of "how long is this sub-thread" for
     * deciding whether to collapse it.
     *
     * @param array<int, array{comment: \LumoraPress\Models\Comment, children: array<mixed>}> $children
     */
    function comment_descendant_count(array $children): int
    {
        $count = count($children);

        foreach ($children as $child) {
            $count += comment_descendant_count($child['children']);
        }

        return $count;
    }
}

if (!function_exists('comment_list')) {
    /**
     * Renders a nested comment thread. $maxDepth caps visual indentation
     * only, not the data — replies past the cap still render, flattened
     * as siblings at the deepest depth, matching classic WordPress.
     * $collapseThreshold wraps a sub-thread with more than that many total
     * replies in a collapsed-by-default `<details>`, the same pattern the
     * per-comment Reply form below already uses — long-running threads on
     * an old post stay readable without an ever-growing page.
     *
     * @param array<int, array{comment: \LumoraPress\Models\Comment, children: array<mixed>}> $tree
     * @param array{cookieConsent?: bool, savedName?: string, savedEmail?: string, savedUrl?: string, nameRequired?: bool, emailRequired?: bool, urlEnabled?: bool} $guestFieldOptions
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
        int $collapseThreshold = 10,
    ): void {
        if ($tree === []) {
            return;
        }

        if ($depth === 0) {
            CommentExtras::markUsed();
            CommentExtras::prime(comment_tree_ids($tree));
        }
        ?>
        <ol class="lp-comment-list<?= $depth > 0 ? ' lp-comment-list--replies' : '' ?>">
            <?php foreach ($tree as $node): ?>
                <?php $comment = $node['comment']; ?>
                <?php $isByPostAuthor = $comment->userId !== null && $comment->userId === $content->authorId; ?>
                <li id="comment-<?= (int) $comment->id ?>" class="lp-comment<?= $isByPostAuthor ? ' lp-comment--by-post-author' : '' ?>">
                    <article class="lp-comment__body">
                        <?php if ($avatarsEnabled): ?>
                            <img class="lp-comment__avatar" src="<?= esc_url(comment_avatar_url($comment->guestEmail, $avatarRating, $avatarDefault)) ?>" alt="" width="48" height="48" loading="lazy">
                        <?php endif; ?>
                        <p class="lp-comment__meta">
                            <span class="lp-comment__author"><?= $comment->guestUrl !== null
                                ? '<a href="' . esc_url($comment->guestUrl) . '" rel="nofollow ugc noopener" target="_blank">' . esc_html($comment->guestName) . '</a>'
                                : esc_html($comment->guestName) ?></span>
                            <?= comment_author_badge($comment, $content) ?>
                            <time class="lp-comment__date" datetime="<?= esc_attr($comment->createdAt->format(DATE_ATOM)) ?>">
                                <?= esc_html(the_date($comment->createdAt)) ?> at <?= esc_html(the_time($comment->createdAt)) ?>
                            </time>
                            <?php if ($comment->editedAt !== null): ?>
                                <span class="lp-comment__edited" title="<?= esc_attr(sprintf(__('Edited %1$s at %2$s'), the_date($comment->editedAt), the_time($comment->editedAt))) ?>"><?= esc_html(__('(edited)')) ?></span>
                            <?php endif; ?>
                        </p>
                        <div class="lp-comment__content"><?= format_comment_content($comment->content) ?></div>
                        <div class="lp-comment__actions">
                            <?= comment_reaction_buttons($comment) ?>
                            <button type="button" class="lp-comment__quote-button" data-lp-comment-quote data-quote-author="<?= esc_attr($comment->guestName) ?>" data-quote-text="<?= esc_attr(comment_quotable_text($comment->content)) ?>" data-quote-target="comment-form-reply-<?= (int) $comment->id ?>-content" hidden><?= esc_html(__('Quote')) ?></button>
                            <?= comment_report_form($comment) ?>
                        </div>
                        <details class="lp-comment__reply">
                            <summary>Reply</summary>
                            <?php comment_form($content, $currentUser, $guestFieldOptions, $comment->id, 'Post Reply'); ?>
                        </details>
                    </article>
                    <?php
                    $nextDepth = $depth < $maxDepth ? $depth + 1 : $depth;
                    $descendantCount = comment_descendant_count($node['children']);
                    ?>
                    <?php if ($descendantCount > $collapseThreshold): ?>
                        <details class="lp-comment__thread">
                            <summary><?= (int) $descendantCount ?> repl<?= $descendantCount === 1 ? 'y' : 'ies' ?></summary>
                            <?php comment_list($node['children'], $content, $currentUser, $guestFieldOptions, $nextDepth, $maxDepth, $avatarsEnabled, $avatarRating, $avatarDefault, $collapseThreshold); ?>
                        </details>
                    <?php else: ?>
                        <?php comment_list($node['children'], $content, $currentUser, $guestFieldOptions, $nextDepth, $maxDepth, $avatarsEnabled, $avatarRating, $avatarDefault, $collapseThreshold); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }
}

if (!function_exists('comment_subscription_checkbox')) {
    /**
     * The comment form's "Notify me of new comments" opt-in, added to
     * every form through the comment_form_fields_after filter.
     */
    function comment_subscription_checkbox(string $formId, bool $isGuest): string
    {
        $hint = $isGuest ? ' <span class="lp-field__hint">' . esc_html(__('(you\'ll be asked to confirm by email)')) . '</span>' : '';

        return '<label class="lp-field--checkbox lp-comment-form__subscribe" for="' . esc_attr($formId) . '-subscribe">'
            . '<input type="checkbox" id="' . esc_attr($formId) . '-subscribe" name="comment_subscribe" value="1"> '
            . esc_html(__('Notify me of new comments by email')) . $hint
            . '</label>';
    }
}

if (!function_exists('comment_subscription_panel')) {
    /**
     * The thread's subscription notices and buttons, rendered above the
     * comments section via the comments_template action — see
     * CommentSubscriptionController::panelState() for the $state shape.
     *
     * @param array{notice: ?string, manage: ?array{status: string}, watch: ?array{watching: bool, contentType: string, contentId: int}, formAction: string} $state
     */
    function comment_subscription_panel(array $state): void
    {
        if ($state['notice'] === null && $state['manage'] === null && $state['watch'] === null) {
            return;
        }

        $notices = [
            'confirmed' => ['success', __('Your subscription is confirmed. We\'ll email you when someone comments here.')],
            'unsubscribed' => ['success', __('You\'ve been unsubscribed and won\'t get any more emails about new comments here.')],
            'watching' => ['success', __('You\'re now watching this thread. We\'ll let you know about new comments.')],
            'confirm_sent' => ['success', __('We\'ve emailed you a link to confirm your subscription to new comments.')],
            'confirm_after_approval' => ['success', __('Once your comment is approved, we\'ll email you a link to confirm your subscription to new comments.')],
            'error' => ['error', __('That subscription link is no longer valid.')],
        ];
        $notice = $state['notice'] !== null ? ($notices[$state['notice']] ?? null) : null;
        ?>
        <div id="lp-comment-subscription" class="lp-comment-subscription">
            <?php if ($notice !== null): ?>
                <div class="lp-alert lp-alert--<?= esc_attr($notice[0]) ?>"><?= esc_html($notice[1]) ?></div>
            <?php endif; ?>

            <?php if ($state['manage'] !== null): ?>
                <form class="lp-comment-subscription__manage" method="post" action="<?= esc_url($state['formAction']) ?>">
                    <?= Csrf::field('comment_subscription') ?>
                    <?php if ($state['manage']['status'] === 'pending'): ?>
                        <p class="lp-comment-subscription__text"><?= esc_html(__('Confirm that you want an email whenever someone comments here?')) ?></p>
                        <p class="lp-comment-subscription__actions">
                            <button type="submit" class="lp-button lp-button--primary" name="subscription_action" value="confirm"><?= esc_html(__('Confirm subscription')) ?></button>
                            <button type="submit" class="lp-button lp-button--secondary" name="subscription_action" value="unsubscribe"><?= esc_html(__('Cancel')) ?></button>
                        </p>
                    <?php else: ?>
                        <p class="lp-comment-subscription__text"><?= esc_html(__('You\'re subscribed to new comments here.')) ?></p>
                        <p class="lp-comment-subscription__actions">
                            <button type="submit" class="lp-button lp-button--secondary" name="subscription_action" value="unsubscribe"><?= esc_html(__('Unsubscribe')) ?></button>
                        </p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if ($state['watch'] !== null): ?>
                <form class="lp-comment-subscription__watch" method="post" action="<?= esc_url($state['formAction']) ?>">
                    <?= Csrf::field('comment_subscription') ?>
                    <input type="hidden" name="content_type" value="<?= esc_attr($state['watch']['contentType']) ?>">
                    <input type="hidden" name="content_id" value="<?= (int) $state['watch']['contentId'] ?>">
                    <?php if ($state['watch']['watching']): ?>
                        <span class="lp-comment-subscription__text"><?= esc_html(__('You\'re watching this thread.')) ?></span>
                        <button type="submit" class="lp-button lp-button--secondary" name="subscription_action" value="unwatch"><?= esc_html(__('Stop watching')) ?></button>
                    <?php else: ?>
                        <button type="submit" class="lp-button lp-button--secondary" name="subscription_action" value="watch"><?= esc_html(__('Watch this thread')) ?></button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('comment_tree_ids')) {
    /**
     * Every comment id anywhere in a comment_list() tree.
     *
     * @param array<int, array{comment: Comment, children: array<mixed>}> $tree
     * @return array<int, int>
     */
    function comment_tree_ids(array $tree): array
    {
        $ids = [];

        foreach ($tree as $node) {
            $ids[] = $node['comment']->id;
            array_push($ids, ...comment_tree_ids($node['children']));
        }

        return $ids;
    }
}

if (!function_exists('comment_author_badge')) {
    /**
     * A registered commenter's role, or "Post author" when they wrote the
     * post/page itself. Guests get nothing. Users are looked up once per
     * request however many comments they left.
     */
    function comment_author_badge(Comment $comment, Post|Page $content): string
    {
        static $users = [];

        if ($comment->userId === null) {
            return '';
        }

        if ($comment->userId === $content->authorId) {
            return '<span class="lp-comment__role lp-comment__role--post-author">' . esc_html(__('Post author')) . '</span>';
        }

        if (!array_key_exists($comment->userId, $users)) {
            $users[$comment->userId] = Authors::users()->findById($comment->userId);
        }

        $user = $users[$comment->userId];

        return $user === null ? '' : '<span class="lp-comment__role lp-comment__role--' . esc_attr($user->role->value) . '">' . esc_html(__($user->role->label())) . '</span>';
    }
}

if (!function_exists('comment_quotable_text')) {
    /**
     * $raw without lines it was itself quoting, so quoting a reply doesn't
     * pile up nested quotes of earlier comments.
     */
    function comment_quotable_text(string $raw): string
    {
        $lines = array_filter(
            preg_split('/\r\n|\r|\n/', $raw) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '>'),
        );

        return trim(implode("\n", $lines));
    }
}

if (!function_exists('comment_interaction_csrf_token')) {
    /**
     * One token shared by every reaction/report form on the page: minting
     * one per form would replace the session's token each time, leaving
     * only the last form valid.
     */
    function comment_interaction_csrf_token(): string
    {
        static $token = null;

        return $token ??= Csrf::token('comment_interact');
    }
}

if (!function_exists('comment_reaction_buttons')) {
    /**
     * The comment's Like/reaction buttons with their counts. Read-only
     * counts (no buttons) for guests when reacting requires signing in.
     */
    function comment_reaction_buttons(Comment $comment): string
    {
        $reactions = CommentExtras::reactions();

        if ($reactions === null || !$reactions->isEnabled()) {
            return '';
        }

        $counts = CommentExtras::countsFor($comment->id);
        $choice = CommentExtras::choiceFor($comment->id);
        $labels = [
            'like' => __('Like'),
            'love' => __('Love'),
            'laugh' => __('Haha'),
            'wow' => __('Wow'),
            'sad' => __('Sad'),
        ];
        $isLikeOnly = $reactions->mode() === 'like';
        $canReact = !$reactions->requiresLogin() || ActiveAuth::auth()->user() !== null;

        $items = '';

        foreach ($reactions->available() as $key => $emoji) {
            $count = $counts[$key] ?? 0;
            $label = $labels[$key] ?? $key;
            $inner = '<span class="lp-comment-reactions__emoji" aria-hidden="true">' . $emoji . '</span>'
                . ($isLikeOnly ? '<span class="lp-comment-reactions__label">' . esc_html($label) . '</span>' : '')
                . '<span class="lp-comment-reactions__count" data-lp-reaction-count' . ($count === 0 ? ' hidden' : '') . '>' . $count . '</span>';
            $accessibleName = esc_attr($label . ($count > 0 ? ' (' . $count . ')' : ''));

            if (!$canReact) {
                if ($count > 0) {
                    $items .= '<span class="lp-comment-reactions__item" title="' . esc_attr($label) . '" aria-label="' . $accessibleName . '">' . $inner . '</span>';
                }

                continue;
            }

            $isSelected = $choice === $key;
            $items .= '<button type="submit" name="reaction" value="' . esc_attr($key) . '" class="lp-comment-reactions__button' . ($isSelected ? ' is-selected' : '') . '"'
                . ' aria-pressed="' . ($isSelected ? 'true' : 'false') . '" title="' . esc_attr($label) . '" aria-label="' . $accessibleName . '" data-lp-reaction="' . esc_attr($key) . '" data-lp-reaction-label="' . esc_attr($label) . '">'
                . $inner . '</button>';
        }

        if ($items === '') {
            return '';
        }

        if (!$canReact) {
            return '<div class="lp-comment-reactions">' . $items . '</div>';
        }

        return '<form class="lp-comment-reactions" method="post" action="' . esc_url(home_url('comment/' . $comment->id . '/react')) . '" data-lp-comment-reactions>'
            . '<input type="hidden" name="csrf_token" value="' . esc_attr(comment_interaction_csrf_token()) . '" data-lp-comment-csrf>'
            . $items
            . '</form>';
    }
}

if (!function_exists('comment_report_form')) {
    /**
     * A collapsed "Report" control with a reason picker, or a thank-you
     * line right after this visitor reported the comment without JavaScript.
     */
    function comment_report_form(Comment $comment): string
    {
        $reports = CommentExtras::reports();

        if ($reports === null || !$reports->isEnabled()) {
            return '';
        }

        if ((int) ($_GET['comment_reported'] ?? 0) === $comment->id) {
            return '<p class="lp-comment-report__thanks" role="status">' . esc_html(__('Thanks for letting us know. A moderator will take a look.')) . '</p>';
        }

        $fieldId = 'comment-report-reason-' . $comment->id;
        $options = '';

        foreach (CommentReportService::REASONS as $key => $label) {
            $options .= '<option value="' . esc_attr($key) . '">' . esc_html(__($label)) . '</option>';
        }

        return '<details class="lp-comment-report">'
            . '<summary>' . esc_html(__('Report')) . '</summary>'
            . '<form class="lp-comment-report__form" method="post" action="' . esc_url(home_url('comment/' . $comment->id . '/report')) . '" data-lp-comment-report data-lp-report-thanks="' . esc_attr(__('Thanks for letting us know. A moderator will take a look.')) . '">'
            . '<input type="hidden" name="csrf_token" value="' . esc_attr(comment_interaction_csrf_token()) . '" data-lp-comment-csrf>'
            . '<label for="' . esc_attr($fieldId) . '">' . esc_html(__('Why are you reporting this comment?')) . '</label>'
            . '<select id="' . esc_attr($fieldId) . '" name="reason">' . $options . '</select>'
            . '<button type="submit" class="lp-button lp-button--secondary">' . esc_html(__('Send report')) . '</button>'
            . '</form>'
            . '</details>';
    }
}
