<?php

/**
 * Public front-end route handlers: posts, pages, archives, search, feeds, 404s, and media downloads.
 *
 * @package LumoraPress
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use LumoraPress\Core\Cache\CacheManager;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Auth;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;
use LumoraPress\Core\Theme\ThemeRenderer;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use LumoraPress\Models\User;
use LumoraPress\Services\AkismetClient;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentModerationService;
use LumoraPress\Services\CommentNotificationService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\FeedService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\MediaStatsService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PermalinkService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\RedirectService;
use LumoraPress\Services\SearchService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\UserService;

/**
 * Public front-end routes. Posts, Pages, Categories, Tags, Feeds, and
 * Search now render from PostService/PageService/CategoryService/
 * TagService/FeedService/SearchService.
 */
final class SiteController
{
    /**
     * Minimum seconds between two comments from the same IP address —
     * basic flood control. Not user-configurable yet.
     */
    private const COMMENT_FLOOD_WINDOW_SECONDS = 30;

    public function __construct(
        private readonly ThemeRenderer $theme,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly CommentService $comments,
        private readonly Auth $auth,
        private readonly PressConfig $config,
        private readonly FeedService $feeds,
        private readonly SearchService $search,
        private readonly MediaService $media,
        private readonly MediaStatsService $mediaStats,
        private readonly CacheManager $cache,
        private readonly RedirectService $redirects,
        private readonly AkismetClient $akismet,
        private readonly UserService $users,
        private readonly CommentModerationService $commentModeration,
        private readonly CommentNotificationService $commentNotifications,
        private readonly PermalinkService $permalinks,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function home(array $params): void
    {
        if ($this->config->option('homepage_display', 'posts') === 'page') {
            $homepagePageId = (int) $this->config->option('homepage_page_id', '0');
            $homepagePage = $homepagePageId > 0 ? $this->pages->findById($homepagePageId) : null;

            if ($homepagePage !== null && $homepagePage->isPubliclyVisible()) {
                $this->markCacheableForGuests(['page_' . $homepagePage->id]);
                $this->theme->render('page.php', [
                    'page_title' => $homepagePage->title,
                    'page' => $homepagePage,
                    'comment_data' => $this->commentTemplateDataForPage($homepagePage, $this->auth->user()),
                ]);

                return;
            }
        }

        $this->renderPostsListing(null);
    }

    /**
     * The configured "Blog pages show at most" size (Settings > Reading),
     * shared by the homepage listing and every archive view below.
     */
    private function postsPerPage(): int
    {
        return max(1, (int) $this->config->option('posts_per_page', '10'));
    }

    /**
     * Opts the current response into HTTP caching — only for a guest, so
     * a logged-in visitor always gets a fresh render and never risks
     * being served another visitor's cached page. Every caller renders
     * identically for every guest at the same URL.
     *
     * @param array<int, string> $tags
     */
    private function markCacheableForGuests(array $tags = []): void
    {
        if (!$this->auth->check()) {
            $this->cache->markPageCacheable($tags);
        }
    }

    private function renderPostsListing(?string $pageTitle): void
    {
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginatePublished($page, $this->postsPerPage());

        $this->markCacheableForGuests(['posts']);
        $this->theme->render('index.php', [
            'page_title' => $pageTitle,
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
            'comment_counts' => $this->commentCountsFor($pagination['posts']),
        ]);
    }

    /**
     * Batched comment counts for a page of listing posts — one query for
     * the whole page rather than one per post (see comments_link()).
     *
     * @param array<int, Post> $posts
     * @return array<int, int>
     */
    private function commentCountsFor(array $posts): array
    {
        return $this->comments->countsForPosts(array_map(
            static fn (Post $post): int => $post->id,
            $posts,
        ));
    }

    /**
     * Whether $pageId is the configured "Posts page" — a page whose own
     * URL shows the latest-posts listing instead of its stored content.
     * Only meaningful while a static homepage is configured.
     */
    private function isConfiguredPostsPage(int $pageId): bool
    {
        return $pageId > 0
            && $this->config->option('homepage_display', 'posts') === 'page'
            && (int) $this->config->option('homepage_posts_page_id', '0') === $pageId;
    }

    /**
     * @param array<string, string> $params
     */
    public function singlePost(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $post = $slug !== '' ? $this->posts->findBySlug($slug) : null;

        if ($post === null || !$post->isVisibleToViewer($this->canViewPrivatePost($post))) {
            $this->notFound();

            return;
        }

        // No-op unless a plugin listens — core has no view-tracking of its own.
        do_action('single_post_viewed', $post, !$this->auth->check());

        $this->theme->render('single.php', [
            'page_title' => $post->title,
            'post' => $post,
            'comment_data' => $this->commentTemplateData($post, $this->auth->user()),
        ]);
    }

    /**
     * Everything comments_template() needs for $post, bundled into one
     * array a theme's single.php forwards straight through rather than
     * re-listing each key by hand. Shared by singlePost() and previewPost().
     *
     * @return array<string, mixed>
     */
    private function commentTemplateData(Post $post, ?User $currentUser): array
    {
        return [
            'post' => $post,
            'current_user' => $currentUser,
            'comments_open' => $this->commentsOpenFor($post),
            ...$this->commentThreadViewData($post),
        ];
    }

    /**
     * Pagination, ordering, threading, and avatar display view data for
     * $post's comment thread, driven by Settings > Discussion options.
     *
     * @return array<string, mixed>
     */
    private function commentThreadViewData(Post $post): array
    {
        $order = (string) $this->config->option('comment_order', 'asc');
        $threaded = $this->config->option('comment_threading_enabled', '1') !== '0';
        $maxNesting = max(0, (int) $this->config->option('comment_max_nesting_level', '5'));

        if ($this->config->option('comment_pagination_enabled', '0') !== '1') {
            // Pagination disabled: one "page" of up to 100000 comments, comfortably past any realistic thread size.
            $pagination = $this->comments->paginateForPost($post->id, 1, 100000, $order, $threaded);
        } else {
            $perPage = max(1, (int) $this->config->option('comment_per_page', '50'));
            $requestedPage = isset($_GET['cpage']) ? max(1, (int) $_GET['cpage']) : null;

            $pagination = $this->comments->paginateForPost($post->id, $requestedPage ?? 1, $perPage, $order, $threaded);

            if ($requestedPage === null && $this->config->option('comment_default_page', 'last') === 'last' && $pagination['totalPages'] > 1) {
                $pagination = $this->comments->paginateForPost($post->id, $pagination['totalPages'], $perPage, $order, $threaded);
            }
        }

        return [
            'comment_tree' => $pagination['comments'],
            'comment_pagination' => $pagination,
            'comment_pagination_enabled' => $this->config->option('comment_pagination_enabled', '0') === '1',
            'comment_max_nesting_level' => $maxNesting,
            'comment_count' => $this->comments->countForPost($post->id),
            'avatars_enabled' => $this->config->option('avatars_enabled', '1') !== '0',
            'avatar_rating' => strtolower((string) $this->config->option('avatar_max_rating', 'G')),
            'avatar_default' => $this->avatarDefaultParam(),
            'comment_cookies_consent_enabled' => $this->config->option('comment_cookies_consent_enabled', '0') === '1',
            'comment_author_name_required' => $this->commentModeration->isAuthorNameRequired(),
            'comment_author_email_required' => $this->commentModeration->isAuthorEmailRequired(),
            'comment_saved_guest_name' => is_string($_COOKIE['lp_commenter_name'] ?? null) ? $_COOKIE['lp_commenter_name'] : '',
            'comment_saved_guest_email' => is_string($_COOKIE['lp_commenter_email'] ?? null) ? $_COOKIE['lp_commenter_email'] : '',
            'comment_saved_guest_url' => is_string($_COOKIE['lp_commenter_url'] ?? null) ? $_COOKIE['lp_commenter_url'] : '',
        ];
    }

    /**
     * Mirrors commentTemplateData(Post) exactly, for Pages.
     *
     * @return array<string, mixed>
     */
    private function commentTemplateDataForPage(Page $page, ?User $currentUser): array
    {
        return [
            'page' => $page,
            'current_user' => $currentUser,
            'comments_open' => $this->commentModeration->commentsOpenForPage($page),
            ...$this->commentThreadViewDataForPage($page),
        ];
    }

    /**
     * Mirrors commentThreadViewData(Post) exactly, for Pages.
     *
     * @return array<string, mixed>
     */
    private function commentThreadViewDataForPage(Page $page): array
    {
        $order = (string) $this->config->option('comment_order', 'asc');
        $threaded = $this->config->option('comment_threading_enabled', '1') !== '0';
        $maxNesting = max(0, (int) $this->config->option('comment_max_nesting_level', '5'));

        if ($this->config->option('comment_pagination_enabled', '0') !== '1') {
            $pagination = $this->comments->paginateForPage($page->id, 1, 100000, $order, $threaded);
        } else {
            $perPage = max(1, (int) $this->config->option('comment_per_page', '50'));
            $requestedPage = isset($_GET['cpage']) ? max(1, (int) $_GET['cpage']) : null;

            $pagination = $this->comments->paginateForPage($page->id, $requestedPage ?? 1, $perPage, $order, $threaded);

            if ($requestedPage === null && $this->config->option('comment_default_page', 'last') === 'last' && $pagination['totalPages'] > 1) {
                $pagination = $this->comments->paginateForPage($page->id, $pagination['totalPages'], $perPage, $order, $threaded);
            }
        }

        return [
            'comment_tree' => $pagination['comments'],
            'comment_pagination' => $pagination,
            'comment_pagination_enabled' => $this->config->option('comment_pagination_enabled', '0') === '1',
            'comment_max_nesting_level' => $maxNesting,
            'comment_count' => $this->comments->countForPage($page->id),
            'avatars_enabled' => $this->config->option('avatars_enabled', '1') !== '0',
            'avatar_rating' => strtolower((string) $this->config->option('avatar_max_rating', 'G')),
            'avatar_default' => $this->avatarDefaultParam(),
            'comment_cookies_consent_enabled' => $this->config->option('comment_cookies_consent_enabled', '0') === '1',
            'comment_author_name_required' => $this->commentModeration->isAuthorNameRequired(),
            'comment_author_email_required' => $this->commentModeration->isAuthorEmailRequired(),
            'comment_saved_guest_name' => is_string($_COOKIE['lp_commenter_name'] ?? null) ? $_COOKIE['lp_commenter_name'] : '',
            'comment_saved_guest_email' => is_string($_COOKIE['lp_commenter_email'] ?? null) ? $_COOKIE['lp_commenter_email'] : '',
            'comment_saved_guest_url' => is_string($_COOKIE['lp_commenter_url'] ?? null) ? $_COOKIE['lp_commenter_url'] : '',
        ];
    }

    /**
     * The Gravatar "d" (default image) param: an absolute URL to the
     * locally uploaded default avatar (Settings > Discussion) when one is
     * configured, otherwise one of Gravatar's own built-in default styles.
     */
    private function avatarDefaultParam(): string
    {
        $mediaId = (int) $this->config->option('avatar_default_media_id', '0');

        if ($mediaId > 0) {
            $item = $this->media->find($mediaId);

            if ($item !== null) {
                return $this->media->url($item);
            }
        }

        return (string) $this->config->option('avatar_default', 'mp');
    }

    /**
     * Handles the public "post a comment" form on a single post. Always
     * redirects back to the post (the classic post/redirect/get pattern
     * used throughout the admin) rather than rendering a result directly,
     * so a page refresh never resubmits the comment.
     *
     * @param array<string, string> $params
     */
    public function submitComment(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $post = $slug !== '' ? $this->posts->findBySlug($slug) : null;

        if ($post === null || !$post->isPubliclyVisible()) {
            $this->notFound();

            return;
        }

        $redirectTo = post_permalink($post);

        if (!$this->commentsOpenFor($post)) {
            header('Location: ' . $redirectTo . '#comments');
            exit;
        }

        // parent_id also selects which form's CSRF token to check — each reply form has its own action name.
        $rawParentId = (int) ($_POST['parent_id'] ?? 0);
        $csrfAction = 'comment_submit_' . $post->id . '_' . ($rawParentId > 0 ? $rawParentId : 'root');
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify($csrfAction, $token)) {
            header('Location: ' . $redirectTo . '?comment=error#comment-form');
            exit;
        }

        // Honeypot: a real visitor never fills this hidden field. Fail
        // silently (pretend success) rather than revealing detection to
        // the bot filling it in.
        if (trim((string) ($_POST['comment_website'] ?? '')) !== '') {
            header('Location: ' . $redirectTo . '#comment-form');
            exit;
        }

        // Submission timing: a real visitor takes at least a few seconds
        // to fill in the form, so an instant submission is a bot signal.
        // Same "fail silently" treatment as the honeypot above.
        $formTime = is_string($_POST['form_time'] ?? null) ? $_POST['form_time'] : null;
        $formTimeHmac = is_string($_POST['form_time_hmac'] ?? null) ? $_POST['form_time_hmac'] : null;

        if (!FormTiming::verify($formTime, $formTimeHmac)) {
            header('Location: ' . $redirectTo . '#comment-form');
            exit;
        }

        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        if ($this->comments->recentCommentFromIpExists($ipAddress, self::COMMENT_FLOOD_WINDOW_SECONDS)) {
            header('Location: ' . $redirectTo . '?comment=flood#comment-form');
            exit;
        }

        $authUser = $this->auth->user();

        // Checked before touching any submitted guest fields.
        if ($authUser === null && $this->commentModeration->requiresRegistrationToComment()) {
            header('Location: ' . $redirectTo . '?comment=login_required#comment-form');
            exit;
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        $parentId = $rawParentId > 0 ? $rawParentId : null;

        $userId = $authUser?->id;
        $guestName = $authUser !== null ? $authUser->displayName : trim((string) ($_POST['guest_name'] ?? ''));
        $guestEmail = $authUser !== null ? $authUser->email : trim((string) ($_POST['guest_email'] ?? ''));
        $guestUrl = trim((string) ($_POST['guest_url'] ?? ''));
        $guestUrl = $guestUrl !== '' && filter_var($guestUrl, FILTER_VALIDATE_URL) !== false ? $guestUrl : null;

        // When a field isn't required, fill a placeholder rather than leave it empty — guest_name/guest_email are NOT NULL columns.
        if ($authUser === null) {
            if ($guestName === '' && !$this->commentModeration->isAuthorNameRequired()) {
                $guestName = __('Anonymous');
            }

            if ($guestEmail === '' && !$this->commentModeration->isAuthorEmailRequired()) {
                $guestEmail = 'anonymous@' . ((string) (parse_url(home_url(), PHP_URL_HOST) ?: 'invalid.example'));
            }
        }

        if ($content === '' || $guestName === '' || filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false) {
            header('Location: ' . $redirectTo . '?comment=error#comment-form');
            exit;
        }

        if ($parentId !== null) {
            $parent = $this->comments->findById($parentId);

            if ($parent === null || $parent->postId !== $post->id) {
                $parentId = null;
            }
        }

        $status = $this->commentModeration->determineStatus(
            userId: $userId,
            isTrustedModerator: $authUser?->can('moderate_comments') ?? false,
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestUrl: $guestUrl,
            content: $content,
        );

        // Akismet, when enabled, can only push a comment toward Spam, never away — additive on the decision above.
        if ($this->akismet->isEnabled()) {
            $isSpam = $this->akismet->checkComment([
                'comment_type' => 'comment',
                'comment_author' => $guestName,
                'comment_author_email' => $guestEmail,
                'comment_author_url' => $guestUrl,
                'comment_content' => $content,
                'user_ip' => $ipAddress,
                'user_agent' => $userAgent,
                'referrer' => is_string($_SERVER['HTTP_REFERER'] ?? null) ? $_SERVER['HTTP_REFERER'] : null,
                'permalink' => $redirectTo,
            ]);

            if ($isSpam === true) {
                $status = CommentStatus::Spam;
            }
        }

        if (apply_filters('comment_is_spam', false, $guestName, $guestEmail, $guestUrl, $content, $ipAddress) === true) {
            $status = CommentStatus::Spam;
        }

        $comment = $this->comments->create(
            postId: $post->id,
            parentId: $parentId,
            userId: $userId,
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestUrl: $guestUrl,
            content: $content,
            status: $status,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        do_action('comment_posted', $comment);

        if ($status !== CommentStatus::Spam) {
            $this->commentNotifications->notifyNewComment($comment, $post);
        }

        // A guest who checked "Save my info" gets those fields pre-filled next time; unchecking never writes/clears anything.
        if ($authUser === null && $this->config->option('comment_cookies_consent_enabled', '0') === '1' && ($_POST['comment_save_info'] ?? '') === '1') {
            $expires = time() + (86400 * 90);
            setcookie('lp_commenter_name', $guestName, $expires, '/');
            setcookie('lp_commenter_email', $guestEmail, $expires, '/');
            setcookie('lp_commenter_url', $guestUrl ?? '', $expires, '/');
        }

        $flag = $status === CommentStatus::Approved ? 'posted' : 'pending';
        $anchor = $status === CommentStatus::Approved ? '#comment-' . $comment->id : '#comment-form';

        header('Location: ' . $redirectTo . '?comment=' . $flag . $anchor);
        exit;
    }

    /**
     * Mirrors submitComment(), for Pages. Kept separate since posts and pages have no shared
     * interface to dispatch through. Uses a distinct CSRF action prefix
     * ('comment_submit_page_') since a post and page can share the same numeric id and
     * Csrf::verify() keys tokens by action name alone.
     *
     * @param array<string, string> $params
     */
    public function submitPageComment(array $params): void
    {
        $slug = $params['slug'] ?? self::lastPathSegment((string) ($params['path'] ?? ''));
        $page = $slug !== '' ? $this->pages->findBySlug($slug) : null;

        if ($page === null || !$page->isPubliclyVisible()) {
            $this->notFound();

            return;
        }

        $redirectTo = page_permalink($page);

        if (!$this->commentModeration->commentsOpenForPage($page)) {
            header('Location: ' . $redirectTo . '#comments');
            exit;
        }

        $rawParentId = (int) ($_POST['parent_id'] ?? 0);
        $csrfAction = 'comment_submit_page_' . $page->id . '_' . ($rawParentId > 0 ? $rawParentId : 'root');
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify($csrfAction, $token)) {
            header('Location: ' . $redirectTo . '?comment=error#comment-form');
            exit;
        }

        if (trim((string) ($_POST['comment_website'] ?? '')) !== '') {
            header('Location: ' . $redirectTo . '#comment-form');
            exit;
        }

        $formTime = is_string($_POST['form_time'] ?? null) ? $_POST['form_time'] : null;
        $formTimeHmac = is_string($_POST['form_time_hmac'] ?? null) ? $_POST['form_time_hmac'] : null;

        if (!FormTiming::verify($formTime, $formTimeHmac)) {
            header('Location: ' . $redirectTo . '#comment-form');
            exit;
        }

        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        if ($this->comments->recentCommentFromIpExists($ipAddress, self::COMMENT_FLOOD_WINDOW_SECONDS)) {
            header('Location: ' . $redirectTo . '?comment=flood#comment-form');
            exit;
        }

        $authUser = $this->auth->user();

        if ($authUser === null && $this->commentModeration->requiresRegistrationToComment()) {
            header('Location: ' . $redirectTo . '?comment=login_required#comment-form');
            exit;
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        $parentId = $rawParentId > 0 ? $rawParentId : null;

        $userId = $authUser?->id;
        $guestName = $authUser !== null ? $authUser->displayName : trim((string) ($_POST['guest_name'] ?? ''));
        $guestEmail = $authUser !== null ? $authUser->email : trim((string) ($_POST['guest_email'] ?? ''));
        $guestUrl = trim((string) ($_POST['guest_url'] ?? ''));
        $guestUrl = $guestUrl !== '' && filter_var($guestUrl, FILTER_VALIDATE_URL) !== false ? $guestUrl : null;

        if ($authUser === null) {
            if ($guestName === '' && !$this->commentModeration->isAuthorNameRequired()) {
                $guestName = __('Anonymous');
            }

            if ($guestEmail === '' && !$this->commentModeration->isAuthorEmailRequired()) {
                $guestEmail = 'anonymous@' . ((string) (parse_url(home_url(), PHP_URL_HOST) ?: 'invalid.example'));
            }
        }

        if ($content === '' || $guestName === '' || filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false) {
            header('Location: ' . $redirectTo . '?comment=error#comment-form');
            exit;
        }

        if ($parentId !== null) {
            $parent = $this->comments->findById($parentId);

            if ($parent === null || $parent->pageId !== $page->id) {
                $parentId = null;
            }
        }

        $status = $this->commentModeration->determineStatus(
            userId: $userId,
            isTrustedModerator: $authUser?->can('moderate_comments') ?? false,
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestUrl: $guestUrl,
            content: $content,
        );

        if ($this->akismet->isEnabled()) {
            $isSpam = $this->akismet->checkComment([
                'comment_type' => 'comment',
                'comment_author' => $guestName,
                'comment_author_email' => $guestEmail,
                'comment_author_url' => $guestUrl,
                'comment_content' => $content,
                'user_ip' => $ipAddress,
                'user_agent' => $userAgent,
                'referrer' => is_string($_SERVER['HTTP_REFERER'] ?? null) ? $_SERVER['HTTP_REFERER'] : null,
                'permalink' => $redirectTo,
            ]);

            if ($isSpam === true) {
                $status = CommentStatus::Spam;
            }
        }

        if (apply_filters('comment_is_spam', false, $guestName, $guestEmail, $guestUrl, $content, $ipAddress) === true) {
            $status = CommentStatus::Spam;
        }

        $comment = $this->comments->create(
            postId: null,
            parentId: $parentId,
            userId: $userId,
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestUrl: $guestUrl,
            content: $content,
            status: $status,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            pageId: $page->id,
        );

        do_action('comment_posted', $comment);

        if ($status !== CommentStatus::Spam) {
            $this->commentNotifications->notifyNewCommentOnPage($comment, $page);
        }

        if ($authUser === null && $this->config->option('comment_cookies_consent_enabled', '0') === '1' && ($_POST['comment_save_info'] ?? '') === '1') {
            $expires = time() + (86400 * 90);
            setcookie('lp_commenter_name', $guestName, $expires, '/');
            setcookie('lp_commenter_email', $guestEmail, $expires, '/');
            setcookie('lp_commenter_url', $guestUrl ?? '', $expires, '/');
        }

        $flag = $status === CommentStatus::Approved ? 'posted' : 'pending';
        $anchor = $status === CommentStatus::Approved ? '#comment-' . $comment->id : '#comment-form';

        header('Location: ' . $redirectTo . '?comment=' . $flag . $anchor);
        exit;
    }

    /**
     * A post accepts comments only if the post and the site both allow
     * it, and the post isn't past the auto-close window. Delegates to
     * CommentModerationService so SiteController and ApiController share
     * one answer.
     */
    private function commentsOpenFor(Post $post): bool
    {
        return $this->commentModeration->commentsOpenFor($post);
    }

    /**
     * Whether the current visitor may see $post despite it being
     * Private: logged in, and either its author or holding edit_posts.
     */
    private function canViewPrivatePost(Post $post): bool
    {
        $user = $this->auth->user();

        return $user !== null && ($user->can('edit_posts') || $user->id === $post->authorId);
    }

    /**
     * Mirrors canViewPrivatePost() — Pages reuse the Posts capabilities
     * since no dedicated page capabilities exist yet.
     */
    private function canViewPrivatePage(Page $page): bool
    {
        $user = $this->auth->user();

        return $user !== null && ($user->can('edit_posts') || $user->id === $page->authorId);
    }

    /**
     * The final segment of a "/{path*}/comment" route's matched path
     * (e.g. "team" from "about/team") — submitPageComment()'s fallback
     * for deriving the page's slug outside the legacy route.
     */
    private static function lastPathSegment(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);

        return (string) end($segments);
    }

    /**
     * "Preview button" — lets an author/editor view a post exactly as it
     * will render publicly, without publishing it or making it reachable
     * by any other visitor (bypasses isVisibleToViewer() entirely rather
     * than issuing a shareable signed URL). Gated by the same ownership
     * rule as editing a post. Deliberately never cached.
     *
     * @param array<string, string> $params
     */
    public function previewPost(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $post = $id > 0 ? $this->posts->findById($id) : null;
        $user = $this->auth->user();

        if ($post === null || $user === null || !($user->can('edit_others_posts') || $user->id === $post->authorId)) {
            $this->notFound();

            return;
        }

        $this->theme->render('single.php', [
            'page_title' => $post->title,
            'post' => $post,
            'comment_data' => $this->commentTemplateData($post, $user),
        ]);
    }

    /**
     * Mirrors previewPost() for Pages — a distinct `/preview-page/{id}`
     * route since post and page ids each start from 1 and would
     * otherwise collide. Deliberately never cached.
     *
     * @param array<string, string> $params
     */
    public function previewPage(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $page = $id > 0 ? $this->pages->findById($id) : null;
        $user = $this->auth->user();

        if ($page === null || $user === null || !($user->can('edit_others_posts') || $user->id === $page->authorId)) {
            $this->notFound();

            return;
        }

        $this->theme->render('page.php', [
            'page_title' => $page->title,
            'page' => $page,
            'page_ancestors' => $this->pages->ancestors($page->id),
            'comment_data' => $this->commentTemplateDataForPage($page, $user),
        ]);
    }

    /**
     * Public author archives (`/author/{slug}`). A user with zero published posts 404s
     * exactly like a nonexistent slug, closing a username-enumeration oracle unconditionally.
     * The `lumora_shield_author_archive_visible` filter remains for hiding every author
     * archive outright. Every 404 branch fires 'lumora_shield_enumeration_blocked'.
     *
     * @param array<string, string> $params
     */
    public function author(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $author = $slug !== '' ? $this->users->findByAuthorSlug($slug) : null;

        if ($author === null) {
            do_action('lumora_shield_enumeration_blocked', $slug, 'unknown_user', $ipAddress);
            $this->notFound();

            return;
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginateByAuthor($author->id, $page, $this->postsPerPage());

        if ($pagination['total'] === 0) {
            do_action('lumora_shield_enumeration_blocked', $slug, 'zero_posts', $ipAddress);
            $this->notFound();

            return;
        }

        if (!apply_filters('lumora_shield_author_archive_visible', true, $author, $pagination['total'])) {
            do_action('lumora_shield_enumeration_blocked', $slug, 'hidden_by_setting', $ipAddress);
            $this->notFound();

            return;
        }

        $this->markCacheableForGuests(['posts', 'author_' . $author->id]);
        $this->theme->render('archive.php', [
            'page_title' => $author->displayName,
            'archive_type' => 'author',
            'archive_description' => null,
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
            'comment_counts' => $this->commentCountsFor($pagination['posts']),
        ]);
    }

    /**
     * The hierarchical category route — "/category/{path*}".
     * $params['path'] is resolved segment by segment via
     * CategoryService::findByPath(), the same ancestor-chain-validated
     * shape pageByPath() uses for Pages, so a URL with a missing or wrong
     * ancestor prefix doesn't silently resolve by its final slug alone.
     *
     * Unlike Pages, category slugs stay globally unique regardless of
     * nesting depth, so a failed hierarchical match still falls back to a
     * plain findBySlug() on the final segment — an old bookmarked/indexed
     * flat link to a category that has since gained a parent 301-redirects
     * to the correct nested URL instead of 404ing.
     *
     * @param array<string, string> $params
     */
    public function category(array $params): void
    {
        $path = trim((string) ($params['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $category = $segments !== [] ? $this->categories->findByPath($segments) : null;

        if ($category === null && $segments !== []) {
            $bySlug = $this->categories->findBySlug($segments[array_key_last($segments)]);

            if ($bySlug !== null) {
                header('Location: ' . category_permalink($bySlug), true, 301);

                return;
            }
        }

        if ($category === null) {
            $this->notFound();

            return;
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginateByCategory($category->id, $page, $this->postsPerPage());

        $this->markCacheableForGuests(['posts', 'category_' . $category->id]);
        $this->theme->render('archive.php', [
            'page_title' => $category->name,
            'archive_type' => 'category',
            'archive_description' => $category->description,
            'archive_image_url' => category_image_url($category, 'large'),
            // Root-first ancestor chain, empty for a top-level category — mirrors
            // SiteController::pageByPath()'s own 'page_ancestors', computed here since theme
            // templates only ever receive curated $vars.
            'archive_category' => $category,
            'archive_category_ancestors' => $this->categories->ancestors($category->id),
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
            'comment_counts' => $this->commentCountsFor($pagination['posts']),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function tag(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $tag = $slug !== '' ? $this->tags->findBySlug($slug) : null;

        if ($tag === null) {
            $this->notFound();

            return;
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginateByTag($tag->id, $page, $this->postsPerPage());

        $this->markCacheableForGuests(['posts', 'tag_' . $tag->id]);
        $this->theme->render('archive.php', [
            'page_title' => $tag->name,
            'archive_type' => 'tag',
            'archive_description' => $tag->description,
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
            'comment_counts' => $this->commentCountsFor($pagination['posts']),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function archive(array $params): void
    {
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginatePublished($page, $this->postsPerPage());

        $this->markCacheableForGuests(['posts']);
        $this->theme->render('archive.php', [
            'page_title' => 'Archive',
            'archive_type' => 'date',
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
            'comment_counts' => $this->commentCountsFor($pagination['posts']),
        ]);
    }

    /**
     * Posts published in a given calendar month, behind the Archives widget's monthly links.
     *
     * @param array<string, string> $params
     */
    public function archiveByMonth(array $params): void
    {
        $year = (int) ($params['year'] ?? 0);
        $month = (int) ($params['month'] ?? 0);

        if ($year < 1000 || $year > 9999 || $month < 1 || $month > 12) {
            $this->notFound();

            return;
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginateByMonth($year, $month, $page, $this->postsPerPage());
        $monthName = (new DateTimeImmutable())->setDate($year, $month, 1)->format('F Y');

        $this->markCacheableForGuests(['posts']);
        $this->theme->render('archive.php', [
            'page_title' => $monthName,
            'archive_type' => 'date',
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
            'comment_counts' => $this->commentCountsFor($pagination['posts']),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function search(array $params): void
    {
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $results = $this->search->search($query, $page);

        $this->markCacheableForGuests(['search']);
        $this->theme->render('search.php', [
            'page_title' => 'Search',
            'query' => $results['query'],
            'results' => $results['results'],
            'pagination' => $results,
        ]);
    }

    /**
     * The hierarchical Page route — "/{path*}", registered last in
     * bootstrap.php's route table since a greedy placeholder would
     * otherwise shadow every fixed-pattern route after it.
     * $params['path'] is resolved segment by segment via
     * PageService::findByPath(), so a URL with a wrong ancestor chain
     * 404s instead of resolving by its final slug alone.
     *
     * @param array<string, string> $params
     */
    public function pageByPath(array $params): void
    {
        $path = trim((string) ($params['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $page = $segments !== [] ? $this->pages->findByPath($segments) : null;

        if ($page === null || !$page->isVisibleToViewer($this->canViewPrivatePage($page))) {
            $this->notFound();

            return;
        }

        // With a static homepage configured, the designated "Posts page" shows the latest-posts listing, not its own content.
        if ($this->isConfiguredPostsPage($page->id)) {
            $this->renderPostsListing($page->title);

            return;
        }

        $this->markCacheableForGuests(['page_' . $page->id]);
        $this->theme->render('page.php', [
            'page_title' => $page->title,
            'page' => $page,
            // Root-first ancestor chain, empty for a top-level page. Computed here since theme templates only ever receive curated $vars.
            'page_ancestors' => $this->pages->ancestors($page->id),
            'comment_data' => $this->commentTemplateDataForPage($page, $this->auth->user()),
        ]);
    }

    /**
     * The legacy flat "/page/{slug}" URL — kept as a permanent redirect
     * to the page's real hierarchical URL so old indexed/bookmarked
     * links keep working. A bare slug lookup suffices since slugs stay
     * globally unique regardless of nesting depth.
     *
     * @param array<string, string> $params
     */
    public function legacyPageRedirect(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $page = $slug !== '' ? $this->pages->findBySlug($slug) : null;

        if ($page === null || !$page->isVisibleToViewer($this->canViewPrivatePage($page))) {
            $this->notFound();

            return;
        }

        header('Location: ' . page_permalink($page), true, 301);
    }

    /**
     * Site-wide feed of published posts. `/feed` and `/feed/rss` serve
     * RSS 2.0; `/feed/atom` serves Atom 1.0. Bypasses ThemeRenderer
     * entirely and sets caching headers itself (Last-Modified/ETag/
     * Cache-Control, with conditional GET support).
     *
     * @param array<string, string> $params
     */
    public function feed(array $params): void
    {
        if ($this->config->option('feeds_enabled', '1') === '0') {
            $this->notFound();

            return;
        }

        $format = ($params['format'] ?? '') === 'atom' ? 'atom' : 'rss';

        $this->emitFeed(
            $format,
            $this->feeds->channel(),
            $this->feeds->items(),
            home_url(),
            home_url('feed/atom'),
            'site|' . $this->feeds->itemLimit(),
        );
    }

    /**
     * Category-scoped counterpart of feed(), reusing the same
     * FeedService/emitFeed() plumbing — only the channel, item source,
     * and links differ. 404s on an unknown category rather than falling
     * back to the site-wide feed. Unlike category() itself, a
     * missing/wrong ancestor prefix here just 404s rather than
     * redirecting — a feed URL has no SEO indexing to preserve the way
     * the archive page does.
     *
     * @param array<string, string> $params
     */
    public function categoryFeed(array $params): void
    {
        if ($this->config->option('feeds_enabled', '1') === '0') {
            $this->notFound();

            return;
        }

        $path = trim((string) ($params['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $category = $segments !== [] ? $this->categories->findByPath($segments) : null;

        if ($category === null) {
            $this->notFound();

            return;
        }

        $format = ($params['format'] ?? '') === 'atom' ? 'atom' : 'rss';

        $this->emitFeed(
            $format,
            $this->feeds->categoryChannel($category),
            $this->feeds->categoryItems($category),
            $this->permalinks->categoryUrl($category),
            $this->permalinks->categoryFeedUrl($category, 'atom'),
            'category_' . $category->id . '|' . $this->feeds->itemLimit(),
        );
    }

    /**
     * Tag-scoped counterpart of feed(), reusing the same FeedService/
     * emitFeed() plumbing as categoryFeed() — only the channel, item
     * source, and links differ. 404s on an unknown tag.
     *
     * @param array<string, string> $params
     */
    public function tagFeed(array $params): void
    {
        if ($this->config->option('feeds_enabled', '1') === '0') {
            $this->notFound();

            return;
        }

        $slug = $params['slug'] ?? '';
        $tag = $slug !== '' ? $this->tags->findBySlug($slug) : null;

        if ($tag === null) {
            $this->notFound();

            return;
        }

        $format = ($params['format'] ?? '') === 'atom' ? 'atom' : 'rss';

        $this->emitFeed(
            $format,
            $this->feeds->tagChannel($tag),
            $this->feeds->tagItems($tag),
            $this->permalinks->tagUrl($tag),
            $this->permalinks->tagFeedUrl($tag, 'atom'),
            'tag_' . $tag->id . '|' . $this->feeds->itemLimit(),
        );
    }

    /**
     * Author-scoped counterpart of feed(), reusing the same FeedService/
     * emitFeed() plumbing as categoryFeed()/tagFeed(). Mirrors author()'s
     * own enumeration-hardening: an unknown slug or a real author with zero
     * published posts both 404 via the same 'lumora_shield_enumeration_blocked'
     * action and 'lumora_shield_author_archive_visible' filter author()
     * uses, so this route can't be used to enumerate usernames a plugin
     * has otherwise closed off on the archive page itself.
     *
     * @param array<string, string> $params
     */
    public function authorFeed(array $params): void
    {
        if ($this->config->option('feeds_enabled', '1') === '0') {
            $this->notFound();

            return;
        }

        $slug = $params['slug'] ?? '';
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $author = $slug !== '' ? $this->users->findByAuthorSlug($slug) : null;

        if ($author === null) {
            do_action('lumora_shield_enumeration_blocked', $slug, 'unknown_user', $ipAddress);
            $this->notFound();

            return;
        }

        $totalPublished = $this->posts->paginateByAuthor($author->id, 1, 1)['total'];

        if ($totalPublished === 0) {
            do_action('lumora_shield_enumeration_blocked', $slug, 'zero_posts', $ipAddress);
            $this->notFound();

            return;
        }

        if (!apply_filters('lumora_shield_author_archive_visible', true, $author, $totalPublished)) {
            do_action('lumora_shield_enumeration_blocked', $slug, 'hidden_by_setting', $ipAddress);
            $this->notFound();

            return;
        }

        $format = ($params['format'] ?? '') === 'atom' ? 'atom' : 'rss';

        $this->emitFeed(
            $format,
            $this->feeds->authorChannel($author),
            $this->feeds->authorItems($author),
            home_url('author/' . $slug),
            home_url('author/' . $slug . '/feed/atom'),
            'author_' . $author->id . '|' . $this->feeds->itemLimit(),
        );
    }

    /**
     * Shared caching/conditional-GET/rendering plumbing for feed() and categoryFeed().
     *
     * @param array{title: string, description: string} $channel
     * @param array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}> $items
     */
    private function emitFeed(string $format, array $channel, array $items, string $channelLink, string $selfLink, string $etagSeed): void
    {
        $lastModified = null;

        foreach ($items as $item) {
            $post = $item['post'];
            $candidate = $post->updatedAt > ($post->publishedAt ?? $post->updatedAt) ? $post->updatedAt : $post->publishedAt;

            if ($candidate !== null && ($lastModified === null || $candidate > $lastModified)) {
                $lastModified = $candidate;
            }
        }

        $etag = '"' . md5($format . '|' . $etagSeed . '|' . ($lastModified?->format('c') ?? '')) . '"';

        $ifNoneMatch = is_string($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : null;

        if ($ifNoneMatch === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);

            return;
        }

        $cacheLifetime = max(0, (int) $this->config->option('feed_cache_lifetime', '900'));

        header('Content-Type: ' . ($format === 'atom' ? 'application/atom+xml' : 'application/rss+xml') . '; charset=UTF-8');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=' . $cacheLifetime);

        if ($lastModified !== null) {
            header('Last-Modified: ' . $lastModified->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT');
        }

        echo $format === 'atom'
            ? $this->renderAtom($channel, $items, $channelLink, $selfLink)
            : $this->renderRss2($channel, $items, $channelLink);

        do_action('feed_generated', $format);
    }

    /**
     * @param array{title: string, description: string} $channel
     * @param array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}> $items
     */
    private function renderRss2(array $channel, array $items, string $channelLink): string
    {
        $now = new DateTimeImmutable();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . esc_html($channel['title']) . '</title>' . "\n";
        $xml .= '<link>' . esc_url($channelLink) . '</link>' . "\n";
        $xml .= '<description>' . esc_html($channel['description']) . '</description>' . "\n";
        $xml .= '<language>en</language>' . "\n";
        $xml .= '<lastBuildDate>' . $now->format('r') . '</lastBuildDate>' . "\n";

        foreach ($items as $item) {
            $post = $item['post'];
            $link = post_permalink($post);

            $xml .= '<item>' . "\n";
            $xml .= '<title>' . esc_html($post->title) . '</title>' . "\n";
            $xml .= '<link>' . esc_url($link) . '</link>' . "\n";
            $xml .= '<guid isPermaLink="true">' . esc_url($link) . '</guid>' . "\n";
            $xml .= '<description>' . esc_html($item['description']) . '</description>' . "\n";

            if ($item['content'] !== null) {
                $xml .= '<content:encoded>' . esc_html($item['content']) . '</content:encoded>' . "\n";
            }

            if ($item['authorName'] !== null) {
                $xml .= '<dc:creator>' . esc_html($item['authorName']) . '</dc:creator>' . "\n";
            }

            if ($post->publishedAt !== null) {
                $xml .= '<pubDate>' . $post->publishedAt->format('r') . '</pubDate>' . "\n";
            }

            if (($item['thumbnailUrl'] ?? null) !== null) {
                $xml .= '<enclosure url="' . esc_url($item['thumbnailUrl'])
                    . '" length="' . (int) ($item['thumbnailLength'] ?? 0)
                    . '" type="' . esc_attr((string) ($item['thumbnailType'] ?? 'image/jpeg')) . '"/>' . "\n";
            }

            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    /**
     * @param array{title: string, description: string} $channel
     * @param array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}> $items
     */
    private function renderAtom(array $channel, array $items, string $channelLink, string $selfLink): string
    {
        $now = new DateTimeImmutable();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '<title>' . esc_html($channel['title']) . '</title>' . "\n";
        $xml .= '<subtitle>' . esc_html($channel['description']) . '</subtitle>' . "\n";
        $xml .= '<id>' . esc_url($channelLink) . '</id>' . "\n";
        $xml .= '<link rel="self" href="' . esc_url($selfLink) . '"/>' . "\n";
        $xml .= '<link rel="alternate" href="' . esc_url($channelLink) . '"/>' . "\n";
        $xml .= '<updated>' . $now->format('c') . '</updated>' . "\n";

        foreach ($items as $item) {
            $post = $item['post'];
            $link = post_permalink($post);
            $updated = $post->updatedAt > $post->createdAt ? $post->updatedAt : ($post->publishedAt ?? $post->createdAt);

            $xml .= '<entry>' . "\n";
            $xml .= '<title>' . esc_html($post->title) . '</title>' . "\n";
            $xml .= '<link rel="alternate" href="' . esc_url($link) . '"/>' . "\n";
            $xml .= '<id>' . esc_url($link) . '</id>' . "\n";
            $xml .= '<updated>' . $updated->format('c') . '</updated>' . "\n";

            if ($post->publishedAt !== null) {
                $xml .= '<published>' . $post->publishedAt->format('c') . '</published>' . "\n";
            }

            if ($item['authorName'] !== null) {
                $xml .= '<author><name>' . esc_html($item['authorName']) . '</name></author>' . "\n";
            }

            $xml .= '<summary>' . esc_html($item['description']) . '</summary>' . "\n";

            if ($item['content'] !== null) {
                $xml .= '<content type="html">' . esc_html($item['content']) . '</content>' . "\n";
            }

            if (($item['thumbnailUrl'] ?? null) !== null) {
                $xml .= '<link rel="enclosure" href="' . esc_url($item['thumbnailUrl'])
                    . '" type="' . esc_attr((string) ($item['thumbnailType'] ?? 'image/jpeg')) . '"/>' . "\n";
            }

            $xml .= '</entry>' . "\n";
        }

        $xml .= '</feed>' . "\n";

        return $xml;
    }

    /**
     * Virtual /robots.txt (Settings > Reading > Search Engine
     * Visibility) — no physical file exists, so this route answers the
     * request directly: a blanket Disallow when discouraged, otherwise
     * just the admin area kept out of search results.
     *
     * @param array<string, string> $params
     */
    public function robotsTxt(array $params): void
    {
        header('Content-Type: text/plain; charset=UTF-8');

        if ($this->config->option('discourage_search_engines', '0') === '1') {
            echo "User-agent: *\nDisallow: /\n";

            return;
        }

        echo "User-agent: *\n";
        echo 'Disallow: ' . site_url('admin') . "\n";
        echo 'Sitemap: ' . home_url('sitemap.xml') . "\n";
    }

    /**
     * Explicit "download this file" link target — counts a download for
     * document/archive/audio/video media, then streams the file rather
     * than redirecting (a redirect would expose the real
     * `content/uploads/...` path, defeating this route's masking).
     * Images pass through uncounted: tracking every `<img>` view would
     * require routing all image requests through PHP.
     *
     * @param array<string, string> $params
     */
    public function mediaDownload(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $item = $id > 0 ? $this->media->find($id) : null;

        if ($item === null) {
            $this->notFound();

            return;
        }

        $isImage = str_starts_with((string) $item['mime_type'], 'image/');

        if (!$isImage && $this->config->option('media_track_downloads', '1') !== '0') {
            $this->mediaStats->recordDownload($id);
        }

        if (!$this->media->stream($item, inline: false)) {
            $this->notFound();

            return;
        }

        exit;
    }

    /**
     * Masked inline-preview counterpart to mediaDownload() — streams
     * with `Content-Disposition: inline` instead of `attachment`. Images
     * only; a non-image file has no inline-preview use case and would
     * just duplicate mediaDownload()'s job.
     *
     * @param array<string, string> $params
     */
    public function mediaView(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $item = $id > 0 ? $this->media->find($id) : null;

        if ($item === null || !str_starts_with((string) $item['mime_type'], 'image/')) {
            $this->notFound();

            return;
        }

        if (!$this->media->stream($item, inline: true)) {
            $this->notFound();

            return;
        }

        exit;
    }

    /**
     * Checks for an admin-configured redirect before answering 404,
     * using the same path normalization as canonical_url().
     */
    public function notFound(): void
    {
        $requestPath = (string) (parse_url(BasePath::stripFrom($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $redirect = $this->redirects->findBySourcePath($requestPath);

        if ($redirect !== null) {
            $this->redirects->recordHit((int) $redirect['id']);
            header('Location: ' . (string) $redirect['target_url'], true, (int) $redirect['status_code']);
            exit;
        }

        http_response_code(404);
        $this->theme->render('404.php', ['page_title' => 'Page Not Found']);
    }

    /**
     * XML sitemap (sitemaps.org protocol) — every published post/page
     * plus every category/tag archive URL. Single flat file capped at
     * the protocol's 50,000-URL limit; not run through CacheManager.
     *
     * @param array<string, string> $params
     */
    public function sitemap(array $params): void
    {
        header('Content-Type: application/xml; charset=UTF-8');

        $urls = [['loc' => home_url(), 'lastmod' => null]];

        foreach ($this->posts->paginatePublished(1, 50000)['posts'] as $sitemapPost) {
            $urls[] = ['loc' => post_permalink($sitemapPost), 'lastmod' => $sitemapPost->updatedAt];
        }

        foreach ($this->pages->paginatePublished(1, 50000)['pages'] as $sitemapPage) {
            $urls[] = ['loc' => page_permalink($sitemapPage), 'lastmod' => $sitemapPage->updatedAt];
        }

        foreach ($this->categories->listAll() as $category) {
            $urls[] = ['loc' => category_permalink($category), 'lastmod' => null];
        }

        foreach ($this->tags->listAll() as $tag) {
            $urls[] = ['loc' => tag_permalink($tag), 'lastmod' => null];
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            echo '<url>' . "\n";
            echo '<loc>' . esc_url((string) $url['loc']) . '</loc>' . "\n";

            if ($url['lastmod'] instanceof DateTimeImmutable) {
                echo '<lastmod>' . $url['lastmod']->format('c') . '</lastmod>' . "\n";
            }

            echo '</url>' . "\n";
        }

        echo '</urlset>' . "\n";
    }
}
