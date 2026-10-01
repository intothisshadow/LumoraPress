<?php

/**
 * Imports a Lumora Press content export into this site through the shared Importer layer.
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

namespace LumoraPress\Services\Import;

use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\Export\ExportContent;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\TagService;
use Throwable;

/**
 * Every payload id is the exporting site's own, so each is remapped to
 * the local id it became before it's used. External ids are recorded as
 * "{site key}:{id}", the key derived from the exporting site's address,
 * so two different sites' post #5 never collide in ContentImportRegistry
 * — while importing the same site's export again matches everything it
 * created last time and updates (or skips) it instead of duplicating.
 *
 * Content is imported dependency-first: users, folders, media,
 * categories, tags, pages, posts, comments, menus, widgets. A single
 * item that fails is reported as a warning and skipped, never aborting
 * the rest of the import.
 */
final class LumoraPressImportService
{
    /** @var array<int, string> */
    private array $warnings = [];

    private string $siteKey = '';

    /** @var array<int, int> */
    private array $userMap = [];

    /** @var array<int, int> */
    private array $folderMap = [];

    /** @var array<int, int> */
    private array $mediaMap = [];

    /** @var array<int, int> */
    private array $categoryMap = [];

    /** @var array<int, int> */
    private array $pageMap = [];

    /** @var array<int, int> */
    private array $postMap = [];

    /** @var array<string, string> */
    private array $urlReplacements = [];

    private int $missingFeaturedImages = 0;

    /** @var array<string, int> */
    private array $pageExternalMap = [];

    /** @var array<string, int> */
    private array $commentExternalMap = [];

    /** Stage id => label, in the order they run. */
    public const STAGES = [
        'users' => 'Users',
        'folders' => 'Media folders',
        'media' => 'Media',
        'categories' => 'Categories',
        'tags' => 'Tags',
        'pages' => 'Pages',
        'posts' => 'Posts',
        'comments' => 'Comments',
        'menus' => 'Menus',
        'widgets' => 'Widgets',
    ];

    public function __construct(
        private readonly UserImporter $userImporter,
        private readonly PostImporter $postImporter,
        private readonly PageImporter $pageImporter,
        private readonly MediaImporter $mediaImporter,
        private readonly CommentImporter $commentImporter,
        private readonly MenuImporter $menuImporter,
        private readonly WidgetImporter $widgetImporter,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly FolderService $folders,
        private readonly MenuManager $menus,
        private readonly WidgetManager $widgets,
        private readonly PressConfig $config,
        private readonly ContentImportRegistry $registry,
        private readonly string $siteUrl,
        private readonly string $uploadsUrl,
        private readonly int $fallbackUserId,
        private readonly ?\Closure $mediaResolver = null,
    ) {
    }

    /**
     * Runs the whole import in this call.
     *
     * @return array{batchId: string, counts: array<string, int>, warnings: array<int, string>}
     */
    public function import(ExportContent $content, ExistingContentMode $mode = ExistingContentMode::Overwrite): array
    {
        $state = $this->begin($content);

        while (!$state['done']) {
            $state = $this->step($content, $mode, $state);
        }

        return $this->result($state);
    }

    /**
     * Starts an import that is carried out over several step() calls.
     * The state is plain JSON-safe data, so it can be kept between requests.
     *
     * @return array<string, mixed>
     */
    public function begin(ExportContent $content): array
    {
        $this->reset($content);

        return $this->snapshot($this->registry->newBatch(), 0, 0, false);
    }

