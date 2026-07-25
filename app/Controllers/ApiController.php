<?php

declare(strict_types=1);

namespace LumoraPress\Controllers;

use Closure;
use DateTimeImmutable;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Http\ApiResponse;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\ApiTokenService;
use LumoraPress\Models\Category;
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\Tag;
use LumoraPress\Models\User;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\SearchService;
use LumoraPress\Services\TagService;

/**
 * REST API (LP-021), versioned under /api/v1. One class handling every
 * resource, the same "one big class per surface" shape SiteController
 * already uses for the public site — no controller-per-resource split
 * exists as a precedent to match instead.
 *
 * Reads (GET) are always public and only ever return published/approved
 * content, regardless of whether a bearer token is presented — there is
 * no "view your own drafts via the API" mode in this pass (see the
 * planning notes for why: it would add a second visibility model on top
 * of Post::isPubliclyVisible()/Page::isPubliclyVisible(), out of scope
 * here). Writes require a bearer token (Authorization: Bearer
 * {selector}:{validator}, resolved via ApiTokenService) and repeat the
 * exact same capability + ownership checks admin/views/posts.php and
 * pages.php already encode — canManagePost()/canDeletePost() (and their
 * Page equivalents) are deliberately small, pure, directly-testable
 * methods rather than being buried inside the HTTP-handling actions,
 * since SiteController itself has no unit tests (it leans on raw
 * superglobals/header() calls that make that impractical) — this class
 * is structured so its core logic doesn't have that problem.
 */
final class ApiController
{
    private const DEFAULT_PER_PAGE = 10;

    private readonly Closure $rawInput;

    /**
     * @param Closure(): string|null $rawInput Overrides reading the raw
     *     request body — defaults to `file_get_contents('php://input')`.
     *     Exists purely so tests can supply a JSON body without needing
     *     a real HTTP request, the same DI-for-testability pattern
     *     MediaService's $moveUploadedFile uses.
     */
    public function __construct(
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly CommentService $comments,
        private readonly SearchService $search,
        private readonly ApiTokenService $apiTokens,
        private readonly PressConfig $config,
        private readonly HookManager $hooks,
        ?Closure $rawInput = null,
    ) {
        $this->rawInput = $rawInput ?? static fn (): string|false => file_get_contents('php://input');
    }

    // =================================================================
    // Authentication / permissions — small, pure, directly testable.
    // =================================================================

    /**
     * Resolves an "Authorization: Bearer {token}" header value to its
     * owning User, or null if missing/malformed/invalid. Takes the raw
     * header string directly (not read from $_SERVER internally) so it's
     * trivially testable without faking superglobals.
     */
    public function resolveUser(?string $authorizationHeader): ?User
    {
        if ($authorizationHeader === null || !str_starts_with($authorizationHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorizationHeader, strlen('Bearer ')));

        return $token === '' ? null : $this->apiTokens->validate($token);
    }

    /**
     * Mirrors admin/views/posts.php's $canEditPost closure: edit_posts,
     * and either your own post or edit_others_posts.
     */
    public function canManagePost(User $user, Post $post): bool
    {
        return $user->can('edit_posts') && ($user->id === $post->authorId || $user->can('edit_others_posts'));
    }

    /**
     * Mirrors admin/views/posts.php's delete gate: delete_posts AND the
     * same ownership rule as canManagePost().
     */
    public function canDeletePost(User $user, Post $post): bool
    {
        return $user->can('delete_posts') && $this->canManagePost($user, $post);
    }

    /**
     * Pages reuse the Posts capabilities (no dedicated page capabilities
     * exist), mirroring admin/views/pages.php's identical convention.
     */
    public function canManagePage(User $user, Page $page): bool
    {
        return $user->can('edit_posts') && ($user->id === $page->authorId || $user->can('edit_others_posts'));
    }

