<?php

/**
 * Converts an ExportContent payload to and from the Lumora Press export format's manifest.json.
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

namespace LumoraPress\Services\Export;

use DateTimeImmutable;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PageVisibility;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use LumoraPress\Models\UserRole;
use LumoraPress\Services\Import\ImportedComment;
use LumoraPress\Services\Import\ImportedMedia;
use LumoraPress\Services\Import\ImportedMenu;
use LumoraPress\Services\Import\ImportedMenuItem;
use LumoraPress\Services\Import\ImportedPage;
use LumoraPress\Services\Import\ImportedPost;
use LumoraPress\Services\Import\ImportedUser;
use LumoraPress\Services\Import\ImportedWidgetInstance;
use RuntimeException;

/**
 * Encoding and decoding live side by side so the two can't drift apart.
 * Decoding treats the manifest as untrusted input: unknown enum values
 * fall back to a safe default and media paths that could escape the
 * extraction directory are neutralized, never followed.
 */
final class ExportManifest
{
    public const FORMAT = 'lumora-press-content-export';

    /** Bump when a change would make an older importer misread the file. */
    public const FORMAT_VERSION = 1;

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @return array<string, mixed>
     */
    public static function encode(ExportContent $content): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'generator' => ['name' => 'Lumora Press', 'version' => $content->generatorVersion],
            'exported_at' => $content->exportedAt->format(DATE_ATOM),
            'site' => ['name' => $content->siteName, 'url' => $content->siteUrl, 'uploads_url' => $content->uploadsUrl],
            'content_types' => $content->contentTypes,
            'includes_uploads' => $content->includesUploads,
            'users' => array_map(static fn (ImportedUser $user): array => [
                'id' => (int) $user->externalId,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role->value,
                'display_name' => $user->displayName,
            ], $content->users),
            'categories' => array_map(static fn (array $category): array => [
                'id' => $category['id'],
                'name' => $category['name'],
                'slug' => $category['slug'],
                'description' => $category['description'],
                'parent_id' => $category['parentId'],
                'image_id' => $category['imageId'],
                'archive_display_mode' => $category['archiveDisplayMode'],
            ], $content->categories),
            'tags' => $content->tags,
            'folders' => array_map(static fn (array $folder): array => [
                'id' => $folder['id'],
                'name' => $folder['name'],
                'parent_id' => $folder['parentId'],
            ], $content->folders),
            'media' => array_map(static fn (ImportedMedia $media): array => [
                'id' => (int) $media->externalId,
                'file_path' => $content->mediaFiles[(int) $media->externalId]['filePath'] ?? '',
                'mime_type' => $content->mediaFiles[(int) $media->externalId]['mimeType'] ?? '',
                'file_name' => $media->fileName,
                'alt_text' => $media->altText,
                'caption' => $media->caption,
                'description' => $media->description,
                'folder_id' => $media->folderId,
                'uploaded_by' => $media->uploadedByUserId,
                'uploaded_at' => $media->uploadedAt?->format(self::DATE_FORMAT),
            ], $content->media),
            'pages' => array_map(static fn (ImportedPage $page): array => [
                'id' => (int) $page->externalId,
                'title' => $page->title,
                'slug' => $page->slug,
                'content' => $page->content,
                'content_format' => $page->contentFormat->value,
                'excerpt' => $page->excerpt,
                'status' => $page->status->value,
                'visibility' => ($page->visibility ?? PageVisibility::Public)->value,
                'comments_open' => $page->commentsOpen ?? true,
                'author_id' => $page->authorId,
                'parent_id' => $page->parentExternalId !== null ? (int) $page->parentExternalId : null,
                'featured_image_id' => $page->featuredImageId,
                'featured_image_crop' => $page->featuredImageCrop,
                'published_at' => $page->publishedAt?->format(self::DATE_FORMAT),
                'meta_title' => $page->metaTitle,
                'meta_description' => $page->metaDescription,
            ], $content->pages),
            'posts' => array_map(static fn (ImportedPost $post): array => [
                'id' => (int) $post->externalId,
                'title' => $post->title,
                'slug' => $post->slug,
                'content' => $post->content,
                'content_format' => $post->contentFormat->value,
                'excerpt' => $post->excerpt,
                'status' => $post->status->value,
                'visibility' => $post->visibility->value,
                'comments_open' => $post->commentsOpen,
                'is_sticky' => $post->isSticky,
                'author_id' => $post->authorId,
                'featured_image_id' => $post->featuredImageId,
                'featured_image_crop' => $post->featuredImageCrop,
                'published_at' => $post->publishedAt?->format(self::DATE_FORMAT),
                'unpublish_at' => $post->unpublishAt?->format(self::DATE_FORMAT),
                'category_ids' => $post->categoryIds,
                'categories' => $post->categories,
                'tags' => $post->tags,
                'meta' => $post->meta,
                'meta_title' => $post->metaTitle,
                'meta_description' => $post->metaDescription,
            ], $content->posts),
            'comments' => array_map(static fn (ImportedComment $comment): array => [
                'id' => (int) $comment->externalId,
                'post_id' => $comment->postId,
                'page_id' => $comment->pageId,
                'parent_id' => $comment->parentExternalId !== null ? (int) $comment->parentExternalId : null,
                'user_id' => $comment->userId,
                'guest_name' => $comment->guestName,
                'guest_email' => $comment->guestEmail,
                'guest_url' => $comment->guestUrl,
                'content' => $comment->content,
                'status' => $comment->status->value,
                'created_at' => $comment->commentedAt?->format(self::DATE_FORMAT),
            ], $content->comments),
            'menus' => array_map(static fn (ImportedMenu $menu): array => [
                'id' => $menu->externalId,
                'name' => $menu->name,
                'items' => array_map(static fn (ImportedMenuItem $item): array => [
                    'id' => $item->externalId,
                    'parent_id' => $item->parentExternalId,
                    'label' => $item->label,
                    'url' => $item->url,
                    'target' => $item->target,
                    'css_class' => $item->cssClass,
                    'rel' => $item->rel,
                    'title_attribute' => $item->titleAttribute,
                    'hidden' => $item->hidden,
                ], $menu->items),
            ], $content->menus),
            'menu_locations' => (object) $content->menuLocations,
            'widgets' => array_map(static fn (ImportedWidgetInstance $widget): array => [
                'id' => $widget->externalId,
                'type' => $widget->type,
                'sidebar' => $widget->targetSidebarId,
                'order' => $widget->order,
                'settings' => (object) $widget->settings,
            ], $content->widgets),
        ];
    }

    /**
     * $filesRoot is the directory an export's uploads/ folder was
     * extracted into; each media item's absolutePath points beneath it,
     * whether or not the file is actually there.
     *
     * @param array<string, mixed> $data A json_decode()d manifest, as an associative array.
     */
    public static function decode(array $data, string $filesRoot): ExportContent
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('This file is not a Lumora Press content export.');
        }

        if (!is_int($data['format_version'] ?? null) || $data['format_version'] > self::FORMAT_VERSION) {
            throw new RuntimeException('This export was made by a newer version of Lumora Press. Update this site first, then try again.');
        }

        $site = self::arr($data, 'site');
        $filesRoot = rtrim($filesRoot, '/');
        $media = [];
        $mediaFiles = [];

        foreach (self::rows($data, 'media') as $row) {
            $id = self::int($row, 'id');
            $filePath = self::safeRelativePath(self::str($row, 'file_path'));
            $directory = $filePath !== '' ? dirname($filePath) : '.';

            $media[] = new ImportedMedia(
                absolutePath: $filePath !== '' ? $filesRoot . '/' . $filePath : '',
                uploadedByUserId: self::int($row, 'uploaded_by'),
                fileName: self::nullableStr($row, 'file_name'),
                altText: self::nullableStr($row, 'alt_text'),
                caption: self::nullableStr($row, 'caption'),
                description: self::nullableStr($row, 'description'),
                folderId: self::nullableInt($row, 'folder_id'),
                externalId: (string) $id,
                uploadedAt: self::date($row, 'uploaded_at'),
                relativeDirectory: $directory !== '.' ? $directory : null,
            );
            $mediaFiles[$id] = ['filePath' => $filePath, 'mimeType' => self::str($row, 'mime_type')];
        }

        return new ExportContent(
            siteName: self::str($site, 'name'),
            siteUrl: rtrim(self::str($site, 'url'), '/'),
            uploadsUrl: rtrim(self::str($site, 'uploads_url'), '/'),
            generatorVersion: self::str(self::arr($data, 'generator'), 'version'),
            exportedAt: DateTimeImmutable::createFromFormat(DATE_ATOM, self::str($data, 'exported_at')) ?: new DateTimeImmutable(),
            contentTypes: array_values(array_filter(self::arr($data, 'content_types'), 'is_string')),
            includesUploads: (bool) ($data['includes_uploads'] ?? false),
            users: array_map(static fn (array $row): ImportedUser => new ImportedUser(
                username: self::str($row, 'username'),
                email: self::str($row, 'email'),
                role: UserRole::tryFrom(self::str($row, 'role')) ?? UserRole::Subscriber,
                displayName: self::nullableStr($row, 'display_name'),
                externalId: (string) self::int($row, 'id'),
            ), self::rows($data, 'users')),
            categories: array_map(static fn (array $row): array => [
                'id' => self::int($row, 'id'),
                'name' => self::str($row, 'name'),
                'slug' => self::str($row, 'slug'),
                'description' => self::str($row, 'description'),
                'parentId' => self::nullableInt($row, 'parent_id'),
                'imageId' => self::nullableInt($row, 'image_id'),
                'archiveDisplayMode' => self::nullableStr($row, 'archive_display_mode'),
            ], self::rows($data, 'categories')),
            tags: array_map(static fn (array $row): array => [
                'id' => self::int($row, 'id'),
                'name' => self::str($row, 'name'),
                'slug' => self::str($row, 'slug'),
                'description' => self::str($row, 'description'),
            ], self::rows($data, 'tags')),
            folders: array_map(static fn (array $row): array => [
                'id' => self::int($row, 'id'),
                'name' => self::str($row, 'name'),
                'parentId' => self::nullableInt($row, 'parent_id'),
            ], self::rows($data, 'folders')),
            media: $media,
            mediaFiles: $mediaFiles,
            pages: array_map(static fn (array $row): ImportedPage => new ImportedPage(
                title: self::str($row, 'title'),
                content: self::str($row, 'content'),
                excerpt: self::str($row, 'excerpt'),
                authorId: self::int($row, 'author_id'),
                status: self::pageStatus(self::str($row, 'status')),
                publishedAt: self::date($row, 'published_at'),
                parentExternalId: self::nullableInt($row, 'parent_id') !== null ? (string) self::int($row, 'parent_id') : null,
                featuredImageId: self::nullableInt($row, 'featured_image_id'),
                slug: self::nullableStr($row, 'slug'),
                contentFormat: ContentFormat::tryFrom(self::str($row, 'content_format')) ?? ContentFormat::Html,
                featuredImageCrop: self::crop($row),
                externalId: (string) self::int($row, 'id'),
                visibility: PageVisibility::tryFrom(self::str($row, 'visibility')) ?? PageVisibility::Public,
                commentsOpen: (bool) ($row['comments_open'] ?? true),
                metaTitle: self::nullableStr($row, 'meta_title'),
                metaDescription: self::nullableStr($row, 'meta_description'),
            ), self::rows($data, 'pages')),
            posts: array_map(static fn (array $row): ImportedPost => new ImportedPost(
                title: self::str($row, 'title'),
                content: self::str($row, 'content'),
                excerpt: self::str($row, 'excerpt'),
                authorId: self::int($row, 'author_id'),
                status: self::postStatus(self::str($row, 'status')),
                publishedAt: self::date($row, 'published_at'),
                featuredImageId: self::nullableInt($row, 'featured_image_id'),
                slug: self::nullableStr($row, 'slug'),
                commentsOpen: (bool) ($row['comments_open'] ?? true),
                contentFormat: ContentFormat::tryFrom(self::str($row, 'content_format')) ?? ContentFormat::Html,
                featuredImageCrop: self::crop($row),
                visibility: PostVisibility::tryFrom(self::str($row, 'visibility')) ?? PostVisibility::Public,
                isSticky: (bool) ($row['is_sticky'] ?? false),
                unpublishAt: self::date($row, 'unpublish_at'),
                categories: self::strings($row, 'categories'),
                tags: self::strings($row, 'tags'),
                meta: self::meta($row),
                externalId: (string) self::int($row, 'id'),
                categoryIds: array_values(array_filter(self::arr($row, 'category_ids'), 'is_int')),
                metaTitle: self::nullableStr($row, 'meta_title'),
                metaDescription: self::nullableStr($row, 'meta_description'),
            ), self::rows($data, 'posts')),
            comments: array_map(static fn (array $row): ImportedComment => new ImportedComment(
                postId: self::nullableInt($row, 'post_id'),
                content: self::str($row, 'content'),
                guestName: self::str($row, 'guest_name'),
                guestEmail: self::str($row, 'guest_email'),
                status: CommentStatus::tryFrom(self::str($row, 'status')) ?? CommentStatus::Pending,
                guestUrl: self::nullableStr($row, 'guest_url'),
                userId: self::nullableInt($row, 'user_id'),
                parentExternalId: self::nullableInt($row, 'parent_id') !== null ? (string) self::int($row, 'parent_id') : null,
                externalId: (string) self::int($row, 'id'),
                commentedAt: self::date($row, 'created_at'),
                pageId: self::nullableInt($row, 'page_id'),
            ), self::rows($data, 'comments')),
            menus: array_map(static fn (array $row): ImportedMenu => new ImportedMenu(
                name: self::str($row, 'name'),
                items: array_values(array_map(static fn (array $item, int $order): ImportedMenuItem => new ImportedMenuItem(
                    label: self::str($item, 'label'),
                    url: self::str($item, 'url'),
                    order: $order,
                    target: self::str($item, 'target') === '_blank' ? '_blank' : '_self',
                    cssClass: self::str($item, 'css_class'),
                    rel: self::str($item, 'rel'),
                    titleAttribute: self::str($item, 'title_attribute'),
                    parentExternalId: self::nullableStr($item, 'parent_id'),
                    externalId: self::nullableStr($item, 'id'),
                    hidden: (bool) ($item['hidden'] ?? false),
                ), self::rows($row, 'items'), array_keys(self::rows($row, 'items')))),
                externalId: self::nullableStr($row, 'id'),
            ), self::rows($data, 'menus')),
            menuLocations: array_filter(self::arr($data, 'menu_locations'), static fn (mixed $menuId, mixed $location): bool => is_string($menuId) && is_string($location), ARRAY_FILTER_USE_BOTH),
            widgets: array_map(static fn (array $row): ImportedWidgetInstance => new ImportedWidgetInstance(
                type: self::str($row, 'type'),
                settings: self::arr($row, 'settings'),
                targetSidebarId: self::str($row, 'sidebar'),
                order: self::int($row, 'order'),
                externalId: self::nullableStr($row, 'id'),
            ), array_values(array_filter(self::rows($data, 'widgets'), static fn (array $row): bool => self::str($row, 'type') !== ''))),
        );
    }

    /**
     * Drops empty, "." and ".." segments and leading slashes, so a
     * crafted manifest can never point outside the extraction directory.
     */
    public static function safeRelativePath(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        );

        return implode('/', $segments);
    }

    /**
     * Imported content is never restored straight into the Trash.
     */
    private static function postStatus(string $value): PostStatus
    {
        $status = PostStatus::tryFrom($value) ?? PostStatus::Draft;

        return $status === PostStatus::Trashed ? PostStatus::Draft : $status;
    }

    private static function pageStatus(string $value): PageStatus
    {
        $status = PageStatus::tryFrom($value) ?? PageStatus::Draft;

        return $status === PageStatus::Trashed ? PageStatus::Draft : $status;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private static function crop(array $row): ?array
    {
        $crop = $row['featured_image_crop'] ?? null;

        if (!is_array($crop)) {
            return null;
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!is_int($crop[$key] ?? null)) {
                return null;
            }
        }

        return ['x' => $crop['x'], 'y' => $crop['y'], 'width' => $crop['width'], 'height' => $crop['height']];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array{key: string, value: string}>
     */
    private static function meta(array $row): array
    {
        $pairs = [];

        foreach (self::rows($row, 'meta') as $pair) {
            $key = self::str($pair, 'key');

            if ($key !== '') {
                $pairs[] = ['key' => $key, 'value' => self::str($pair, 'value')];
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private static function rows(array $row, string $key): array
    {
        return array_values(array_filter(self::arr($row, $key), 'is_array'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<mixed>
     */
    private static function arr(array $row, string $key): array
    {
        return is_array($row[$key] ?? null) ? $row[$key] : [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private static function strings(array $row, string $key): array
    {
        return array_values(array_filter(self::arr($row, $key), 'is_string'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nullableStr(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function int(array $row, string $key): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nullableInt(array $row, string $key): ?int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function date(array $row, string $key): ?DateTimeImmutable
    {
        $value = self::str($row, $key);

        if ($value === '') {
            return null;
        }

        return DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value) ?: null;
    }
}
