<?php

declare(strict_types=1);

namespace LumoraPress\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Auth;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Theme\ThemeRenderer;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\Post;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\FeedService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\SearchService;
use LumoraPress\Services\TagService;

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
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function home(array $params): void
    {
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $pagination = $this->posts->paginatePublished($page);

        $this->theme->render('index.php', [
            'page_title' => null,
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function singlePost(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $post = $slug !== '' ? $this->posts->findBySlug($slug) : null;

        if ($post === null || !$post->isPubliclyVisible()) {
            $this->notFound();

            return;
        }

        $this->theme->render('single.php', [
            'page_title' => $post->title,
            'post' => $post,
            'comments_open' => $this->commentsOpenFor($post),
            'comment_tree' => $this->comments->publicTreeForPost($post->id),
            'comment_count' => $this->comments->countForPost($post->id),
            'current_user' => $this->auth->user(),
        ]);
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

        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        if ($this->comments->recentCommentFromIpExists($ipAddress, self::COMMENT_FLOOD_WINDOW_SECONDS)) {
            header('Location: ' . $redirectTo . '?comment=flood#comment-form');
            exit;
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        $parentId = $rawParentId > 0 ? $rawParentId : null;

        $authUser = $this->auth->user();
        $userId = $authUser?->id;
        $guestName = $authUser !== null ? $authUser->displayName : trim((string) ($_POST['guest_name'] ?? ''));
        $guestEmail = $authUser !== null ? $authUser->email : trim((string) ($_POST['guest_email'] ?? ''));
        $guestUrl = trim((string) ($_POST['guest_url'] ?? ''));
        $guestUrl = $guestUrl !== '' && filter_var($guestUrl, FILTER_VALIDATE_URL) !== false ? $guestUrl : null;

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

        $status = ($authUser?->can('moderate_comments') ?? false)
            || $this->comments->hasPreviouslyApprovedComment($userId, $guestEmail)
            ? CommentStatus::Approved
            : CommentStatus::Pending;

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

        $flag = $status === CommentStatus::Approved ? 'posted' : 'pending';
        $anchor = $status === CommentStatus::Approved ? '#comment-' . $comment->id : '#comment-form';

        header('Location: ' . $redirectTo . '?comment=' . $flag . $anchor);
        exit;
    }

    /**
     * A post accepts comments only if both the post itself and the site
     * as a whole allow it. Stored as the literal strings '1'/'0' (see
     * CommentService's own docblock convention) rather than a PHP bool,
     * since PressConfig::setOption() casts `false` to '' — an easy
     * footgun for a "!== '0'" style default-true check.
     */
    private function commentsOpenFor(Post $post): bool
    {
        return $post->commentsOpen && $this->config->option('comments_enabled', '1') !== '0';
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
        $pagination = $this->posts->paginateByCategory($category->id, $page);

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
        $pagination = $this->posts->paginateByTag($tag->id, $page);

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
        $pagination = $this->posts->paginatePublished($page);

        $this->theme->render('archive.php', [
            'page_title' => 'Archive',
            'archive_type' => 'date',
            'posts' => $pagination['posts'],
            'pagination' => $pagination,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    /**
     * @param array<string, string> $params
     */
    public function search(array $params): void
    {
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $results = $this->search->search($query, $page);

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
     * @param array<int, array{post: Post, authorName: ?string, description: string, content: ?string}> $items
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

            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    /**
     * @param array{title: string, description: string} $channel
     * @param array<int, array{post: Post, authorName: ?string, description: string, content: ?string}> $items
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

            $xml .= '</entry>' . "\n";
        }

        $xml .= '</feed>' . "\n";

        return $xml;
    }

    public function notFound(): void
    {
        http_response_code(404);
        $this->theme->render('404.php', ['page_title' => 'Page Not Found']);
    }
}
