<?php

declare(strict_types=1);

namespace LumoraPress\Controllers;

use LumoraPress\Core\Theme\ThemeRenderer;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\TagService;

/**
 * Public front-end routes. Posts, Pages, Categories, and Tags now render
 * from PostService/PageService/CategoryService/TagService; search is
 * intentionally not implemented yet (see TODO.md "Not Yet") — this
 * handler exists to prove the URL structure and theme rendering pipeline
 * ahead of that content type landing in a later phase.
 */
final class SiteController
{
    public function __construct(
        private readonly ThemeRenderer $theme,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
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
    public function search(array $params): void
    {
        $this->theme->render('search.php', [
            'page_title' => 'Search',
            'query' => $_GET['q'] ?? '',
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

    public function notFound(): void
    {
        http_response_code(404);
        $this->theme->render('404.php', ['page_title' => 'Page Not Found']);
    }
}
