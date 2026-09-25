<?php

/**
 * The format-agnostic snapshot of a site's content that every export format writer consumes.
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
use LumoraPress\Services\Import\ImportedComment;
use LumoraPress\Services\Import\ImportedMedia;
use LumoraPress\Services\Import\ImportedMenu;
use LumoraPress\Services\Import\ImportedPage;
use LumoraPress\Services\Import\ImportedPost;
use LumoraPress\Services\Import\ImportedUser;
use LumoraPress\Services\Import\ImportedWidgetInstance;

/**
 * Reuses the Imported* DTOs so the native importer can feed them straight
 * back through the existing *Importer classes. Unlike an import, though,
 * every id in here — authorId, featuredImageId, categoryIds, a comment's
 * postId/pageId/userId, a media item's uploadedByUserId/folderId — is the
 * *exporting* site's own id, and each object's externalId is that same id
 * as a string. Whoever imports the payload is responsible for remapping
 * them to local ids.
 */
final class ExportContent
{
    /**
     * @param array<int, string> $contentTypes ExportOptions content types this payload was built with.
     * @param array<int, ImportedUser> $users
     * @param array<int, array{id: int, name: string, slug: string, description: string, parentId: ?int, imageId: ?int, archiveDisplayMode: ?string}> $categories Parents before children.
     * @param array<int, array{id: int, name: string, slug: string, description: string}> $tags
     * @param array<int, array{id: int, name: string, parentId: ?int}> $folders Parents before children.
     * @param array<int, ImportedMedia> $media absolutePath is where the file currently lives (it may not exist).
     * @param array<int, array{filePath: string, mimeType: string}> $mediaFiles Keyed by source media id; filePath is relative to the uploads root.
     * @param array<int, ImportedPage> $pages Parents before children.
     * @param array<int, ImportedPost> $posts
     * @param array<int, ImportedComment> $comments Parents before replies.
     * @param array<int, ImportedMenu> $menus
     * @param array<string, string> $menuLocations Theme location slug => menu externalId.
     * @param array<int, ImportedWidgetInstance> $widgets targetSidebarId is the exporting site's sidebar id.
     */
    public function __construct(
        public readonly string $siteName,
        public readonly string $siteUrl,
        public readonly string $uploadsUrl,
        public readonly string $generatorVersion,
        public readonly DateTimeImmutable $exportedAt,
        public readonly array $contentTypes,
        public readonly bool $includesUploads,
        public readonly array $users = [],
        public readonly array $categories = [],
        public readonly array $tags = [],
        public readonly array $folders = [],
        public readonly array $media = [],
        public readonly array $mediaFiles = [],
        public readonly array $pages = [],
        public readonly array $posts = [],
        public readonly array $comments = [],
        public readonly array $menus = [],
        public readonly array $menuLocations = [],
        public readonly array $widgets = [],
    ) {
    }

    /**
     * The same payload with a replacement media list — e.g. with each
     * item's absolutePath re-pointed at wherever its file was found.
     *
     * @param array<int, ImportedMedia> $media
     */
    public function withMedia(array $media): self
    {
        return new self(
            siteName: $this->siteName,
            siteUrl: $this->siteUrl,
            uploadsUrl: $this->uploadsUrl,
            generatorVersion: $this->generatorVersion,
            exportedAt: $this->exportedAt,
            contentTypes: $this->contentTypes,
            includesUploads: $this->includesUploads,
            users: $this->users,
            categories: $this->categories,
            tags: $this->tags,
            folders: $this->folders,
            media: $media,
            mediaFiles: $this->mediaFiles,
            pages: $this->pages,
            posts: $this->posts,
            comments: $this->comments,
            menus: $this->menus,
            menuLocations: $this->menuLocations,
            widgets: $this->widgets,
        );
    }

    public function includes(string $contentType): bool
    {
        return in_array($contentType, $this->contentTypes, true);
    }

    /**
     * A filename-safe version of the site name, for download filenames.
     */
    public function siteSlug(): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->siteName)), '-');

        return $slug !== '' ? $slug : 'site';
    }
}