    /**
     * Imports until $seconds have passed (at least one item), or to the end
     * when $seconds is null. $content must be the same export on every call.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function step(ExportContent $content, ExistingContentMode $mode, array $state, ?float $seconds = null): array
    {
        $this->restore($state);
        $batchId = (string) $state['batchId'];
        $stage = (int) $state['stage'];
        $cursor = (int) $state['cursor'];
        $stageIds = array_keys(self::STAGES);
        $deadline = $seconds === null ? null : microtime(true) + $seconds;

        while ($stage < count($stageIds)) {
            $next = match ($stageIds[$stage]) {
                'users' => $this->importUsers($batchId, $content, $cursor, $deadline),
                'folders' => $this->importFolders($batchId, $content, $cursor, $deadline),
                'media' => $this->importMedia($batchId, $content, $mode, $cursor, $deadline),
                'categories' => $this->importCategories($batchId, $content, $mode, $cursor, $deadline),
                'tags' => $this->importTags($batchId, $content, $cursor, $deadline),
                'pages' => $this->importPages($batchId, $content, $mode, $cursor, $deadline),
                'posts' => $this->importPosts($batchId, $content, $mode, $cursor, $deadline),
                'comments' => $this->importComments($batchId, $content, $mode, $cursor, $deadline),
                'menus' => $this->importMenus($batchId, $content),
                'widgets' => $this->importWidgets($batchId, $content),
            };

            if ($next !== null) {
                return $this->snapshot($batchId, $stage, $next, false);
            }

            if ($stageIds[$stage] === 'media') {
                $this->buildUrlReplacements($content);
            }

            $stage++;
            $cursor = 0;

            if ($deadline !== null && $stage < count($stageIds) && microtime(true) >= $deadline) {
                return $this->snapshot($batchId, $stage, 0, false);
            }
        }

        if ($this->missingFeaturedImages > 0) {
            $this->warnings[] = "{$this->missingFeaturedImages} post(s)/page(s) had a featured image that wasn't imported, so no featured image is set on them.";
            $this->missingFeaturedImages = 0;
        }

        return $this->snapshot($batchId, $stage, 0, true);
    }

    /**
     * @param array<string, mixed> $state a finished state
     * @return array{batchId: string, counts: array<string, int>, warnings: array<int, string>}
     */
    public function result(array $state): array
    {
        $batchId = (string) $state['batchId'];
        $counts = array_filter(
            $this->registry->countsForBatch($batchId),
            static fn (string $type): bool => !str_ends_with($type, '_snap'),
            ARRAY_FILTER_USE_KEY,
        );

        return ['batchId' => $batchId, 'counts' => $counts, 'warnings' => array_values((array) $state['warnings'])];
    }

    /**
     * What the progress display shows for a state: every stage with its status, and a line about the current one.
     *
     * @param array<string, mixed> $state
     * @return array{stages: array<int, array{label: string, status: string}>, detail: string}
     */
    public function progress(ExportContent $content, array $state): array
    {
        $stages = [];
        $detail = '';
        $current = (int) $state['stage'];

        foreach (array_keys(self::STAGES) as $index => $id) {
            $status = $index < $current || $state['done'] ? 'done' : ($index === $current ? 'running' : 'pending');
            $stages[] = ['label' => self::STAGES[$id], 'status' => $status];

            if ($status === 'running') {
                $total = $this->stageTotal($content, $id);
                $detail = $total > 0
                    ? 'Importing ' . strtolower(self::STAGES[$id]) . ': ' . number_format(min((int) $state['cursor'], $total)) . ' of ' . number_format($total)
                    : 'Importing ' . strtolower(self::STAGES[$id]) . '…';
            }
        }

        return ['stages' => $stages, 'detail' => $detail];
    }

