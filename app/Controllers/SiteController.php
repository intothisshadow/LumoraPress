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
use LumoraPress\Models\Post;
use LumoraPress\Services\AkismetClient;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentModerationService;
use LumoraPress\Services\CommentNotificationService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\FeedService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\MediaStatsService;
use LumoraPress\Services\PageService;
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
     * basic flood control (LP-012). Not user-configurable yet.
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
                ]);

                return;
            }
        }

        $this->renderPostsListing(null);
    }

    /**
     * The configured "Blog pages show at most" size (LP-046 Reading
     * settings), shared by the homepage post listing and every archive
     * view (category/tag/date/month) below — mirrors how feed_item_limit
     * already centralizes the equivalent RSS/Atom setting in FeedService.
     */
    private function postsPerPage(): int
    {
        return max(1, (int) $this->config->option('posts_per_page', '10'));
    }

    /**
     * Opts the current response into HTTP caching (LP-037) — only for a
     * guest; a logged-in visitor's response is never marked cacheable, so
     * they always get a fresh render (and never risk being served
     * another visitor's cached copy of an admin-bar-less guest page, or
     * vice versa). Every route that calls this renders identically for
     * every guest with the same URL — no embedded CSRF form, no
     * per-visitor content — see CacheManager::markPageCacheable()'s
     * docblock for why singlePost() deliberately never does.
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
        ]);
    }

    /**
     * Whether $pageId is the configured "Posts page" (LP-046) — the page
     * whose own URL shows the latest-posts listing instead of that page's
     * stored content, the same "static homepage + separate posts page"
     * combination WordPress's Reading settings offer. Only meaningful
     * while a static homepage is configured; with the default "latest
     * posts" homepage there's no separate posts page to redirect to.
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

        $this->theme->render('single.php', [
            'page_title' => $post->title,
            'post' => $post,
            'comments_open' => $this->commentsOpenFor($post),
            ...$this->commentThreadViewData($post),
            'current_user' => $this->auth->user(),
        ]);
    }

    /**
     * View data for the comment thread shared by singlePost() and
     * previewPost() — pagination, ordering, threading, and avatar display
     * are all Settings > Discussion (LP-047) options rather than hardcoded
     * as publicTreeForPost() always was before this ticket.
     *
     * @return array<string, mixed>
     */
    private function commentThreadViewData(Post $post): array
    {
        $order = (string) $this->config->option('comment_order', 'asc');
        $threaded = $this->config->option('comment_threading_enabled', '1') !== '0';
        $maxNesting = max(0, (int) $this->config->option('comment_max_nesting_level', '5'));

        if ($this->config->option('comment_pagination_enabled', '0') !== '1') {
            // Pagination disabled: one "page" holding every comment on the
            // post. 100000 comfortably exceeds any realistic thread size
            // while keeping paginateForPost()'s single query/slice path.
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

        $redirectTo = home_url('post/' . $post->slug);

        if (!$this->commentsOpenFor($post)) {
            header('Location: ' . $redirectTo . '#comments');
            exit;
        }

        // The submitted parent_id also selects which form's CSRF token to
        // check against — comments.php gives the top-level form and each
        // reply form (one per visible comment) their own action name for
        // exactly this reason (see that file's comment for why a single
        // shared name would leave every form but the last-rendered one
        // with an already-invalidated token).
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

        // Submission timing (LP-025): a real visitor takes at least a few
        // seconds to fill in the form, so an instant submission is a bot
        // signal. Same "fail silently" treatment as the honeypot above.
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

        // "Require user registration before commenting" (LP-047) — checked
        // before touching any submitted guest fields, since a guest who
        // hits this has nothing else worth validating.
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

        // "Comment author name/email required" (LP-047): when a guest
        // field isn't required, a blank value is filled with a placeholder
        // rather than left empty — guest_name/guest_email are NOT NULL
        // columns (every existing admin/API comment list assumes a
        // displayable name and a syntactically valid email is always
        // present), so "not required" means "don't force the visitor to
        // type one," not "store nothing."
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

        // Akismet (LP-025), when enabled, can only push a comment toward
        // Spam — never away from it — so this is strictly additive on top
        // of the trust-signal decision above. A null result (Akismet
        // unreachable/misconfigured) leaves that decision untouched. A
        // future spam-detection plugin (e.g. Lumora Shield) can hook the
        // same 'comment_is_spam' filter (LP-047) to apply the same rule.
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

        // "Enable comment cookies consent" (LP-047) — a guest who checked
        // "Save my name/email in this browser" gets those fields
        // pre-filled next time; unchecking (or the site-wide setting being
        // off) never writes/clears anything the visitor didn't ask for.
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
     * A post accepts comments only if the post itself and the site as a
     * whole allow it, and — Settings > Discussion's "Automatically close
     * comments after N days" — the post isn't past that window. Delegates
     * to CommentModerationService (LP-047) so SiteController and
     * ApiController share one answer.
     */
    private function commentsOpenFor(Post $post): bool
    {
        return $this->commentModeration->commentsOpenFor($post);
    }

    /**
     * LP-008's "Private posts" — whether the current visitor is permitted
     * to see $post even though it's Private: logged in, and either the
     * post's own author or holding edit_posts (Editor/Administrator/
     * Author/Contributor all qualify, matching who can already see a
     * draft they didn't write via the admin list's own edit_others_posts
     * gate).
     */
    private function canViewPrivatePost(Post $post): bool
    {
        $user = $this->auth->user();

        return $user !== null && ($user->can('edit_posts') || $user->id === $post->authorId);
    }

    /**
     * LP-008's "Preview button" — lets an author/editor view a post
     * exactly as it will render publicly (single.php, comments and all)
     * without publishing it and without any other visitor ever being
     * able to reach it, since this bypasses isVisibleToViewer() entirely
     * rather than issuing a shareable signed URL. Gated by the same
     * ownership rule admin/views/posts/new.php already applies to editing
     * a post at all (edit_others_posts, or being the post's own author).
     * Deliberately never cached (see singlePost()'s own docblock note —
     * neither method calls markCacheableForGuests()).
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
            'comments_open' => $this->commentsOpenFor($post),
            ...$this->commentThreadViewData($post),
            'current_user' => $user,
        ]);
    }

    /**
     * LP-008's public author archives (`/author/{slug}`) — the slug is
     * computed from the user's username (UserService::findByAuthorSlug()),
     * not a stored column; see that method's docblock.
     *
     * @param array<string, string> $params
     */
    public function author(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $author = $slug !== '' ? $this->users->findByAuthorSlug($slug) : null;

        if ($author === null) {
            $this->notFound();

            return;
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginateByAuthor($author->id, $page, $this->postsPerPage());

        $this->markCacheableForGuests(['posts', 'author_' . $author->id]);
        $this->theme->render('archive.php', [
            'page_title' => $author->displayName,
            'archive_type' => 'author',
            'archive_description' => null,
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function category(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $category = $slug !== '' ? $this->categories->findBySlug($slug) : null;

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
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
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
        ]);
    }

    /**
     * Posts published in a given calendar month (LP-048, behind the
     * Archives widget's monthly links). Reuses archive.php, same as the
     * plain date archive above and category()/tag() below.
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
     * @param array<string, string> $params
     */
    public function page(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $page = $slug !== '' ? $this->pages->findBySlug($slug) : null;

        if ($page === null || !$page->isPubliclyVisible()) {
            $this->notFound();

            return;
        }

        // LP-046: with a static homepage configured, the designated
        // "Posts page" shows the latest-posts listing at its own URL
        // instead of its own stored content — mirroring how visiting that
        // same page ID as the homepage already renders it as a static
        // page above, this is the other half of that pairing.
        if ($this->isConfiguredPostsPage($page->id)) {
            $this->renderPostsListing($page->title);

            return;
        }

        $this->markCacheableForGuests(['page_' . $page->id]);
        $this->theme->render('page.php', [
            'page_title' => $page->title,
            'page' => $page,
        ]);
    }

    /**
     * Site-wide feed of published posts (LP-013). `/feed` and `/feed/rss`
     * serve RSS 2.0; `/feed/atom` serves Atom 1.0. Bypasses ThemeRenderer
     * entirely — feeds are XML, not a themed HTML page — and sets caching
     * headers itself (Last-Modified/ETag/Cache-Control, with conditional
     * GET support) since ThemeRenderer never touches response headers.
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
        $channel = $this->feeds->channel();
        $items = $this->feeds->items();

        $lastModified = null;

        foreach ($items as $item) {
            $post = $item['post'];
            $candidate = $post->updatedAt > ($post->publishedAt ?? $post->updatedAt) ? $post->updatedAt : $post->publishedAt;

            if ($candidate !== null && ($lastModified === null || $candidate > $lastModified)) {
                $lastModified = $candidate;
            }
        }

        $etag = '"' . md5($format . '|' . $this->feeds->itemLimit() . '|' . ($lastModified?->format('c') ?? '')) . '"';

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

        echo $format === 'atom' ? $this->renderAtom($channel, $items) : $this->renderRss2($channel, $items);

        do_action('feed_generated', $format);
    }

    /**
     * @param array{title: string, description: string} $channel
     * @param array<int, array{post: Post, authorName: ?string, description: string, content: ?string, thumbnailUrl: ?string, thumbnailType: ?string, thumbnailLength: ?int}> $items
     */
    private function renderRss2(array $channel, array $items): string
    {
        $now = new DateTimeImmutable();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . esc_html($channel['title']) . '</title>' . "\n";
        $xml .= '<link>' . esc_url(home_url()) . '</link>' . "\n";
        $xml .= '<description>' . esc_html($channel['description']) . '</description>' . "\n";
        $xml .= '<language>en</language>' . "\n";
        $xml .= '<lastBuildDate>' . $now->format('r') . '</lastBuildDate>' . "\n";

        foreach ($items as $item) {
            $post = $item['post'];
            $link = home_url('post/' . $post->slug);

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
    private function renderAtom(array $channel, array $items): string
    {
        $now = new DateTimeImmutable();
        $feedLink = home_url('feed/atom');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '<title>' . esc_html($channel['title']) . '</title>' . "\n";
        $xml .= '<subtitle>' . esc_html($channel['description']) . '</subtitle>' . "\n";
        $xml .= '<id>' . esc_url(home_url()) . '</id>' . "\n";
        $xml .= '<link rel="self" href="' . esc_url($feedLink) . '"/>' . "\n";
        $xml .= '<link rel="alternate" href="' . esc_url(home_url()) . '"/>' . "\n";
        $xml .= '<updated>' . $now->format('c') . '</updated>' . "\n";

        foreach ($items as $item) {
            $post = $item['post'];
            $link = home_url('post/' . $post->slug);
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
     * Virtual /robots.txt (LP-046 Reading settings > Search Engine
     * Visibility) — there's no physical robots.txt file in the app root
     * (see .htaccess's "serve existing files directly" rule), so this
     * route is what actually answers the request. Mirrors the two states
     * classic WordPress's own "Discourage search engines" option
     * produces: a blanket Disallow when discouraged, otherwise just the
     * admin area kept out of search results.
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
     * Explicit "download this file" link target (LP-006 Media Statistics)
     * — counts a download for document/archive/audio/video media, then
     * redirects to the real static file URL. Images pass through
     * uncounted: this ticket deliberately doesn't track image "views",
     * since every `<img>` on every page would otherwise need to route
     * through PHP to be countable (see MediaStatsService's docblock) —
     * this route only exists at all because a distinct, explicit download
     * click is a request PHP already gets to see.
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

        header('Location: ' . $this->media->url($item));
        exit;
    }

    /**
     * Checks for an admin-configured redirect (LP-022) before actually
     * answering 404 — the same request-path normalization
     * canonical_url() (include/helpers.php) uses, so a redirect saved
     * against "old-page" matches a request for "/old-page" regardless of
     * how it was entered.
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
     * XML sitemap (LP-022, sitemaps.org protocol) — every published
     * post/page plus every category/tag archive URL. Single flat file,
     * capped at the protocol's own 50,000-URL limit per sitemap; a large
     * blog beyond that would need splitting into a sitemap index, not
     * built here (see TODO.md's LP-022 entry). Not run through
     * CacheManager — infrequent crawler traffic, not worth the added
     * complexity for this first pass.
     *
     * @param array<string, string> $params
     */
    public function sitemap(array $params): void
    {
        header('Content-Type: application/xml; charset=UTF-8');

        $urls = [['loc' => home_url(), 'lastmod' => null]];

        foreach ($this->posts->paginatePublished(1, 50000)['posts'] as $sitemapPost) {
            $urls[] = ['loc' => home_url('post/' . $sitemapPost->slug), 'lastmod' => $sitemapPost->updatedAt];
        }

        foreach ($this->pages->paginatePublished(1, 50000)['pages'] as $sitemapPage) {
            $urls[] = ['loc' => home_url('page/' . $sitemapPage->slug), 'lastmod' => $sitemapPage->updatedAt];
        }

        foreach ($this->categories->listAll() as $category) {
            $urls[] = ['loc' => home_url('category/' . $category->slug), 'lastmod' => null];
        }

        foreach ($this->tags->listAll() as $tag) {
            $urls[] = ['loc' => home_url('tag/' . $tag->slug), 'lastmod' => null];
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
