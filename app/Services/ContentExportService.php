<?php

/**
 * Reads this site's content once into a format-agnostic ExportContent payload for Maintenance > Export.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Services\Export\ExportContent;
use LumoraPress\Services\Export\ExportFormatWriter;
use LumoraPress\Services\Export\ExportOptions;
use LumoraPress\Services\Import\ImportedComment;
use LumoraPress\Services\Import\ImportedMedia;
use LumoraPress\Services\Import\ImportedMenu;
use LumoraPress\Services\Import\ImportedMenuItem;
use LumoraPress\Services\Import\ImportedPage;
use LumoraPress\Services\Import\ImportedPost;
use LumoraPress\Services\Import\ImportedUser;
use LumoraPress\Services\Import\ImportedWidgetInstance;

/**
 * The only export code that touches the live data layer — writers get
 * the finished payload and nothing else. Everything is read in one
 * request; trashed content, spam comments, and user passwords are never
 * exported, and neither are IP addresses or user agents on comments.
 */
final class ContentExportService
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly CommentService $comments,
        private readonly UserService $users,
        private readonly MediaService $media,
        private readonly FolderService $folders,
        private readonly MenuManager $menus,
        private readonly WidgetManager $widgets,
        private readonly string $siteName,
        private readonly string $siteUrl,
        private readonly string $uploadsUrl,
        private readonly string $version,
    ) {
    }

    /**
     * Builds the payload and hands it to $writer, returning the finished
     * file's path. $options is narrowed to what $writer supports first.
     */
    public function export(ExportFormatWriter $writer, ExportOptions $options): string
    {
        $options = $options->restrictedTo($writer);

        return $writer->write($this->build($options), $options);
    }

    public function build(ExportOptions $options): ExportContent
    {
        $withPosts = $options->includes(ExportOptions::POSTS);
        $withMedia = $options->includes(ExportOptions::MEDIA);
        $pages = $options->includes(ExportOptions::PAGES) ? $this->exportPages() : [];
        $posts = $withPosts ? $this->exportPosts() : [];
        [$media, $mediaFiles] = $withMedia ? $this->exportMedia() : [[], []];

        return new ExportContent(
            siteName: $this->siteName,
            siteUrl: rtrim($this->siteUrl, '/'),
            uploadsUrl: rtrim($this->uploadsUrl, '/'),
            generatorVersion: $this->version,
            exportedAt: new DateTimeImmutable(),
            contentTypes: $options->contentTypes,
            includesUploads: $options->includeUploads && $withMedia,
            users: $options->includes(ExportOptions::USERS) ? $this->exportUsers() : [],
            categories: $withPosts ? $this->exportCategories() : [],
            tags: $withPosts ? $this->exportTags() : [],
            folders: $withMedia ? $this->exportFolders() : [],
            media: $media,
            mediaFiles: $mediaFiles,
            pages: $pages,
            posts: $posts,
            comments: $options->includes(ExportOptions::COMMENTS) ? $this->exportComments($posts, $pages) : [],
            menus: $options->includes(ExportOptions::MENUS) ? $this->exportMenus() : [],
            menuLocations: $options->includes(ExportOptions::MENUS) ? $this->exportMenuLocations() : [],
            widgets: $options->includes(ExportOptions::WIDGETS) ? $this->exportWidgets() : [],
        );
    }

    /**
     * @return array<int, ImportedUser>
     */
    private function exportUsers(): array
    {
        $users = [];

        foreach ($this->users->listAll() as $user) {
            $users[] = new ImportedUser(
                username: $user->username,
                email: $user->email,
                role: $user->role,
                displayName: $user->displayName,
                externalId: (string) $user->id,
            );
        }

        return $users;
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, description: string, parentId: ?int, imageId: ?int, archiveDisplayMode: ?string}>
     */
    private function exportCategories(): array
    {
        $categories = array_map(
            static fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'parentId' => $category->parentId,
                'imageId' => $category->imageId,
                'archiveDisplayMode' => $category->archiveDisplayMode,
            ],
            $this->categories->listAll(),
        );

        return self::parentsFirst($categories, 'id', 'parentId');
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, description: string}>
     */
    private function exportTags(): array
    {
        return array_map(
            static fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'description' => $tag->description],
            $this->tags->listAll(),
        );
    }

    /**
     * @return array<int, array{id: int, name: string, parentId: ?int}>
     */
    private function exportFolders(): array
    {
        $folders = array_map(
            static fn ($folder): array => ['id' => $folder->id, 'name' => $folder->name, 'parentId' => $folder->parentId],
            $this->folders->listAll(),
        );

        return self::parentsFirst($folders, 'id', 'parentId');
    }

    /**
     * @return array{0: array<int, ImportedMedia>, 1: array<int, array{filePath: string, mimeType: string}>}
     */
    private function exportMedia(): array
    {
        $media = [];
        $files = [];
        $offset = 0;

        do {
            $result = $this->media->query([], self::BATCH_SIZE, $offset);

            foreach ($result['items'] as $row) {
                $id = (int) $row['id'];
                $filePath = (string) $row['file_path'];
                $directory = dirname($filePath);

                $media[] = new ImportedMedia(
                    absolutePath: $this->media->absolutePath($row),
                    uploadedByUserId: (int) $row['uploaded_by'],
                    fileName: (string) $row['file_name'],
                    altText: self::nullableString($row['alt_text'] ?? null),
                    caption: self::nullableString($row['caption'] ?? null),
                    description: self::nullableString($row['description'] ?? null),
                    folderId: $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
                    externalId: (string) $id,
                    uploadedAt: ($row['uploaded_at'] ?? null) !== null ? new DateTimeImmutable((string) $row['uploaded_at']) : null,
                    relativeDirectory: $directory !== '.' ? $directory : null,
                );
                $files[$id] = ['filePath' => $filePath, 'mimeType' => (string) $row['mime_type']];
            }

            $offset += self::BATCH_SIZE;
        } while ($offset < $result['total']);

        return [$media, $files];
    }

    /**
     * @return array<int, ImportedPage>
     */
    private function exportPages(): array
    {
        $pages = [];
        $pageNumber = 1;

        do {
            $result = $this->pages->paginateForAdmin($pageNumber, self::BATCH_SIZE);

            foreach ($result['pages'] as $page) {
                $pages[] = [
                    'id' => $page->id,
                    'parentId' => $page->parentId,
                    'menuOrder' => $page->menuOrder,
                    'dto' => new ImportedPage(
                        title: $page->title,
                        content: $page->content,
                        excerpt: $page->excerpt,
                        authorId: $page->authorId,
                        status: $page->status,
                        publishedAt: $page->publishedAt,
                        parentExternalId: $page->parentId !== null ? (string) $page->parentId : null,
                        featuredImageId: $page->featuredImageId,
                        slug: $page->slug,
                        contentFormat: $page->contentFormat,
                        featuredImageCrop: $page->featuredImageCrop,
                        externalId: (string) $page->id,
                        visibility: $page->visibility,
                        commentsOpen: $page->commentsOpen,
                        metaTitle: $page->metaTitle,
                        metaDescription: $page->metaDescription,
                    ),
                ];
            }

            $pageNumber++;
        } while ($pageNumber <= $result['totalPages']);

        // Sibling order survives the trip because an importer recreates
        // pages in this order and PageService appends each to the end.
        usort($pages, static fn (array $a, array $b): int => $a['menuOrder'] <=> $b['menuOrder'] ?: $a['id'] <=> $b['id']);

        return array_column(self::parentsFirst($pages, 'id', 'parentId'), 'dto');
    }

    /**
     * @return array<int, ImportedPost>
     */
    private function exportPosts(): array
    {
        $posts = [];
        $pageNumber = 1;

        do {
            $result = $this->posts->paginateForAdmin($pageNumber, self::BATCH_SIZE);

            foreach ($result['posts'] as $post) {
                $categories = $this->categories->categoriesForPost($post->id);

                $posts[] = new ImportedPost(
                    title: $post->title,
                    content: $post->content,
                    excerpt: $post->excerpt,
                    authorId: $post->authorId,
                    status: $post->status,
                    publishedAt: $post->publishedAt,
                    featuredImageId: $post->featuredImageId,
                    slug: $post->slug,
                    commentsOpen: $post->commentsOpen,
                    contentFormat: $post->contentFormat,
                    featuredImageCrop: $post->featuredImageCrop,
                    visibility: $post->visibility,
                    isSticky: $post->isSticky,
                    unpublishAt: $post->unpublishAt,
                    categories: array_map(static fn ($category): string => $category->name, $categories),
                    tags: array_map(static fn ($tag): string => $tag->name, $this->tags->tagsForPost($post->id)),
                    meta: $this->posts->metaForPost($post->id),
                    externalId: (string) $post->id,
                    categoryIds: array_map(static fn ($category): int => $category->id, $categories),
                    metaTitle: $post->metaTitle,
                    metaDescription: $post->metaDescription,
                );
            }

            $pageNumber++;
        } while ($pageNumber <= $result['totalPages']);

        // Oldest first, matching how the content was originally written.
        return array_reverse($posts);
    }

    /**
     * Only comments on content that's part of this same export — a
     * comment whose post was left out has nothing to attach to.
     *
     * @param array<int, ImportedPost> $posts
     * @param array<int, ImportedPage> $pages
     * @return array<int, ImportedComment>
     */
    private function exportComments(array $posts, array $pages): array
    {
        $postIds = array_flip(array_map(static fn (ImportedPost $post): int => (int) $post->externalId, $posts));
        $pageIds = array_flip(array_map(static fn (ImportedPage $page): int => (int) $page->externalId, $pages));
        $comments = [];
        $pageNumber = 1;

        do {
            $result = $this->comments->paginateForAdmin($pageNumber, self::BATCH_SIZE);

            foreach ($result['comments'] as $row) {
                $comment = $row['comment'];

                if ($comment->status === CommentStatus::Spam) {
                    continue;
                }

                $onExportedPost = $comment->postId !== null && isset($postIds[$comment->postId]);
                $onExportedPage = $comment->pageId !== null && isset($pageIds[$comment->pageId]);

                if (!$onExportedPost && !$onExportedPage) {
                    continue;
                }

                $comments[$comment->id] = new ImportedComment(
                    postId: $onExportedPost ? $comment->postId : null,
                    content: $comment->content,
                    guestName: $comment->guestName,
                    guestEmail: $comment->guestEmail,
                    status: $comment->status,
                    guestUrl: $comment->guestUrl,
                    userId: $comment->userId,
                    parentExternalId: $comment->parentId !== null ? (string) $comment->parentId : null,
                    externalId: (string) $comment->id,
                    commentedAt: $comment->createdAt,
                    pageId: $onExportedPage ? $comment->pageId : null,
                );
            }

            $pageNumber++;
        } while ($pageNumber <= $result['totalPages']);

        // A reply is always newer than its parent, so id order is parent-first.
        ksort($comments);

        return array_values($comments);
    }

    /**
     * @return array<int, ImportedMenu>
     */
    private function exportMenus(): array
    {
        $menus = [];

        foreach ($this->menus->menus() as $menuId => $menu) {
            $items = [];

            foreach ($menu['items'] as $order => $item) {
                $items[] = new ImportedMenuItem(
                    label: (string) ($item['label'] ?? ''),
                    url: (string) ($item['url'] ?? ''),
                    order: $order,
                    target: (string) ($item['target'] ?? '_self'),
                    cssClass: (string) ($item['cssClass'] ?? ''),
                    rel: (string) ($item['rel'] ?? ''),
                    titleAttribute: (string) ($item['titleAttribute'] ?? ''),
                    parentExternalId: ($item['parentId'] ?? null) !== null ? (string) $item['parentId'] : null,
                    externalId: (string) ($item['id'] ?? ''),
                    hidden: ($item['hidden'] ?? false) === true,
                );
            }

            $menus[] = new ImportedMenu(name: $menu['name'], items: $items, externalId: (string) $menuId);
        }

        return $menus;
    }

    /**
     * @return array<string, string>
     */
    private function exportMenuLocations(): array
    {
        $locations = [];

        foreach (array_keys($this->menus->locations()) as $location) {
            $menuId = $this->menus->menuIdForLocation($location);

            if ($menuId !== null) {
                $locations[$location] = $menuId;
            }
        }

        return $locations;
    }

    /**
     * @return array<int, ImportedWidgetInstance>
     */
    private function exportWidgets(): array
    {
        $instances = [];

        foreach ([...array_keys($this->widgets->sidebars()), WidgetManager::INACTIVE_SIDEBAR_ID] as $sidebarId) {
            foreach ($this->widgets->widgetsFor($sidebarId) as $order => $widget) {
                $instances[] = new ImportedWidgetInstance(
                    type: $widget['type'],
                    settings: $widget['settings'],
                    targetSidebarId: $sidebarId,
                    order: $order,
                    externalId: $widget['id'],
                );
            }
        }

        return $instances;
    }

    /**
     * Reorders a flat parent-linked list so every parent precedes its
     * children, keeping the original order within each level. A row whose
     * parent isn't in the list is treated as top-level.
     *
     * @template T of array
     * @param array<int, T> $rows
     * @return array<int, T>
     */
    private static function parentsFirst(array $rows, string $idKey, string $parentKey): array
    {
        $ids = array_flip(array_map(static fn (array $row): int => (int) $row[$idKey], $rows));
        $childrenOf = [];

        foreach ($rows as $row) {
            $parent = $row[$parentKey] !== null && isset($ids[(int) $row[$parentKey]]) ? (int) $row[$parentKey] : 0;
            $childrenOf[$parent][] = $row;
        }

        $ordered = [];
        $visit = static function (int $parent) use (&$visit, &$ordered, &$childrenOf, $idKey): void {
            $children = $childrenOf[$parent] ?? [];
            unset($childrenOf[$parent]);

            foreach ($children as $row) {
                $ordered[] = $row;
                $visit((int) $row[$idKey]);
            }
        };

        $visit(0);

        // A parent cycle (shouldn't exist, but a hand-edited database
        // could have one) is never reachable from the root; keep those
        // rows rather than silently dropping them.
        foreach ($childrenOf as $orphans) {
            array_push($ordered, ...$orphans);
        }

        return $ordered;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