    private function stageTotal(ExportContent $content, string $stageId): int
    {
        return match ($stageId) {
            'users' => count($content->users),
            'folders' => count($content->folders),
            'media' => count($content->media),
            'categories' => count($content->categories),
            'tags' => count($content->tags),
            'pages' => count($content->pages),
            'posts' => count($content->posts),
            'comments' => count($content->comments),
            'menus' => count($content->menus),
            'widgets' => count($content->widgets),
            default => 0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $batchId, int $stage, int $cursor, bool $done): array
    {
        return [
            'batchId' => $batchId,
            'stage' => $stage,
            'cursor' => $cursor,
            'done' => $done,
            'siteKey' => $this->siteKey,
            'warnings' => $this->warnings,
            'userMap' => $this->userMap,
            'folderMap' => $this->folderMap,
            'mediaMap' => $this->mediaMap,
            'categoryMap' => $this->categoryMap,
            'pageMap' => $this->pageMap,
            'postMap' => $this->postMap,
            'urlReplacements' => $this->urlReplacements,
            'missingFeaturedImages' => $this->missingFeaturedImages,
            'pageExternalMap' => $this->pageExternalMap,
            'commentExternalMap' => $this->commentExternalMap,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function restore(array $state): void
    {
        $this->siteKey = (string) $state['siteKey'];
        $this->warnings = (array) $state['warnings'];
        $this->userMap = (array) $state['userMap'];
        $this->folderMap = (array) $state['folderMap'];
        $this->mediaMap = (array) $state['mediaMap'];
        $this->categoryMap = (array) $state['categoryMap'];
        $this->pageMap = (array) $state['pageMap'];
        $this->postMap = (array) $state['postMap'];
        $this->urlReplacements = (array) $state['urlReplacements'];
        $this->missingFeaturedImages = (int) $state['missingFeaturedImages'];
        $this->pageExternalMap = (array) $state['pageExternalMap'];
        $this->commentExternalMap = (array) $state['commentExternalMap'];
    }

    /** Checked between items, so every call still gets through at least one. */
    private function outOfTime(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    private function reset(ExportContent $content): void
    {
        $this->warnings = [];
        $this->siteKey = substr(hash('sha256', strtolower($content->siteUrl)), 0, 12);
        $this->userMap = [];
        $this->folderMap = [];
        $this->mediaMap = [];
        $this->categoryMap = [];
        $this->pageMap = [];
        $this->postMap = [];
        $this->urlReplacements = [];
        $this->missingFeaturedImages = 0;
        $this->pageExternalMap = [];
        $this->commentExternalMap = [];
    }

    private function externalId(string|int|null $sourceId): string
    {
        return $this->siteKey . ':' . $sourceId;
    }

    private function localUserId(int $sourceUserId): int
    {
        return $this->userMap[$sourceUserId] ?? $this->fallbackUserId;
    }

    private function importUsers(string $batchId, ExportContent $content, int $start, ?float $deadline): ?int
    {
        $items = array_values($content->users);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $user = $items[$index];
            try {
                $local = $this->userImporter->importOrReuse($batchId, LumoraPressExportSource::SOURCE, new ImportedUser(
                    username: $user->username,
                    email: $user->email,
                    role: $user->role,
                    displayName: $user->displayName,
                    externalId: $this->externalId($user->externalId),
                ));
                $this->userMap[(int) $user->externalId] = $local->id;
            } catch (Throwable $exception) {
                $this->warnings[] = "User \"{$user->username}\": {$exception->getMessage()} Their content is credited to you instead.";
            }
        }

        return null;
    }

    /**
     * A folder with the same name under the same parent is reused, so
     * importing into a site that already has "Wallpapers" doesn't create
     * a second one next to it.
     */
    private function importFolders(string $batchId, ExportContent $content, int $start, ?float $deadline): ?int
    {
        $existing = [];

        foreach ($this->folders->listAll() as $folder) {
            $existing[($folder->parentId ?? 0) . '|' . mb_strtolower($folder->name)] = $folder->id;
        }

        $items = array_values($content->folders);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $folder = $items[$index];
            $parentId = $folder['parentId'] !== null ? ($this->folderMap[$folder['parentId']] ?? null) : null;
            $key = ($parentId ?? 0) . '|' . mb_strtolower($folder['name']);
            $previousId = $this->registry->existingLocalId(LumoraPressExportSource::SOURCE, 'folder', $this->externalId($folder['id']));

            if ($previousId !== null && $this->folders->findById($previousId) !== null) {
                $this->folderMap[$folder['id']] = $previousId;
            } elseif (isset($existing[$key])) {
                $this->folderMap[$folder['id']] = $existing[$key];
            } else {
                try {
                    $created = $this->folders->create($folder['name'], $parentId);
                    $this->registry->record($batchId, LumoraPressExportSource::SOURCE, 'folder', $created->id, $this->externalId($folder['id']));
                    $this->folderMap[$folder['id']] = $created->id;
                    $existing[$key] = $created->id;
                } catch (Throwable $exception) {
                    $this->warnings[] = "Media folder \"{$folder['name']}\": {$exception->getMessage()}";
                }
            }
        }

        return null;
    }

    private function importMedia(string $batchId, ExportContent $content, ExistingContentMode $mode, int $start, ?float $deadline): ?int
    {
        $items = array_values($content->media);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $media = $items[$index];
            $sourceId = (int) $media->externalId;
            $oldPath = $content->mediaFiles[(int) $media->externalId]['filePath'] ?? '';
            $name = $media->fileName ?? basename($oldPath);

            try {
                $media = $this->mediaResolver !== null ? ($this->mediaResolver)($media, $content) : $media;
                $row = $this->mediaImporter->importFromLocalFile($batchId, LumoraPressExportSource::SOURCE, new ImportedMedia(
                    absolutePath: $media->absolutePath,
                    uploadedByUserId: $this->localUserId($media->uploadedByUserId),
                    fileName: $media->fileName,
                    altText: $media->altText,
                    caption: $media->caption,
                    description: $media->description,
                    folderId: $media->folderId !== null ? ($this->folderMap[$media->folderId] ?? null) : null,
                    externalId: $this->externalId($sourceId),
                    uploadedAt: $media->uploadedAt,
                    relativeDirectory: $media->relativeDirectory,
                ), $mode);

                $this->mediaMap[$sourceId] = (int) $row['id'];

                if ($oldPath !== '' && (string) $row['file_path'] !== $oldPath) {
                    $this->urlReplacements[$oldPath] = (string) $row['file_path'];
                }
            } catch (Throwable $exception) {
                $this->warnings[] = !is_file($media->absolutePath)
                    ? "Media #{$sourceId} (\"{$name}\"): file not found in the export or the uploaded files folder, skipped."
                    : "Media #{$sourceId} (\"{$name}\"): {$exception->getMessage()}";
            }
        }

        return null;
    }

    private function importCategories(string $batchId, ExportContent $content, ExistingContentMode $mode, int $start, ?float $deadline): ?int
    {
        $items = array_values($content->categories);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $category = $items[$index];
            $parentId = $category['parentId'] !== null ? ($this->categoryMap[$category['parentId']] ?? null) : null;
            $imageId = $category['imageId'] !== null ? ($this->mediaMap[$category['imageId']] ?? null) : null;
            $previousId = $this->registry->existingLocalId(LumoraPressExportSource::SOURCE, 'category', $this->externalId($category['id']));

            try {
                if ($previousId !== null && $this->categories->findById($previousId) !== null) {
                    if ($mode === ExistingContentMode::Overwrite) {
                        $this->categories->update($previousId, $category['name'], $category['description'], $parentId, $category['slug'], $imageId, $category['archiveDisplayMode']);
                    }

                    $this->categoryMap[$category['id']] = $previousId;
                    continue;
                }

                $match = $this->categories->findBySlugAndParent($category['slug'], $parentId);

                if ($match !== null) {
                    $this->categoryMap[$category['id']] = $match->id;
                    continue;
                }

                $created = $this->categories->create($category['name'], $category['description'], $parentId, $category['slug'], $imageId, $category['archiveDisplayMode']);
                $this->registry->record($batchId, LumoraPressExportSource::SOURCE, 'category', $created->id, $this->externalId($category['id']));
                $this->categoryMap[$category['id']] = $created->id;
            } catch (Throwable $exception) {
                $this->warnings[] = "Category \"{$category['name']}\": {$exception->getMessage()}";
            }
        }

        return null;
    }