    public function canDeletePage(User $user, Page $page): bool
    {
        return $user->can('delete_posts') && $this->canManagePage($user, $page);
    }

    /**
     * Whether $status may be set given $user's capabilities — mirrors
     * admin/views/posts.php's "Contributors can only ever save as a
     * draft" rule: publishing (Published or Scheduled) needs
     * publish_posts, Draft never needs any extra capability.
     */
    public function canSetStatus(User $user, PostStatus|PageStatus $status): bool
    {
        if ($status instanceof PostStatus && $status === PostStatus::Draft) {
            return true;
        }

        if ($status instanceof PageStatus && $status === PageStatus::Draft) {
            return true;
        }

        return $user->can('publish_posts');
    }

    // =================================================================
    // Resource -> array shaping — small, pure, directly testable.
    // =================================================================

    /**
     * @return array<string, mixed>
     */
    public function postToArray(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'content' => $post->content,
            'excerpt' => $post->excerpt,
            'status' => $post->status->value,
            'author_id' => $post->authorId,
            'featured_image_id' => $post->featuredImageId,
            'published_at' => $post->publishedAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $post->createdAt->format(DateTimeImmutable::ATOM),
            'updated_at' => $post->updatedAt->format(DateTimeImmutable::ATOM),
            'comments_open' => $post->commentsOpen,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pageToArray(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'content' => $page->content,
            'excerpt' => $page->excerpt,
            'status' => $page->status->value,
            'author_id' => $page->authorId,
            'parent_id' => $page->parentId,
            'featured_image_id' => $page->featuredImageId,
            'published_at' => $page->publishedAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $page->createdAt->format(DateTimeImmutable::ATOM),
            'updated_at' => $page->updatedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryToArray(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'parent_id' => $category->parentId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tagToArray(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commentToArray(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'post_id' => $comment->postId,
            'parent_id' => $comment->parentId,
            'author_name' => $comment->guestName,
            'content' => $comment->content,
            'status' => $comment->status->value,
            'created_at' => $comment->createdAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array{comment: Comment, children: array<mixed>} $node
     * @return array<string, mixed>
     */
    public function commentTreeNodeToArray(array $node): array
    {
        return [
            ...$this->commentToArray($node['comment']),
            'children' => array_map($this->commentTreeNodeToArray(...), $node['children']),
        ];
    }

    /**
     * @param array{total: int, page: int, perPage: int, totalPages: int} $paginated
     * @return array{page: int, perPage: int, total: int, totalPages: int}
     */
    public function paginationMeta(array $paginated): array
    {
        return [
            'page' => $paginated['page'],
            'perPage' => $paginated['perPage'],
            'total' => $paginated['total'],
            'totalPages' => $paginated['totalPages'],
        ];
    }

    // =================================================================
    // Posts
    // =================================================================

    /**
     * @param array<string, string> $params
     */
    public function postsIndex(array $params): void
    {
        if (!$this->gate('posts')) {
            return;
        }

        [$page, $perPage] = $this->pagingParams();
        $categorySlug = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
        $tagSlug = is_string($_GET['tag'] ?? null) ? $_GET['tag'] : '';
        $authorId = (int) ($_GET['author'] ?? 0);

        if ($categorySlug !== '') {
            $category = $this->categories->findBySlug($categorySlug);
            $result = $category !== null
                ? $this->posts->paginateByCategory($category->id, $page, $perPage)
                : ['posts' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage, 'totalPages' => 1];
        } elseif ($tagSlug !== '') {
            $tag = $this->tags->findBySlug($tagSlug);
            $result = $tag !== null
                ? $this->posts->paginateByTag($tag->id, $page, $perPage)
                : ['posts' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage, 'totalPages' => 1];
        } elseif ($authorId > 0) {
            $result = $this->posts->paginateByAuthor($authorId, $page, $perPage);
        } else {
            $result = $this->posts->paginatePublished($page, $perPage);
        }

        ApiResponse::json(array_map($this->postToArray(...), $result['posts']), 200, $this->paginationMeta($result));
    }

    /**
     * @param array<string, string> $params
     */
    public function postsShow(array $params): void
    {
        if (!$this->gate('posts')) {
            return;
        }

        $post = $this->posts->findBySlug($params['slug'] ?? '');

        if ($post === null || !$post->isPubliclyVisible()) {
            ApiResponse::error('Post not found.', 404);

            return;
        }

        ApiResponse::json($this->postToArray($post));
    }

    /**
     * @param array<string, string> $params
     */
    public function postsStore(array $params): void
    {
        if (!$this->gate('posts')) {
            return;
        }

        $user = $this->resolveUser($this->authorizationHeader());

        if ($user === null) {
            ApiResponse::error('A valid API token is required.', 401);

            return;
        }

        if (!$user->can('edit_posts')) {
            ApiResponse::error('You do not have permission to create posts.', 403);

            return;
        }

        $body = $this->requestBody();
        $title = trim((string) ($body['title'] ?? ''));
        $content = (string) ($body['content'] ?? '');

        if ($title === '' || $content === '') {
            ApiResponse::error('"title" and "content" are required.', 422);

            return;
        }

        $status = PostStatus::tryFrom((string) ($body['status'] ?? 'draft')) ?? PostStatus::Draft;

        if (!$this->canSetStatus($user, $status)) {
            $status = PostStatus::Draft;
        }

        $post = $this->posts->create(
            title: $title,
            content: $content,
            excerpt: (string) ($body['excerpt'] ?? ''),
            authorId: $user->id,
            status: $status,
            publishedAt: $this->parseDate($body['published_at'] ?? null),
            featuredImageId: isset($body['featured_image_id']) ? (int) $body['featured_image_id'] : null,
            slug: isset($body['slug']) ? (string) $body['slug'] : null,
            commentsOpen: (bool) ($body['comments_open'] ?? true),
        );

        $this->assignTaxonomies($post->id, $body);

        ApiResponse::json($this->postToArray($post), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function postsUpdate(array $params): void
    {
        if (!$this->gate('posts')) {
            return;
        }

        $existing = $this->posts->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Post not found.', 404);

            return;
        }

        $user = $this->resolveUser($this->authorizationHeader());

        if ($user === null) {
            ApiResponse::error('A valid API token is required.', 401);

            return;
        }

        if (!$this->canManagePost($user, $existing)) {
            ApiResponse::error('You do not have permission to edit this post.', 403);

            return;
        }

        $body = $this->requestBody();
        $status = isset($body['status'])
            ? (PostStatus::tryFrom((string) $body['status']) ?? $existing->status)
            : $existing->status;

        if (!$this->canSetStatus($user, $status)) {
            $status = PostStatus::Draft;
        }

        $post = $this->posts->update(
            id: $existing->id,
            title: isset($body['title']) ? (string) $body['title'] : $existing->title,
            content: isset($body['content']) ? (string) $body['content'] : $existing->content,
            excerpt: isset($body['excerpt']) ? (string) $body['excerpt'] : $existing->excerpt,
            status: $status,
            publishedAt: isset($body['published_at']) ? $this->parseDate($body['published_at']) : $existing->publishedAt,
            featuredImageId: array_key_exists('featured_image_id', $body) ? ($body['featured_image_id'] !== null ? (int) $body['featured_image_id'] : null) : $existing->featuredImageId,
            slug: isset($body['slug']) ? (string) $body['slug'] : $existing->slug,
            commentsOpen: (bool) ($body['comments_open'] ?? $existing->commentsOpen),
        );

        $this->assignTaxonomies($post->id, $body);

        ApiResponse::json($this->postToArray($post));
    }

    /**
     * @param array<string, string> $params
     */
    public function postsDestroy(array $params): void
    {
        if (!$this->gate('posts')) {
            return;
        }

        $existing = $this->posts->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Post not found.', 404);

            return;
        }

        $user = $this->resolveUser($this->authorizationHeader());

        if ($user === null) {
            ApiResponse::error('A valid API token is required.', 401);

            return;
        }

        if (!$this->canDeletePost($user, $existing)) {
            ApiResponse::error('You do not have permission to delete this post.', 403);

            return;
        }

        $this->posts->delete($existing->id);

        ApiResponse::json(null, 204);
    }

    // =================================================================
    // Pages
    // =================================================================

    /**
     * @param array<string, string> $params
     */
    public function pagesIndex(array $params): void
    {
        if (!$this->gate('pages')) {
            return;
        }

        [$page, $perPage] = $this->pagingParams();
        $result = $this->pages->paginatePublished($page, $perPage);

        ApiResponse::json(array_map($this->pageToArray(...), $result['pages']), 200, $this->paginationMeta($result));
    }

    /**
     * @param array<string, string> $params
     */
    public function pagesShow(array $params): void
    {
        if (!$this->gate('pages')) {
            return;
        }

        $page = $this->pages->findBySlug($params['slug'] ?? '');

        if ($page === null || !$page->isPubliclyVisible()) {
            ApiResponse::error('Page not found.', 404);

            return;
        }

        ApiResponse::json($this->pageToArray($page));
    }

    /**
     * @param array<string, string> $params
     */
    public function pagesStore(array $params): void
    {
        if (!$this->gate('pages')) {
            return;
        }

        $user = $this->resolveUser($this->authorizationHeader());

        if ($user === null) {
            ApiResponse::error('A valid API token is required.', 401);

            return;
        }

        if (!$user->can('edit_posts')) {
            ApiResponse::error('You do not have permission to create pages.', 403);

            return;
        }

        $body = $this->requestBody();
        $title = trim((string) ($body['title'] ?? ''));
        $content = (string) ($body['content'] ?? '');

        if ($title === '' || $content === '') {
            ApiResponse::error('"title" and "content" are required.', 422);

            return;
        }

        $status = PageStatus::tryFrom((string) ($body['status'] ?? 'draft')) ?? PageStatus::Draft;

        if (!$this->canSetStatus($user, $status)) {
            $status = PageStatus::Draft;
        }

        $page = $this->pages->create(
            title: $title,
            content: $content,
            excerpt: (string) ($body['excerpt'] ?? ''),
            authorId: $user->id,
            status: $status,
            publishedAt: $this->parseDate($body['published_at'] ?? null),
            parentId: isset($body['parent_id']) ? (int) $body['parent_id'] : null,
            featuredImageId: isset($body['featured_image_id']) ? (int) $body['featured_image_id'] : null,
            slug: isset($body['slug']) ? (string) $body['slug'] : null,
        );

        ApiResponse::json($this->pageToArray($page), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function pagesUpdate(array $params): void
    {
        if (!$this->gate('pages')) {
            return;
        }

        $existing = $this->pages->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Page not found.', 404);

            return;
        }

        $user = $this->resolveUser($this->authorizationHeader());

        if ($user === null) {
            ApiResponse::error('A valid API token is required.', 401);

            return;
        }

        if (!$this->canManagePage($user, $existing)) {
            ApiResponse::error('You do not have permission to edit this page.', 403);

            return;
        }

        $body = $this->requestBody();
        $status = isset($body['status'])
            ? (PageStatus::tryFrom((string) $body['status']) ?? $existing->status)
            : $existing->status;

        if (!$this->canSetStatus($user, $status)) {
            $status = PageStatus::Draft;
        }

        $page = $this->pages->update(
            id: $existing->id,
            title: isset($body['title']) ? (string) $body['title'] : $existing->title,
            content: isset($body['content']) ? (string) $body['content'] : $existing->content,
            excerpt: isset($body['excerpt']) ? (string) $body['excerpt'] : $existing->excerpt,
            status: $status,
            publishedAt: isset($body['published_at']) ? $this->parseDate($body['published_at']) : $existing->publishedAt,
            parentId: array_key_exists('parent_id', $body) ? ($body['parent_id'] !== null ? (int) $body['parent_id'] : null) : $existing->parentId,
            featuredImageId: array_key_exists('featured_image_id', $body) ? ($body['featured_image_id'] !== null ? (int) $body['featured_image_id'] : null) : $existing->featuredImageId,
            slug: isset($body['slug']) ? (string) $body['slug'] : $existing->slug,
        );

        ApiResponse::json($this->pageToArray($page));
    }

    /**
     * @param array<string, string> $params
     */
    public function pagesDestroy(array $params): void
    {
        if (!$this->gate('pages')) {
            return;
        }

        $existing = $this->pages->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Page not found.', 404);

            return;
        }

        $user = $this->resolveUser($this->authorizationHeader());

        if ($user === null) {
            ApiResponse::error('A valid API token is required.', 401);

            return;
        }

        if (!$this->canDeletePage($user, $existing)) {
            ApiResponse::error('You do not have permission to delete this page.', 403);

            return;
        }

        $this->pages->delete($existing->id);

        ApiResponse::json(null, 204);
    }

    // =================================================================
    // Categories
    // =================================================================

    /**
     * @param array<string, string> $params
     */
    public function categoriesIndex(array $params): void
    {
        if (!$this->gate('categories')) {
            return;
        }

        ApiResponse::json(array_map($this->categoryToArray(...), $this->categories->listAll()));
    }

    /**
     * @param array<string, string> $params
     */
    public function categoriesShow(array $params): void
    {
        if (!$this->gate('categories')) {
            return;
        }

        $category = $this->categories->findBySlug($params['slug'] ?? '');

        if ($category === null) {
            ApiResponse::error('Category not found.', 404);

            return;
        }

        ApiResponse::json($this->categoryToArray($category));
    }

    /**
     * @param array<string, string> $params
     */
    public function categoriesStore(array $params): void
    {
        if (!$this->gate('categories')) {
            return;
        }

        $user = $this->requireCapability('edit_posts');

        if (!$user instanceof User) {
            return;
        }

        $body = $this->requestBody();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            ApiResponse::error('"name" is required.', 422);

            return;
        }

        $category = $this->categories->create(
            name: $name,
            description: (string) ($body['description'] ?? ''),
            parentId: isset($body['parent_id']) ? (int) $body['parent_id'] : null,
            slug: isset($body['slug']) ? (string) $body['slug'] : null,
        );

        ApiResponse::json($this->categoryToArray($category), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function categoriesUpdate(array $params): void
    {
        if (!$this->gate('categories')) {
            return;
        }

        $existing = $this->categories->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Category not found.', 404);

            return;
        }

        $user = $this->requireCapability('edit_posts');

        if (!$user instanceof User) {
            return;
        }

        $body = $this->requestBody();

        $category = $this->categories->update(
            id: $existing->id,
            name: isset($body['name']) ? (string) $body['name'] : $existing->name,
            description: isset($body['description']) ? (string) $body['description'] : $existing->description,
            parentId: array_key_exists('parent_id', $body) ? ($body['parent_id'] !== null ? (int) $body['parent_id'] : null) : $existing->parentId,
            slug: isset($body['slug']) ? (string) $body['slug'] : $existing->slug,
        );

        ApiResponse::json($this->categoryToArray($category));
    }

    /**
     * @param array<string, string> $params
     */
    public function categoriesDestroy(array $params): void
    {
        if (!$this->gate('categories')) {
            return;
        }

        $existing = $this->categories->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Category not found.', 404);

            return;
        }

        $user = $this->requireCapability('delete_posts');

        if (!$user instanceof User) {
            return;
        }

        $this->categories->delete($existing->id);

        ApiResponse::json(null, 204);
    }

    // =================================================================
    // Tags
    // =================================================================

    /**
     * @param array<string, string> $params
     */
    public function tagsIndex(array $params): void
    {
        if (!$this->gate('tags')) {
            return;
        }

        ApiResponse::json(array_map($this->tagToArray(...), $this->tags->listAll()));
    }

    /**
     * @param array<string, string> $params
     */
    public function tagsShow(array $params): void
    {
        if (!$this->gate('tags')) {
            return;
        }

        $tag = $this->tags->findBySlug($params['slug'] ?? '');

        if ($tag === null) {
            ApiResponse::error('Tag not found.', 404);

            return;
        }

        ApiResponse::json($this->tagToArray($tag));
    }

    /**
     * @param array<string, string> $params
     */
    public function tagsStore(array $params): void
    {
        if (!$this->gate('tags')) {
            return;
        }

        $user = $this->requireCapability('edit_posts');

        if (!$user instanceof User) {
            return;
        }

        $body = $this->requestBody();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            ApiResponse::error('"name" is required.', 422);

            return;
        }

        $tag = $this->tags->create(
            name: $name,
            description: (string) ($body['description'] ?? ''),
            slug: isset($body['slug']) ? (string) $body['slug'] : null,
        );

        ApiResponse::json($this->tagToArray($tag), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function tagsUpdate(array $params): void
    {
        if (!$this->gate('tags')) {
            return;
        }

        $existing = $this->tags->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Tag not found.', 404);

            return;
        }

        $user = $this->requireCapability('edit_posts');

        if (!$user instanceof User) {
            return;
        }

        $body = $this->requestBody();

        $tag = $this->tags->update(
            id: $existing->id,
            name: isset($body['name']) ? (string) $body['name'] : $existing->name,
            description: isset($body['description']) ? (string) $body['description'] : $existing->description,
            slug: isset($body['slug']) ? (string) $body['slug'] : $existing->slug,
        );

        ApiResponse::json($this->tagToArray($tag));
    }

    /**
     * @param array<string, string> $params
     */
    public function tagsDestroy(array $params): void
    {
        if (!$this->gate('tags')) {
            return;
        }

        $existing = $this->tags->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Tag not found.', 404);

            return;
        }

        $user = $this->requireCapability('delete_posts');

        if (!$user instanceof User) {
            return;
        }

        $this->tags->delete($existing->id);

        ApiResponse::json(null, 204);
    }

    // =================================================================
    // Comments
    // =================================================================

    /**
     * @param array<string, string> $params
     */
    public function commentsIndex(array $params): void
    {
        if (!$this->gate('comments')) {
            return;
        }

        $postId = (int) ($_GET['post_id'] ?? 0);

        if ($postId <= 0) {
            ApiResponse::error('"post_id" is required.', 422);

            return;
        }

        $post = $this->posts->findById($postId);

        if ($post === null || !$post->isPubliclyVisible()) {
            ApiResponse::error('Post not found.', 404);

            return;
        }

        $tree = $this->comments->publicTreeForPost($postId);

        ApiResponse::json(array_map($this->commentTreeNodeToArray(...), $tree));
    }

    /**
     * Public write endpoint — mirrors SiteController::submitComment()'s
     * pending/auto-approve/flood-control rules exactly, minus the
     * honeypot/CSRF fields that only make sense for an HTML form (a
     * bearer-token-less API request has no ambient browser credential to
     * forge in the first place, and a scripted API client filling a
     * hidden field is not the threat honeypots defend against).
     *
     * @param array<string, string> $params
     */
    public function commentsStore(array $params): void
    {
        if (!$this->gate('comments')) {
            return;
        }

        $user = $this->resolveUser($this->authorizationHeader());

        // Distinct from the general "comments" resource toggle above: an
        // administrator can keep reading/moderating comments via the API
        // on while specifically blocking anonymous (no bearer token)
        // submissions — the closest analog to the original XML-RPC
        // ticket's "remote publishing" concern, since this is the one
        // write endpoint reachable with no token at all.
        if ($user === null && $this->config->option('rest_api_comments_public_submission_enabled', '1') === '0') {
            ApiResponse::error('Public comment submission via the API is disabled.', 403, 'public_submission_disabled');

            return;
        }

        $body = $this->requestBody();
        $postId = (int) ($body['post_id'] ?? 0);
        $post = $postId > 0 ? $this->posts->findById($postId) : null;

        if ($post === null || !$post->isPubliclyVisible()) {
            ApiResponse::error('Post not found.', 404);

            return;
        }

        if (!$post->commentsOpen) {
            ApiResponse::error('Comments are closed for this post.', 403);

            return;
        }

        $content = trim((string) ($body['content'] ?? ''));
        $guestName = $user !== null ? $user->displayName : trim((string) ($body['guest_name'] ?? ''));
        $guestEmail = $user !== null ? $user->email : trim((string) ($body['guest_email'] ?? ''));
        $guestUrl = trim((string) ($body['guest_url'] ?? ''));
        $guestUrl = $guestUrl !== '' && filter_var($guestUrl, FILTER_VALIDATE_URL) !== false ? $guestUrl : null;

        if ($content === '' || $guestName === '' || filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false) {
            ApiResponse::error('"content", and a valid name/email (or a bearer token), are required.', 422);

            return;
        }

        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if ($this->comments->recentCommentFromIpExists($ipAddress, 30)) {
            ApiResponse::error('You are commenting too quickly. Please wait a moment and try again.', 429);

            return;
        }

        $parentId = isset($body['parent_id']) ? (int) $body['parent_id'] : null;

        if ($parentId !== null) {
            $parent = $this->comments->findById($parentId);

            if ($parent === null || $parent->postId !== $post->id) {
                $parentId = null;
            }
        }

        $status = (($user?->can('moderate_comments')) ?? false)
            || $this->comments->hasPreviouslyApprovedComment($user?->id, $guestEmail)
            ? CommentStatus::Approved
            : CommentStatus::Pending;

        $comment = $this->comments->create(
            postId: $post->id,
            parentId: $parentId,
            userId: $user?->id,
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestUrl: $guestUrl,
            content: $content,
            status: $status,
            ipAddress: $ipAddress,
            userAgent: is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        );

        ApiResponse::json($this->commentToArray($comment), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function commentsUpdate(array $params): void
    {
        if (!$this->gate('comments')) {
            return;
        }

        $existing = $this->comments->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Comment not found.', 404);

            return;
        }

        $user = $this->requireCapability('moderate_comments');

        if (!$user instanceof User) {
            return;
        }

        $body = $this->requestBody();

        if (isset($body['status'])) {
            $status = CommentStatus::tryFrom((string) $body['status']);

            if ($status === null) {
                ApiResponse::error('Invalid "status".', 422);

                return;
            }

            $this->comments->updateStatus($existing->id, $status);
        }

        if (isset($body['content'])) {
            $this->comments->updateContent($existing->id, (string) $body['content']);
        }

        $comment = $this->comments->findById($existing->id);

        ApiResponse::json($comment !== null ? $this->commentToArray($comment) : null);
    }

    /**
     * @param array<string, string> $params
     */
    public function commentsDestroy(array $params): void
    {
        if (!$this->gate('comments')) {
            return;
        }

        $existing = $this->comments->findById((int) ($params['id'] ?? 0));

        if ($existing === null) {
            ApiResponse::error('Comment not found.', 404);

            return;
        }

        $user = $this->requireCapability('moderate_comments');

        if (!$user instanceof User) {
            return;
        }

        $this->comments->delete($existing->id);

        ApiResponse::json(null, 204);
    }

    // =================================================================
    // Search
    // =================================================================

    /**
     * @param array<string, string> $params
     */
    public function searchIndex(array $params): void
    {
        if (!$this->gate('search')) {
            return;
        }

        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = $this->search->search($query, $page);

        $data = array_map(
            static fn ($searchResult): array => [
                'type' => $searchResult->type,
                'id' => $searchResult->id,
                'title' => $searchResult->title,
                'slug' => $searchResult->slug,
                'excerpt' => $searchResult->excerpt,
                'featured_image_id' => $searchResult->featuredImageId,
                'published_at' => $searchResult->publishedAt?->format(DateTimeImmutable::ATOM),
                'score' => $searchResult->score,
            ],
            $result['results'],
        );

        ApiResponse::json($data, 200, $this->paginationMeta($result));
    }

    // =================================================================
    // Internal helpers
    // =================================================================

    /**
     * REST API Access Controls (LP-039, retargeting the original
     * "XML-RPC API Controls" ticket at the REST API this codebase
     * actually has): the first line of every public action method.
     * Fires `rest_api_request` for every request (allowed or not, so a
     * plugin can observe real traffic), then checks the global
     * `rest_api_enabled` toggle and the per-resource
     * `rest_api_resource_{$resource}_enabled` toggle (each filterable via
     * `rest_api_enabled`/`rest_api_resource_enabled`) — writes a JSON 403
     * and returns false the moment either is off, so callers just need
     * `if (!$this->gate('posts')) { return; }`.
     */
    private function gate(string $resource): bool
    {
        $user = $this->resolveUser($this->authorizationHeader());
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '');

        $this->hooks->doAction('rest_api_request', $method, $path, $user);

        $enabled = (bool) $this->hooks->applyFilters(
            'rest_api_enabled',
            $this->config->option('rest_api_enabled', '1') !== '0',
        );

        if (!$enabled) {
            $this->logBlocked($method, $path, 'api_disabled');
            ApiResponse::error('The REST API is disabled.', 403, 'api_disabled');

            return false;
        }

        $resourceEnabled = (bool) $this->hooks->applyFilters(
            'rest_api_resource_enabled',
            $this->config->option("rest_api_resource_{$resource}_enabled", '1') !== '0',
            $resource,
        );

        if (!$resourceEnabled) {
            $this->logBlocked($method, $path, 'resource_disabled');
            ApiResponse::error("The \"{$resource}\" API resource is disabled.", 403, 'resource_disabled');

            return false;
        }

        return true;
    }

    private function logBlocked(string $method, string $path, string $reason): void
    {
        error_log("[ApiController] Blocked {$method} {$path} ({$reason})");
    }

    /**
     * Resolves the bearer token, requires it to grant $capability, and
     * writes a 401/403 JSON error itself when it doesn't — the common
     * "simple capability, no ownership" gate Categories/Tags/Comment
     * moderation all share. Returns the resolved User on success, or
     * null after already writing the error response (callers just need
     * to `if (!$user instanceof User) { return; }`).
     */
    private function requireCapability(string $capability): ?User
    {
        $user = $this->resolveUser($this->authorizationHeader());

        if ($user === null) {
            ApiResponse::error('A valid API token is required.', 401);

            return null;
        }

        if (!$user->can($capability)) {
            ApiResponse::error('You do not have permission to perform this action.', 403);

            return null;
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assignTaxonomies(int $postId, array $body): void
    {
        if (isset($body['category_ids']) && is_array($body['category_ids'])) {
            $this->categories->assignToPost($postId, $body['category_ids']);
        }

        if (isset($body['tags']) && is_array($body['tags'])) {
            $this->tags->assignToPost($postId, array_map('strval', $body['tags']));
        }
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pagingParams(): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? self::DEFAULT_PER_PAGE)));

        return [$page, $perPage];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(): array
    {
        $raw = ($this->rawInput)();

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function authorizationHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        return is_string($header) ? $header : null;
    }
}