    /**
     * Posts assign tags by name, so this only needs every tag to exist
     * with its original slug and description before the posts arrive.
     */
    private function importTags(string $batchId, ExportContent $content, int $start, ?float $deadline): ?int
    {
        $items = array_values($content->tags);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $tag = $items[$index];
            if ($this->registry->existingLocalId(LumoraPressExportSource::SOURCE, 'tag', $this->externalId($tag['id'])) !== null || $this->tags->findBySlug($tag['slug']) !== null) {
                continue;
            }

            try {
                $created = $this->tags->create($tag['name'], $tag['description'], $tag['slug']);
                $this->registry->record($batchId, LumoraPressExportSource::SOURCE, 'tag', $created->id, $this->externalId($tag['id']));
            } catch (Throwable $exception) {
                $this->warnings[] = "Tag \"{$tag['name']}\": {$exception->getMessage()}";
            }
        }

        return null;
    }

    private function importPages(string $batchId, ExportContent $content, ExistingContentMode $mode, int $start, ?float $deadline): ?int
    {
        $items = array_values($content->pages);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $page = $items[$index];
            $featuredImageId = $this->localFeaturedImageId($page->featuredImageId);

            try {
                $local = $this->pageImporter->import($batchId, LumoraPressExportSource::SOURCE, new ImportedPage(
                    title: $page->title,
                    content: $this->rewriteUrls($page->content, $content),
                    excerpt: $page->excerpt,
                    authorId: $this->localUserId($page->authorId),
                    status: $page->status,
                    publishedAt: $page->publishedAt,
                    parentExternalId: $page->parentExternalId !== null ? $this->externalId($page->parentExternalId) : null,
                    featuredImageId: $featuredImageId,
                    slug: $page->slug,
                    contentFormat: $page->contentFormat,
                    featuredImageCrop: $featuredImageId !== null ? $page->featuredImageCrop : null,
                    externalId: $this->externalId($page->externalId),
                    visibility: $page->visibility,
                    commentsOpen: $page->commentsOpen,
                    metaTitle: $page->metaTitle,
                    metaDescription: $page->metaDescription,
                ), $this->pageExternalMap, $mode);

                $this->pageExternalMap[$this->externalId($page->externalId)] = $local->id;
                $this->pageMap[(int) $page->externalId] = $local->id;
            } catch (Throwable $exception) {
                $this->warnings[] = "Page \"{$page->title}\": {$exception->getMessage()}";
            }
        }

        return null;
    }

    private function importPosts(string $batchId, ExportContent $content, ExistingContentMode $mode, int $start, ?float $deadline): ?int
    {
        $items = array_values($content->posts);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $post = $items[$index];
            $featuredImageId = $this->localFeaturedImageId($post->featuredImageId);
            $categoryIds = array_values(array_filter(array_map(
                fn (int $sourceId): ?int => $this->categoryMap[$sourceId] ?? null,
                $post->categoryIds,
            )));

            try {
                $local = $this->postImporter->import($batchId, LumoraPressExportSource::SOURCE, new ImportedPost(
                    title: $post->title,
                    content: $this->rewriteUrls($post->content, $content),
                    excerpt: $post->excerpt,
                    authorId: $this->localUserId($post->authorId),
                    status: $post->status,
                    publishedAt: $post->publishedAt,
                    featuredImageId: $featuredImageId,
                    slug: $post->slug,
                    commentsOpen: $post->commentsOpen,
                    contentFormat: $post->contentFormat,
                    featuredImageCrop: $featuredImageId !== null ? $post->featuredImageCrop : null,
                    visibility: $post->visibility,
                    isSticky: $post->isSticky,
                    unpublishAt: $post->unpublishAt,
                    categories: $post->categories,
                    tags: $post->tags,
                    meta: $post->meta,
                    externalId: $this->externalId($post->externalId),
                    categoryIds: $categoryIds,
                    metaTitle: $post->metaTitle,
                    metaDescription: $post->metaDescription,
                ), $mode);

                $this->postMap[(int) $post->externalId] = $local->id;
            } catch (Throwable $exception) {
                $this->warnings[] = "Post \"{$post->title}\": {$exception->getMessage()}";
            }
        }

        return null;
    }

    private function importComments(string $batchId, ExportContent $content, ExistingContentMode $mode, int $start, ?float $deadline): ?int
    {
        $items = array_values($content->comments);

        for ($index = $start; $index < count($items); $index++) {
            if ($index > $start && $this->outOfTime($deadline)) {
                return $index;
            }

            $comment = $items[$index];
            $postId = $comment->postId !== null ? ($this->postMap[$comment->postId] ?? null) : null;
            $pageId = $comment->pageId !== null ? ($this->pageMap[$comment->pageId] ?? null) : null;

            if ($postId === null && $pageId === null) {
                $this->warnings[] = "Comment #{$comment->externalId}: the post or page it belongs to wasn't imported, skipped.";
                continue;
            }

            try {
                $local = $this->commentImporter->import($batchId, LumoraPressExportSource::SOURCE, new ImportedComment(
                    postId: $postId,
                    content: $comment->content,
                    guestName: $comment->guestName,
                    guestEmail: $comment->guestEmail,
                    status: $comment->status,
                    guestUrl: $comment->guestUrl,
                    userId: $comment->userId !== null ? ($this->userMap[$comment->userId] ?? null) : null,
                    parentExternalId: $comment->parentExternalId !== null ? $this->externalId($comment->parentExternalId) : null,
                    externalId: $this->externalId($comment->externalId),
                    commentedAt: $comment->commentedAt,
                    pageId: $pageId,
                ), $this->commentExternalMap, $mode);

                $this->commentExternalMap[$this->externalId($comment->externalId)] = $local->id;
            } catch (Throwable $exception) {
                $this->warnings[] = "Comment #{$comment->externalId}: {$exception->getMessage()}";
            }
        }

        return null;
    }

    /**
     * Menus have no per-item database rows to update in place, so a menu
     * this source already imported once is left alone rather than
     * duplicated. A theme location is only filled if it's currently
     * empty here — an import never replaces a menu the admin chose.
     */
    private function importMenus(string $batchId, ExportContent $content): ?int
    {
        if ($content->menus === []) {
            return null;
        }

        $this->registry->record($batchId, LumoraPressExportSource::SOURCE, 'nav_menus_snap', 0, null, (string) $this->config->option('nav_menus', '{}'));

        /** @var array<string, string> $localMenuIds source menu id => local menu id */
        $localMenuIds = [];

        foreach ($content->menus as $menu) {
            $externalId = $this->externalId($menu->externalId);

            if ($this->registry->existingLocalId(LumoraPressExportSource::SOURCE, 'nav_menu', $externalId) !== null) {
                $this->warnings[] = "Menu \"{$menu->name}\" was already imported from this site before, so it was left unchanged.";
                continue;
            }

            $items = array_map(fn (ImportedMenuItem $item): ImportedMenuItem => new ImportedMenuItem(
                label: $item->label,
                url: $this->rewriteUrls($item->url, $content),
                order: $item->order,
                target: $item->target,
                cssClass: $item->cssClass,
                rel: $item->rel,
                titleAttribute: $item->titleAttribute,
                parentExternalId: $item->parentExternalId,
                externalId: $item->externalId,
                hidden: $item->hidden,
            ), $menu->items);

            $localMenuIds[(string) $menu->externalId] = $this->menuImporter->import($batchId, LumoraPressExportSource::SOURCE, new ImportedMenu($menu->name, $items, $externalId));
        }

        $this->config->setOption('nav_menus', json_encode($this->menus->menus()));

        $locationsChanged = false;

        foreach ($content->menuLocations as $location => $sourceMenuId) {
            if (isset($localMenuIds[$sourceMenuId]) && array_key_exists($location, $this->menus->locations()) && $this->menus->menuIdForLocation($location) === null) {
                $this->menus->assignMenuToLocation($location, $localMenuIds[$sourceMenuId]);
                $locationsChanged = true;
            }
        }

        if ($locationsChanged) {
            $assignments = [];

            foreach (array_keys($this->menus->locations()) as $location) {
                $menuId = $this->menus->menuIdForLocation($location);

                if ($menuId !== null) {
                    $assignments[$location] = $menuId;
                }
            }

            $this->config->setOption('nav_menu_locations', json_encode($assignments));
        }

        return null;
    }

    /**
     * Widgets are appended to whatever each sidebar already holds. One
     * whose sidebar the active theme doesn't have lands in Inactive
     * Widgets, and one whose type isn't available here (a plugin's widget
     * with that plugin inactive) is skipped.
     */
    private function importWidgets(string $batchId, ExportContent $content): ?int
    {
        if ($content->widgets === []) {
            return null;
        }

        $this->registry->record($batchId, LumoraPressExportSource::SOURCE, 'widgets_snap', 0, null, (string) $this->config->option('widgets_config', '{}'));

        $sidebars = $this->widgets->sidebars();
        $types = $this->widgets->widgetTypes();
        $instances = [];
        $unmatchedSidebars = [];

        foreach ($content->widgets as $widget) {
            $externalId = $this->externalId($widget->externalId);

            if ($this->registry->existingLocalId(LumoraPressExportSource::SOURCE, 'widget_instance', $externalId) !== null) {
                continue;
            }

            if (!isset($types[$widget->type])) {
                $this->warnings[] = "Widget \"{$widget->type}\": no widget of that type is available on this site (is the plugin that provides it active?), skipped.";
                continue;
            }

            $target = $widget->targetSidebarId;

            if ($target !== WidgetManager::INACTIVE_SIDEBAR_ID && !isset($sidebars[$target])) {
                $unmatchedSidebars[$target] = true;
                $target = WidgetManager::INACTIVE_SIDEBAR_ID;
            }

            $instances[] = new ImportedWidgetInstance($widget->type, $widget->settings, $target, $widget->order, $externalId);
        }

        foreach (array_keys($unmatchedSidebars) as $sidebarId) {
            $this->warnings[] = "Widget area \"{$sidebarId}\" doesn't exist in the active theme, so its widgets were moved to Inactive Widgets.";
        }

        if ($instances === []) {
            return null;
        }

        $this->widgetImporter->import($batchId, LumoraPressExportSource::SOURCE, $instances);

        $config = [];

        foreach ([...array_keys($sidebars), WidgetManager::INACTIVE_SIDEBAR_ID] as $sidebarId) {
            $config[$sidebarId] = $this->widgets->widgetsFor($sidebarId);
        }

        $this->config->setOption('widgets_config', json_encode($config));

        return null;
    }

    private function localFeaturedImageId(?int $sourceMediaId): ?int
    {
        if ($sourceMediaId === null) {
            return null;
        }

        if (!isset($this->mediaMap[$sourceMediaId])) {
            $this->missingFeaturedImages++;

            return null;
        }

        return $this->mediaMap[$sourceMediaId];
    }

    /**
     * Keyed by the file's path relative to the uploads root: MediaImporter
     * gives every file a new unique name, so each old path maps to the
     * new one. Built once after the media stage.
     */
    private function buildUrlReplacements(ExportContent $content): void
    {
        $oldUploadsPath = rtrim((string) parse_url($content->uploadsUrl, PHP_URL_PATH), '/');
        $newUploadsPath = rtrim((string) parse_url($this->uploadsUrl, PHP_URL_PATH), '/');
        $replacements = [];

        foreach ($this->urlReplacements as $oldPath => $newPath) {
            $replacements[$newUploadsPath . '/' . $oldPath] = $newUploadsPath . '/' . $newPath;
            $replacements[$oldUploadsPath . '/' . $oldPath] = $newUploadsPath . '/' . $newPath;
        }

        $this->urlReplacements = $replacements;
    }

    /**
     * Best-effort link repair for content moving between sites: absolute
     * links to the old site's address, root-relative links under its old
     * install folder, then each renamed media file. Links whose own
     * permalink changed shape aren't rewritten.
     */
    private function rewriteUrls(string $text, ExportContent $content): string
    {
        if ($text === '') {
            return $text;
        }

        $oldSite = rtrim($content->siteUrl, '/');
        $newSite = rtrim($this->siteUrl, '/');

        if ($oldSite !== '' && $oldSite !== $newSite) {
            $text = $text === $oldSite ? $newSite : str_replace($oldSite . '/', $newSite . '/', $text);
        }

        $oldBase = rtrim((string) parse_url($oldSite, PHP_URL_PATH), '/');
        $newBase = rtrim((string) parse_url($newSite, PHP_URL_PATH), '/');

        if ($oldBase !== '' && $oldBase !== $newBase) {
            $text = (string) preg_replace('#(^|["\'(=\s])' . preg_quote($oldBase . '/', '#') . '#', '$1' . $newBase . '/', $text);
        }

        return $this->urlReplacements !== [] ? strtr($text, $this->urlReplacements) : $text;
    }
}
