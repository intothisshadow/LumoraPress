<?php

/**
 * Orchestrates a full WordPress-site import (users, categories/tags, media, pages, posts, comments) via the shared Core import layer (LPP-004).
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\WordPressImporter;

use DateTimeImmutable;
use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\Downloads\DownloadType;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\Import\CommentImporter;
use LumoraPress\Services\Import\ImportedComment;
use LumoraPress\Services\Import\ImportedMedia;
use LumoraPress\Services\Import\ImportedMenu;
use LumoraPress\Services\Import\ImportedMenuItem;
use LumoraPress\Services\Import\ImportedPage;
use LumoraPress\Services\Import\ImportedPost;
use LumoraPress\Services\Import\ImportedUser;
use LumoraPress\Services\Import\ImportedWidgetInstance;
use LumoraPress\Services\Import\MediaImporter;
use LumoraPress\Services\Import\MenuImporter;
use LumoraPress\Services\Import\PageImporter;
use LumoraPress\Services\Import\PostImporter;
use LumoraPress\Services\Import\UserImporter;
use LumoraPress\Services\Import\WidgetImporter;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\MediaStatsService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\RedirectService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\UserService;
use RuntimeException;
use Throwable;

/**
 * The orchestrator DummyContentGenerator (LPP-005) plays for synthetic
 * data — this class plays the same role for a real source: a
 * WordPressSource read connection plus the same batch-tagged
 * import()/importOrReuse() calls into the shared Importer layer.
 *
 * Ordering mirrors the dependency chain the Importer layer already
 * requires: Users -> Categories/Tags -> Media (attachments) -> Downloads
 * (Simple Download Monitor's own post type, folder-organized via its
 * `sdm_categories` taxonomy — see importDownloads()) -> Pages -> Posts
 * -> Comments (Posts only — see this class's own docblock on
 * comments()) -> Menus -> Widgets (Stage 8 — see importMenus()/
 * importWidgets()). Media is imported before Pages/Posts specifically so
 * in-content `<img>` URLs pointing at the old site can be rewritten to
 * the new local media URL while content is being built, not as a
 * separate pass afterward. Menus/Widgets are imported last because menu
 * items need Pages/Posts/Categories already imported (to resolve a menu
 * item's real permalink) and widgets need nothing but are ordered after
 * for symmetry with Stage 8's own "Menus, Widgets" naming.
 *
 * Scope for this first pass (see LPP-004's own TODO entry for the full
 * list of what's deferred): no WXR .xml path, no site settings
 * write-back, no dry-run/resume/overwrite-existing semantics.
 * Idempotency follows DummyContentGenerator's own hard guard — run()
 * refuses to start while a previous 'wordpress_import' batch still
 * exists; removeAll() must be called first.
 */
final class WordPressImportService
{
    public const SOURCE = 'wordpress_import';

    /**
     * WordPress attachments always carry post_status = 'inherit',
     * regardless of the parent content's own status — never part of the
     * caller-selectable $statuses option.
     */
    private const ATTACHMENT_STATUSES = ['inherit'];

    /** @var array<int, string> */
    private array $warnings = [];

    /**
     * $source is nullable specifically so removeAll()/lastImportSummary()
     * — pure ContentImportRegistry lookups that never touch the source
     * WordPress database at all — can be used without opening a real
     * database connection first (WordPressSource::connect() connects
     * eagerly). Only run() actually requires it.
     */
    public function __construct(
        private readonly ?WordPressSource $source,
        private readonly UserImporter $userImporter,
        private readonly PostImporter $postImporter,
        private readonly PageImporter $pageImporter,
        private readonly MediaImporter $mediaImporter,
        private readonly CommentImporter $commentImporter,
        private readonly MenuImporter $menuImporter,
        private readonly WidgetImporter $widgetImporter,
        private readonly UserService $users,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly MediaService $media,
        private readonly MediaStatsService $mediaStats,
        private readonly CommentService $comments,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly FolderService $folders,
        private readonly RedirectService $redirects,
        private readonly MenuManager $menus,
        private readonly WidgetManager $widgets,
        private readonly PressConfig $config,
        private readonly ContentImportRegistry $registry,
        private readonly string $sourceUploadsPath,
        /**
         * Null when the Downloads plugin (LPP-008) isn't active — every
         * download still imports as a plain Media item/Redirect exactly
         * as before, just without the new `downloads` table's own
         * identity/metadata row on top. See importDownloads()'s own
         * docblock.
         */
        private readonly ?DownloadService $downloads = null,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function existingBatchIds(): array
    {
        return $this->registry->batchesForSource(self::SOURCE);
    }

    /**
     * @return array{batchId: string, createdAt: ?DateTimeImmutable, counts: array<string, int>}|null
     */
    public function lastImportSummary(): ?array
    {
        $batchIds = $this->existingBatchIds();

        if ($batchIds === []) {
            return null;
        }

        $batchId = $batchIds[0];

        return [
            'batchId' => $batchId,
            'createdAt' => $this->registry->batchCreatedAt($batchId),
            'counts' => $this->registry->countsForBatch($batchId),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array{users?: bool, categories?: bool, media?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, statuses?: array<int, string>} $options
     * @return array<string, int>
     */
    public function run(array $options): array
    {
        if ($this->source === null) {
            throw new RuntimeException('No source database connection was provided.');
        }

        if ($this->existingBatchIds() !== []) {
            throw new RuntimeException('A WordPress import already exists. Remove it before importing again.');
        }

        $this->warnings = [];
        $statuses = $options['statuses'] ?? ['publish', 'draft', 'pending', 'future', 'private'];
        $batchId = $this->registry->newBatch();

        $wpUserIdToLocalId = ($options['users'] ?? true) ? $this->importUsers($batchId) : [];

        $wpCategoryTermIdToLocalId = [];
        $wpTagTermIdToLocalId = [];

        if ($options['categories'] ?? true) {
            $wpCategoryTermIdToLocalId = $this->importCategories($batchId);
            $wpTagTermIdToLocalId = $this->importTags($batchId);
        }

        [$wpAttachmentIdToLocalMediaId, $oldUrlToNewUrl] = ($options['media'] ?? true)
            ? $this->importMedia($batchId, $wpUserIdToLocalId)
            : [[], []];

        if ($options['downloads'] ?? true) {
            $this->importDownloads($batchId, $wpUserIdToLocalId);
        }

        $wpPageIdToLocalId = ($options['pages'] ?? true)
            ? $this->importPages($batchId, $statuses, $wpUserIdToLocalId, $wpAttachmentIdToLocalMediaId, $oldUrlToNewUrl)
            : [];

        $wpPostIdToLocalId = ($options['posts'] ?? true)
            ? $this->importPosts($batchId, $statuses, $wpUserIdToLocalId, $wpAttachmentIdToLocalMediaId, $oldUrlToNewUrl)
            : [];

        if (($options['comments'] ?? true) && $wpPostIdToLocalId !== []) {
            $this->importComments($batchId, $wpPostIdToLocalId, $wpUserIdToLocalId);
        }

        if ($options['menus'] ?? true) {
            $this->importMenus($batchId, $wpPageIdToLocalId, $wpPostIdToLocalId, $wpCategoryTermIdToLocalId, $wpTagTermIdToLocalId);
        }

        if ($options['widgets'] ?? true) {
            $this->importWidgets($batchId);
        }

        return $this->registry->countsForBatch($batchId);
    }

    /**
     * Deletion order mirrors DummyContentGenerator::removeAll()'s own
     * docblock reasoning: comments before the posts they belong to,
     * posts/pages before their authors, media/categories/tags last.
     *
     * Menus/widgets have no per-id delete path at all — see
     * ContentImportRegistry::record()'s own docblock on why — so instead
     * of appearing in the id-loop below, their pre-import
     * `nav_menus`/`widgets_config` option snapshots (recorded by
     * importMenus()/importWidgets()) are written straight back,
     * restoring exactly what was there before the import ran, whether
     * that was nothing or a site's own existing menus/widgets.
     *
     * @return array<string, int>
     */
    public function removeAll(): array
    {
        $totals = [];

        foreach ($this->existingBatchIds() as $batchId) {
            foreach ($this->registry->countsForBatch($batchId) as $type => $count) {
                $totals[$type] = ($totals[$type] ?? 0) + $count;
            }

            $navMenusSnapshot = $this->registry->snapshotForBatch($batchId, 'nav_menus_snap');

            if ($navMenusSnapshot !== null) {
                $this->config->setOption('nav_menus', $navMenusSnapshot);
            }

            $widgetsSnapshot = $this->registry->snapshotForBatch($batchId, 'widgets_snap');

            if ($widgetsSnapshot !== null) {
                $this->config->setOption('widgets_config', $widgetsSnapshot);
            }

            foreach (['comment', 'post', 'page', 'download', 'media', 'redirect', 'folder', 'category', 'tag', 'user'] as $contentType) {
                foreach ($this->registry->idsForBatch($batchId, $contentType) as $entry) {
                    $this->deleteOne($entry['contentType'], $entry['contentId']);
                }
            }

            $this->registry->clearBatch($batchId);
        }

        return $totals;
    }

    private function deleteOne(string $contentType, int $id): void
    {
        match ($contentType) {
            'comment' => $this->comments->delete($id),
            'post' => $this->posts->delete($id),
            'page' => $this->pages->delete($id),
            // Null when the Downloads plugin was deactivated between
            // import and rollback — the row is simply left behind in
            // that case (its own underlying Media/Redirect still gets
            // cleaned up by the 'media'/'redirect' entries below), since
            // there's no way to delete it correctly without that
            // plugin's own class loaded.
            'download' => $this->downloads?->delete($id),
            'media' => $this->media->delete($id),
            'redirect' => $this->redirects->delete($id),
            'folder' => $this->folders->delete($id),
            'category' => $this->categories->delete($id),
            'tag' => $this->tags->delete($id),
            'user' => $this->users->delete($id),
            default => null,
        };
    }

    /**
     * @return array<int, int> wpUserId => local user id
     */
    private function importUsers(string $batchId): array
    {
        $map = [];

        foreach ($this->source->users() as $wpUser) {
            $data = new ImportedUser(
                username: $wpUser['user_login'],
                email: $wpUser['user_email'],
                role: $this->source->userRole($wpUser['ID']),
                displayName: $wpUser['display_name'] !== '' ? $wpUser['display_name'] : null,
                externalId: (string) $wpUser['ID'],
                registeredAt: $this->parseWpDate($wpUser['user_registered']),
            );

            $user = $this->userImporter->importOrReuse($batchId, self::SOURCE, $data);
            $map[$wpUser['ID']] = $user->id;
        }

        return $map;
    }

    /**
     * Categories preserve WordPress parent/child hierarchy via a
     * multi-pass resolution (repeatedly creating whatever's parent is
     * already resolved) rather than assuming the source query's
     * `ORDER BY parent ASC` is already a full topological order, which
     * it isn't for hierarchies deeper than two levels. A parent
     * reference that never resolves (a taxonomy oddity, not a normal
     * WordPress state) falls back to top-level rather than being
     * dropped. Tags have no hierarchy, so they need no such pass — see
     * importTags().
     *
     * Post/page category *assignment* itself needs no id map at all:
     * WordPressSource::termNamesForPost() returns category names
     * directly, and PostImporter already resolves names via
     * CategoryService::findOrCreateByName() (which will find the exact
     * rows created here by name) — see PostImporter's own docblock.
     *
     * The returned map is used by importMenus() (Stage 8) to resolve a
     * menu item pointing at a category term.
     *
     * @return array<int, int> wpTermId => local category id
     */
    private function importCategories(string $batchId): array
    {
        $remaining = $this->source->terms('category');
        $localIdByWpTermId = [];

        while ($remaining !== []) {
            $stillRemaining = [];
            $progressed = false;

            foreach ($remaining as $term) {
                if ($term['parent'] === 0) {
                    $parentId = null;
                } elseif (isset($localIdByWpTermId[$term['parent']])) {
                    $parentId = $localIdByWpTermId[$term['parent']];
                } else {
                    $stillRemaining[] = $term;
                    continue;
                }

                $category = $this->categories->create($term['name'], '', $parentId);
                $this->registry->record($batchId, self::SOURCE, 'category', $category->id);
                $localIdByWpTermId[$term['term_id']] = $category->id;
                $progressed = true;
            }

            if (!$progressed) {
                // Every remaining term's parent id doesn't exist in the
                // 'category' taxonomy at all (data oddity) — create the
                // rest top-level rather than looping forever.
                foreach ($stillRemaining as $term) {
                    $category = $this->categories->create($term['name'], '', null);
                    $this->registry->record($batchId, self::SOURCE, 'category', $category->id);
                    $localIdByWpTermId[$term['term_id']] = $category->id;
                }

                $stillRemaining = [];
            }

            $remaining = $stillRemaining;
        }

        return $localIdByWpTermId;
    }

    /**
     * The returned map is used by importMenus() (Stage 8) to resolve a
     * menu item pointing at a tag term.
     *
     * @return array<int, int> wpTermId => local tag id
     */
    private function importTags(string $batchId): array
    {
        $localIdByWpTermId = [];

        foreach ($this->source->terms('post_tag') as $term) {
            $tag = $this->tags->findOrCreateByName($term['name']);
            $this->registry->record($batchId, self::SOURCE, 'tag', $tag->id);
            $localIdByWpTermId[$term['term_id']] = $tag->id;
        }

        return $localIdByWpTermId;
    }

    /**
     * Media Manager Folders imported from `sdm_categories` (Simple
     * Download Monitor's own taxonomy for grouping downloads — the same
     * category names its `[sdm_show_dl_from_category]` shortcode
     * grouped by) — the folder feature already supports the parent/child
     * hierarchy SDM's categories actually use, so this needs no new
     * taxonomy concept, just the same multi-pass hierarchy resolution
     * importCategories() already does.
     *
     * @return array<int, int> wpTermId => local folder id
     */
    private function importFolders(string $batchId): array
    {
        $remaining = $this->source->terms('sdm_categories');
        $localIdByWpTermId = [];

        while ($remaining !== []) {
            $stillRemaining = [];
            $progressed = false;

            foreach ($remaining as $term) {
                if ($term['parent'] === 0) {
                    $parentId = null;
                } elseif (isset($localIdByWpTermId[$term['parent']])) {
                    $parentId = $localIdByWpTermId[$term['parent']];
                } else {
                    $stillRemaining[] = $term;
                    continue;
                }

                $folder = $this->folders->create($term['name'], $parentId);
                $this->registry->record($batchId, self::SOURCE, 'folder', $folder->id);
                $localIdByWpTermId[$term['term_id']] = $folder->id;
                $progressed = true;
            }

            if (!$progressed) {
                foreach ($stillRemaining as $term) {
                    $folder = $this->folders->create($term['name'], null);
                    $this->registry->record($batchId, self::SOURCE, 'folder', $folder->id);
                    $localIdByWpTermId[$term['term_id']] = $folder->id;
                }

                $stillRemaining = [];
            }

            $remaining = $stillRemaining;
        }

        return $localIdByWpTermId;
    }

    /**
     * Simple Download Monitor's own post type — files (and download
     * links) organized by `sdm_categories`. A download whose file
     * already lives on the source's own site becomes a real Media
     * Manager item, filed into the matching Folder. A download that
     * only points at an external URL (e.g. a GitHub release — common
     * when a host blocks .zip uploads, one real reason found in this
     * ticket's own source site) has no local file to import at all;
     * it becomes a Redirect instead, giving it a stable local URL and a
     * real hit counter (`SiteController` already calls
     * `RedirectService::recordHit()` on every redirect) without ever
     * hosting the file itself. Either way, the migrated item's download
     * count is seeded from Simple Download Monitor's own total
     * (`WordPressSource::sdmDownloadStats()` — its `sdm_count_offset`
     * plus its own download-event log, added directly since neither the
     * new Media item nor the new Redirect has any download history of
     * its own yet) rather than silently restarting at 0.
     *
     * When the Downloads plugin (LPP-008) is active, each migrated
     * download also gets a real `downloads` table row on top of its
     * Media/Redirect (via `DownloadService::recordExisting()`, not
     * `create()` — the Media/Redirect already exists by this point, so
     * `create()` would upload the file or create the redirect a second
     * time) so it appears on the Downloads admin screen, not just Media
     * Manager/Settings > Redirects.
     *
     * @param array<int, int> $wpUserIdToLocalId
     */
    private function importDownloads(string $batchId, array $wpUserIdToLocalId): void
    {
        $wpFolderIdByWpTermId = $this->importFolders($batchId);

        foreach ($this->source->posts(['sdm_downloads'], ['publish']) as $download) {
            $meta = $this->source->postMeta($download['ID']);
            $uploadUrl = $meta['sdm_upload'] ?? null;

            if ($uploadUrl === null || $uploadUrl === '') {
                $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): no file/URL recorded, skipped.";
                continue;
            }

            $folderId = $this->resolveDownloadFolder($download['ID'], $wpFolderIdByWpTermId);
            $authorId = $wpUserIdToLocalId[$download['post_author']] ?? 1;
            $stats = $this->source->sdmDownloadStats($download['ID']);
            $description = ($meta['sdm_description'] ?? '') !== '' ? $meta['sdm_description'] : '';

            $uploadsMarker = '/wp-content/uploads/';

            if (str_contains($uploadUrl, $uploadsMarker)) {
                $relativePath = ltrim(strstr($uploadUrl, $uploadsMarker) ?: '', '/');
                $relativePath = substr($relativePath, strlen('wp-content/uploads/'));
                $absolutePath = rtrim($this->sourceUploadsPath, '/') . '/' . $relativePath;

                if (!is_file($absolutePath)) {
                    $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): file not found at {$absolutePath}, skipped.";
                    continue;
                }

                $data = new ImportedMedia(
                    absolutePath: $absolutePath,
                    uploadedByUserId: $authorId,
                    fileName: basename($relativePath),
                    description: $description !== '' ? $description : null,
                    folderId: $folderId,
                    externalId: (string) $download['ID'],
                    uploadedAt: $this->parseWpDate($download['post_date']),
                    relativeDirectory: dirname($relativePath),
                );

                try {
                    $media = $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data);
                    $this->mediaStats->seed((int) $media['id'], $stats['count'], $stats['lastDownloadedAt']);

                    if ($this->downloads !== null) {
                        $newDownload = $this->downloads->recordExisting($download['post_title'], $description, $folderId, DownloadType::File, (int) $media['id'], null);
                        $this->registry->record($batchId, self::SOURCE, 'download', $newDownload->id, (string) $download['ID']);
                    }
                } catch (Throwable $exception) {
                    $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): {$exception->getMessage()}";
                }

                continue;
            }

            $slug = $download['post_name'] !== '' ? $download['post_name'] : ('download-' . $download['ID']);

            try {
                $redirect = $this->redirects->create('/downloads/' . $slug, $uploadUrl, folderId: $folderId);
                $this->redirects->setHitCount((int) $redirect['id'], $stats['count']);
                $this->registry->record($batchId, self::SOURCE, 'redirect', (int) $redirect['id'], (string) $download['ID']);

                if ($this->downloads !== null) {
                    $newDownload = $this->downloads->recordExisting($download['post_title'], $description, $folderId, DownloadType::Url, null, (int) $redirect['id']);
                    $this->registry->record($batchId, self::SOURCE, 'download', $newDownload->id, (string) $download['ID']);
                }
            } catch (Throwable $exception) {
                $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): {$exception->getMessage()}";
            }
        }
    }

    /**
     * Prefers a child/leaf sdm_categories term over a parent one when a
     * download carries both (SDM's own categories are often tagged this
     * way — e.g. a download under "Game Of Thrones" is also tagged with
     * its parent "FocusWriter Themes") — Media items support only one
     * folder, so the more specific category is the more useful choice.
     *
     * @param array<int, int> $wpFolderIdByWpTermId
     */
    private function resolveDownloadFolder(int $wpPostId, array $wpFolderIdByWpTermId): ?int
    {
        $candidateTermIds = array_values(array_intersect(
            $this->source->termIdsForPost($wpPostId, 'sdm_categories'),
            array_keys($wpFolderIdByWpTermId),
        ));

        if ($candidateTermIds === []) {
            return null;
        }

        foreach ($this->source->terms('sdm_categories') as $term) {
            if (in_array($term['term_id'], $candidateTermIds, true) && $term['parent'] !== 0) {
                return $wpFolderIdByWpTermId[$term['term_id']];
            }
        }

        return $wpFolderIdByWpTermId[$candidateTermIds[0]];
    }

    /**
     * @param array<int, int> $wpUserIdToLocalId
     * @return array{0: array<int, int>, 1: array<string, string>} [wpAttachmentId => local media id, oldUrl => newUrl]
     */
    private function importMedia(string $batchId, array $wpUserIdToLocalId): array
    {
        $wpAttachmentIdToLocalMediaId = [];
        $oldUrlToNewUrl = [];

        foreach ($this->source->posts(['attachment'], self::ATTACHMENT_STATUSES) as $attachment) {
            $meta = $this->source->postMeta($attachment['ID']);
            $relativePath = $meta['_wp_attached_file'] ?? null;

            if ($relativePath === null) {
                $this->warnings[] = "Attachment #{$attachment['ID']}: no file path recorded, skipped.";
                continue;
            }

            $absolutePath = rtrim($this->sourceUploadsPath, '/') . '/' . $relativePath;

            if (!is_file($absolutePath)) {
                $this->warnings[] = "Attachment #{$attachment['ID']}: file not found at {$absolutePath}, skipped.";
                continue;
            }

            $authorId = $wpUserIdToLocalId[$attachment['post_author']] ?? 1;

            $data = new ImportedMedia(
                absolutePath: $absolutePath,
                uploadedByUserId: $authorId,
                fileName: basename($relativePath),
                altText: ($meta['_wp_attachment_image_alt'] ?? '') !== '' ? $meta['_wp_attachment_image_alt'] : null,
                caption: $attachment['post_excerpt'] !== '' ? $attachment['post_excerpt'] : null,
                description: $attachment['post_content'] !== '' ? $attachment['post_content'] : null,
                externalId: (string) $attachment['ID'],
                uploadedAt: $this->parseWpDate($attachment['post_date']),
                // _wp_attached_file is itself a relative path (usually
                // "2020/03/file.jpg", but a plugin-managed upload can sit
                // under an arbitrary custom folder — both are worth
                // preserving rather than flattening everything into
                // today's year/month). dirname() on a file with no
                // subdirectory returns '.', which MediaImporter treats as
                // "no preference" and falls back to today's date.
                relativeDirectory: dirname($relativePath),
            );

            try {
                $media = $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data);
            } catch (RuntimeException $exception) {
                $this->warnings[] = "Attachment #{$attachment['ID']}: {$exception->getMessage()}";
                continue;
            }

            $wpAttachmentIdToLocalMediaId[$attachment['ID']] = (int) $media['id'];

            if ($attachment['guid'] !== '') {
                $oldUrlToNewUrl[$attachment['guid']] = $this->media->url($media);
            }
        }

        return [$wpAttachmentIdToLocalMediaId, $oldUrlToNewUrl];
    }

    /**
     * The returned map is used by importMenus() (Stage 8) to resolve a
     * menu item pointing at a page.
     *
     * @param array<int, string> $statuses
     * @param array<int, int> $wpUserIdToLocalId
     * @param array<int, int> $wpAttachmentIdToLocalMediaId
     * @param array<string, string> $oldUrlToNewUrl
     * @return array<string, int> wpPageId (string) => local page id
     */
    private function importPages(
        string $batchId,
        array $statuses,
        array $wpUserIdToLocalId,
        array $wpAttachmentIdToLocalMediaId,
        array $oldUrlToNewUrl,
    ): array {
        $externalMap = [];

        foreach ($this->source->posts(['page'], $statuses) as $wpPage) {
            $meta = $this->source->postMeta($wpPage['ID']);
            $featuredImageId = isset($meta['_thumbnail_id'])
                ? ($wpAttachmentIdToLocalMediaId[(int) $meta['_thumbnail_id']] ?? null)
                : null;

            $data = new ImportedPage(
                title: $this->resolveTitle($wpPage['post_title']),
                content: $this->rewriteContentUrls($wpPage['post_content'], $oldUrlToNewUrl),
                excerpt: $wpPage['post_excerpt'],
                authorId: $wpUserIdToLocalId[$wpPage['post_author']] ?? 1,
                status: $this->mapPageStatus($wpPage['post_status']),
                publishedAt: $this->parseWpDate($wpPage['post_date']),
                parentExternalId: $wpPage['post_parent'] > 0 ? (string) $wpPage['post_parent'] : null,
                featuredImageId: $featuredImageId,
                slug: $wpPage['post_name'] !== '' ? $wpPage['post_name'] : null,
                contentFormat: ContentFormat::Html,
                externalId: (string) $wpPage['ID'],
            );

            $this->flagUnsupportedShortcodes($wpPage['ID'], $wpPage['post_title'], $wpPage['post_content']);

            try {
                $page = $this->pageImporter->import($batchId, self::SOURCE, $data, $externalMap);
                $externalMap[(string) $wpPage['ID']] = $page->id;
            } catch (\Throwable $exception) {
                $this->warnings[] = "Page #{$wpPage['ID']} (\"{$wpPage['post_title']}\"): {$exception->getMessage()}";
            }
        }

        return $externalMap;
    }

    /**
     * @param array<int, string> $statuses
     * @param array<int, int> $wpUserIdToLocalId
     * @param array<int, int> $wpAttachmentIdToLocalMediaId
     * @param array<string, string> $oldUrlToNewUrl
     * @return array<int, int> wpPostId => local post id
     */
    private function importPosts(
        string $batchId,
        array $statuses,
        array $wpUserIdToLocalId,
        array $wpAttachmentIdToLocalMediaId,
        array $oldUrlToNewUrl,
    ): array {
        $map = [];

        foreach ($this->source->posts(['post'], $statuses) as $wpPost) {
            $meta = $this->source->postMeta($wpPost['ID']);
            $featuredImageId = isset($meta['_thumbnail_id'])
                ? ($wpAttachmentIdToLocalMediaId[(int) $meta['_thumbnail_id']] ?? null)
                : null;

            $data = new ImportedPost(
                title: $this->resolveTitle($wpPost['post_title']),
                content: $this->rewriteContentUrls($wpPost['post_content'], $oldUrlToNewUrl),
                excerpt: $wpPost['post_excerpt'],
                authorId: $wpUserIdToLocalId[$wpPost['post_author']] ?? 1,
                status: $this->mapPostStatus($wpPost['post_status']),
                publishedAt: $this->parseWpDate($wpPost['post_date']),
                featuredImageId: $featuredImageId,
                slug: $wpPost['post_name'] !== '' ? $wpPost['post_name'] : null,
                contentFormat: ContentFormat::Html,
                visibility: $wpPost['post_status'] === 'private' ? PostVisibility::Private : PostVisibility::Public,
                categories: $this->source->termNamesForPost($wpPost['ID'], 'category'),
                tags: $this->source->termNamesForPost($wpPost['ID'], 'post_tag'),
                externalId: (string) $wpPost['ID'],
            );

            $this->flagUnsupportedShortcodes($wpPost['ID'], $wpPost['post_title'], $wpPost['post_content']);

            try {
                $post = $this->postImporter->import($batchId, self::SOURCE, $data);
                $map[$wpPost['ID']] = $post->id;
            } catch (\Throwable $exception) {
                $this->warnings[] = "Post #{$wpPost['ID']} (\"{$wpPost['post_title']}\"): {$exception->getMessage()}";
            }
        }

        return $map;
    }

    /**
     * WordPress comments only ever attach to a single object id
     * (comment_post_ID) regardless of whether that object is a post or a
     * page, but this codebase's Importer layer only supports posts (see
     * ImportedComment — it has no $pageId field). Page comments are
     * skipped in this first pass; this method is only ever called with
     * $wpPostIdToLocalId built from imported *posts* (see run()).
     *
     * @param array<int, int> $wpPostIdToLocalId
     * @param array<int, int> $wpUserIdToLocalId
     */
    private function importComments(string $batchId, array $wpPostIdToLocalId, array $wpUserIdToLocalId): void
    {
        foreach ($wpPostIdToLocalId as $wpPostId => $localPostId) {
            $externalMap = [];

            foreach ($this->source->comments($wpPostId) as $wpComment) {
                $userId = $wpComment['user_id'] > 0 ? ($wpUserIdToLocalId[$wpComment['user_id']] ?? null) : null;

                $data = new ImportedComment(
                    postId: $localPostId,
                    content: $wpComment['comment_content'],
                    guestName: $wpComment['comment_author'],
                    guestEmail: $wpComment['comment_author_email'],
                    status: $this->mapCommentStatus($wpComment['comment_approved']),
                    guestUrl: $wpComment['comment_author_url'] !== '' ? $wpComment['comment_author_url'] : null,
                    userId: $userId,
                    parentExternalId: $wpComment['comment_parent'] > 0 ? (string) $wpComment['comment_parent'] : null,
                    externalId: (string) $wpComment['comment_ID'],
                    commentedAt: $this->parseWpDate($wpComment['comment_date']),
                );

                try {
                    $comment = $this->commentImporter->import($batchId, self::SOURCE, $data, $externalMap);
                    $externalMap[(string) $wpComment['comment_ID']] = $comment->id;
                } catch (\Throwable $exception) {
                    $this->warnings[] = "Comment #{$wpComment['comment_ID']}: {$exception->getMessage()}";
                }
            }
        }
    }

    /**
     * WordPress's own nav_menu taxonomy (LPP-004 Stage 8) — each term is
     * a menu, each member post (post_type = nav_menu_item, tied to its
     * menu's term via term_relationships exactly like a category on a
     * post) is one item, its real data living in postmeta
     * (_menu_item_type/_menu_item_object/_menu_item_object_id for what
     * it points at, _menu_item_menu_item_parent for nesting — yes,
     * WordPress core really does double up "menu_item" in that meta key
     * name). A menu is always imported as a named Lumora Press menu;
     * WordPress's own per-theme location slug (e.g. "menu-1") is never
     * auto-assigned to a Lumora Press location — see this feature's
     * implementation plan for why that's a real, honest scope boundary
     * rather than a shortcut: WordPress never stores a location's
     * human-readable label in the database at all, only the theme's own
     * opaque slug, so there is no reliable way to guess which Lumora
     * Press location (primary/footer/social/secondary) it actually
     * meant. The admin finishes that one manual step via the
     * already-built Appearance > Menus > Manage Locations screen.
     *
     * The pre-import "nav_menus" option value is snapshotted before any
     * menu is created, so removeAll() can restore it verbatim — see
     * ContentImportRegistry::record()'s own docblock on why menus can't
     * use the normal per-id delete path every other content type does.
     *
     * @param array<string, int> $wpPageIdToLocalId
     * @param array<int, int> $wpPostIdToLocalId
     * @param array<int, int> $wpCategoryTermIdToLocalId
     * @param array<int, int> $wpTagTermIdToLocalId
     */
    private function importMenus(
        string $batchId,
        array $wpPageIdToLocalId,
        array $wpPostIdToLocalId,
        array $wpCategoryTermIdToLocalId,
        array $wpTagTermIdToLocalId,
    ): void {
        $this->registry->record($batchId, self::SOURCE, 'nav_menus_snap', 0, null, (string) $this->config->option('nav_menus', '{}'));

        $navMenuItemTermIds = $this->source->navMenuItemTermTaxonomyIds();
        $itemsByMenuTermId = [];

        foreach ($this->source->posts(['nav_menu_item'], ['publish']) as $wpItem) {
            $menuTermId = $navMenuItemTermIds[$wpItem['ID']] ?? null;

            if ($menuTermId !== null) {
                $itemsByMenuTermId[$menuTermId][] = $wpItem;
            }
        }

        foreach ($this->source->terms('nav_menu') as $term) {
            $items = [];

            foreach ($itemsByMenuTermId[$term['term_id']] ?? [] as $wpItem) {
                $meta = $this->source->postMeta($wpItem['ID']);
                $resolved = $this->resolveMenuItemTarget($meta, $wpPageIdToLocalId, $wpPostIdToLocalId, $wpCategoryTermIdToLocalId, $wpTagTermIdToLocalId);

                if ($resolved === null) {
                    $this->warnings[] = "Menu item #{$wpItem['ID']} in menu \"{$term['name']}\": target content wasn't imported, skipped.";
                    continue;
                }

                $label = $wpItem['post_title'] !== '' ? $wpItem['post_title'] : $resolved['label'];
                $classes = @unserialize($meta['_menu_item_classes'] ?? '', ['allowed_classes' => false]);
                $parentExternalId = ($meta['_menu_item_menu_item_parent'] ?? '0') !== '0' ? $meta['_menu_item_menu_item_parent'] : null;

                $items[] = new ImportedMenuItem(
                    label: $label !== '' ? $label : $resolved['url'],
                    url: $resolved['url'],
                    order: $wpItem['menu_order'],
                    target: ($meta['_menu_item_target'] ?? '') === '_blank' ? '_blank' : '_self',
                    cssClass: is_array($classes) ? trim(implode(' ', $classes)) : '',
                    rel: $meta['_menu_item_xfn'] ?? '',
                    parentExternalId: $parentExternalId,
                    externalId: (string) $wpItem['ID'],
                );
            }

            $menuData = new ImportedMenu(name: $term['name'], items: $items, externalId: (string) $term['term_id']);

            try {
                $this->menuImporter->import($batchId, self::SOURCE, $menuData);
            } catch (Throwable $exception) {
                $this->warnings[] = "Menu \"{$term['name']}\": {$exception->getMessage()}";
            }
        }

        $this->config->setOption('nav_menus', json_encode($this->menus->menus()));
    }

    /**
     * Resolves a WordPress menu item's postmeta into a plain
     * label/url pair, or null when its target wasn't imported (its
     * content type's own toggle was off, or the id genuinely isn't
     * found) — a 'custom' link has no such dependency and always
     * resolves. Every id map here was already built by an earlier import
     * step in run(); this never queries the source database for
     * anything beyond what's already in $meta.
     *
     * @param array<string, string> $meta
     * @param array<string, int> $wpPageIdToLocalId
     * @param array<int, int> $wpPostIdToLocalId
     * @param array<int, int> $wpCategoryTermIdToLocalId
     * @param array<int, int> $wpTagTermIdToLocalId
     * @return array{label: string, url: string}|null
     */
    private function resolveMenuItemTarget(
        array $meta,
        array $wpPageIdToLocalId,
        array $wpPostIdToLocalId,
        array $wpCategoryTermIdToLocalId,
        array $wpTagTermIdToLocalId,
    ): ?array {
        $type = $meta['_menu_item_type'] ?? 'custom';
        $object = $meta['_menu_item_object'] ?? '';
        $objectId = (int) ($meta['_menu_item_object_id'] ?? 0);

        if ($type === 'custom') {
            $url = $meta['_menu_item_url'] ?? '';

            return $url !== '' ? ['label' => '', 'url' => $url] : null;
        }

        if ($type === 'post_type' && $object === 'page') {
            $page = ($wpPageIdToLocalId[(string) $objectId] ?? null) !== null ? $this->pages->findById($wpPageIdToLocalId[(string) $objectId]) : null;

            return $page !== null ? ['label' => $page->title, 'url' => page_permalink($page)] : null;
        }

        if ($type === 'post_type' && $object === 'post') {
            $post = ($wpPostIdToLocalId[$objectId] ?? null) !== null ? $this->posts->findById($wpPostIdToLocalId[$objectId]) : null;

            return $post !== null ? ['label' => $post->title, 'url' => post_permalink($post)] : null;
        }

        if ($type === 'taxonomy' && $object === 'category') {
            $category = ($wpCategoryTermIdToLocalId[$objectId] ?? null) !== null ? $this->categories->findById($wpCategoryTermIdToLocalId[$objectId]) : null;

            return $category !== null ? ['label' => $category->name, 'url' => category_permalink($category)] : null;
        }

        if ($type === 'taxonomy' && $object === 'post_tag') {
            $tag = ($wpTagTermIdToLocalId[$objectId] ?? null) !== null ? $this->tags->findById($wpTagTermIdToLocalId[$objectId]) : null;

            return $tag !== null ? ['label' => $tag->name, 'url' => tag_permalink($tag)] : null;
        }

        // An unrecognized type/object combination (a custom post type,
        // WooCommerce product category, etc.) — no Lumora Press
        // equivalent to link to.
        return null;
    }

    /**
     * Classic widget instances (LPP-004 Stage 8) — WordPress stores these
     * as one `widget_{type}` option per type (a PHP-serialized array
     * keyed by instance number) plus a `sidebars_widgets` option mapping
     * each sidebar id to an ordered list of "type-index" slugs (e.g.
     * "text-6"). Only widget types with a direct Lumora Press equivalent
     * are imported (see WIDGET_TYPE_MAP) — every other `widget_*` option
     * name (the overwhelming majority on a real multi-plugin site) is
     * skipped, aggregated into one warning per type rather than one line
     * per instance. Area assignment is a small id-based heuristic (see
     * matchSidebar()); anything that doesn't confidently match — including
     * WordPress's own `wp_inactive_widgets` bucket — lands in Lumora
     * Press's existing Inactive Widgets bucket instead of being dropped,
     * so nothing is ever silently lost, just left for the admin to place.
     *
     * The pre-import "widgets_config" option value is snapshotted before
     * any widget is added, so removeAll() can restore it verbatim — same
     * reasoning as importMenus()'s own snapshot.
     */
    private function importWidgets(string $batchId): void
    {
        $this->registry->record($batchId, self::SOURCE, 'widgets_snap', 0, null, (string) $this->config->option('widgets_config', '{}'));

        $sidebarsWidgetsRaw = $this->source->option('sidebars_widgets');
        $sidebarsWidgets = $sidebarsWidgetsRaw !== null ? @unserialize($sidebarsWidgetsRaw, ['allowed_classes' => false]) : null;

        if (!is_array($sidebarsWidgets)) {
            return;
        }

        $widgetTypeMap = [
            'text' => 'text', 'custom_html' => 'custom_html', 'search' => 'search',
            'pages' => 'pages', 'categories' => 'categories', 'recent-posts' => 'recent_posts',
            'recent-comments' => 'recent_comments', 'archives' => 'archives',
            'tag_cloud' => 'tag_cloud', 'meta' => 'meta',
        ];

        $widgetOptions = $this->source->optionsLike('widget_');
        $instances = [];
        $skippedByType = [];

        foreach ($sidebarsWidgets as $sourceSidebarId => $widgetSlugs) {
            if (!is_string($sourceSidebarId) || $sourceSidebarId === 'array_version' || !is_array($widgetSlugs)) {
                continue;
            }

            $isSourceInactive = $sourceSidebarId === 'wp_inactive_widgets';
            $targetSidebarId = $isSourceInactive ? WidgetManager::INACTIVE_SIDEBAR_ID : $this->matchSidebar($sourceSidebarId);
            $unmatchedButActive = !$isSourceInactive && $targetSidebarId === null;
            $targetSidebarId ??= WidgetManager::INACTIVE_SIDEBAR_ID;
            $order = 0;

            foreach ($widgetSlugs as $slug) {
                if (!is_string($slug) || !preg_match('/^(.+)-(\d+)$/', $slug, $matches)) {
                    continue;
                }

                $wpType = $matches[1];
                $instanceKey = (int) $matches[2];
                $lumoraType = $widgetTypeMap[$wpType] ?? null;

                if ($lumoraType === null) {
                    $skippedByType[$wpType] = ($skippedByType[$wpType] ?? 0) + 1;
                    continue;
                }

                $decoded = isset($widgetOptions['widget_' . $wpType])
                    ? @unserialize($widgetOptions['widget_' . $wpType], ['allowed_classes' => false])
                    : null;
                $settingsRaw = is_array($decoded) ? ($decoded[$instanceKey] ?? null) : null;

                if (!is_array($settingsRaw)) {
                    continue;
                }

                if ($unmatchedButActive) {
                    $this->warnings[] = "Widget \"{$slug}\" was in sidebar \"{$sourceSidebarId}\", which has no matching area — moved to Inactive Widgets.";
                }

                $instances[] = new ImportedWidgetInstance(
                    type: $lumoraType,
                    settings: $this->translateWidgetSettings($lumoraType, $settingsRaw),
                    targetSidebarId: $targetSidebarId,
                    order: $order++,
                    externalId: $slug,
                );
            }
        }

        if ($skippedByType !== []) {
            $parts = [];

            foreach ($skippedByType as $type => $count) {
                $parts[] = "{$type} ({$count})";
            }

            $this->warnings[] = count($skippedByType) . ' unsupported widget type(s) skipped (no Lumora Press equivalent): ' . implode(', ', $parts) . '.';
        }

        if ($instances !== []) {
            $this->widgetImporter->import($batchId, self::SOURCE, $instances);
        }

        $config = [];

        foreach ([...array_keys($this->widgets->sidebars()), WidgetManager::INACTIVE_SIDEBAR_ID] as $sidebarId) {
            $config[$sidebarId] = $this->widgets->widgetsFor($sidebarId);
        }

        $this->config->setOption('widgets_config', json_encode($config));
    }

    /**
     * A small, deliberately narrow heuristic tuned to WordPress's own
     * common sidebar-id conventions found in real source data (e.g.
     * "sidebar-1", "footer-1".."footer-4", "header-sidebar-1") — not a
     * generic fuzzy-matching algorithm. "footer" is checked first since
     * it's unambiguous; "sidebar" only matches Lumora Press's "primary"
     * area when the source id doesn't also contain "header" (a real
     * "header-sidebar-1" area exists in production data and is
     * genuinely ambiguous — it lands in Inactive Widgets instead of
     * being guessed wrong). Anything else returns null, meaning
     * "no confident match" — the caller routes that to Inactive Widgets
     * rather than dropping it.
     */
    private function matchSidebar(string $sourceSidebarId): ?string
    {
        $normalized = strtolower($sourceSidebarId);
        $registered = array_keys($this->widgets->sidebars());

        if (str_contains($normalized, 'footer') && in_array('footer', $registered, true)) {
            return 'footer';
        }

        if (str_contains($normalized, 'sidebar') && !str_contains($normalized, 'header') && in_array('primary', $registered, true)) {
            return 'primary';
        }

        return null;
    }

    /**
     * Translates a WordPress classic widget instance's own setting keys
     * onto Lumora Press's own (see CoreWidgets for the full field list
     * per type) — title is common to every type; anything else falls
     * back to title-only, which is always a safe default even for a
     * type with more settings (e.g. archives/search/meta/tag_cloud have
     * no other required setting).
     *
     * @param array<string, mixed> $wp
     * @return array<string, mixed>
     */
    private function translateWidgetSettings(string $lumoraType, array $wp): array
    {
        $title = (string) ($wp['title'] ?? '');

        return match ($lumoraType) {
            'text' => ['title' => $title, 'text' => (string) ($wp['text'] ?? '')],
            'custom_html' => ['title' => $title, 'html' => (string) ($wp['content'] ?? '')],
            'categories' => ['title' => $title, 'show_count' => !empty($wp['count'])],
            'recent_posts' => ['title' => $title, 'limit' => isset($wp['number']) ? (int) $wp['number'] : 5],
            'recent_comments' => ['title' => $title, 'limit' => isset($wp['number']) ? (int) $wp['number'] : 5],
            default => ['title' => $title],
        };
    }

    /**
     * WordPress shortcodes this import has no equivalent for (currently
     * just Simple Download Monitor's own display shortcode) are left as
     * literal, inert text in imported content — this import brings the
     * underlying download data in via importDownloads() above, but has
     * no shortcode processor of its own to make `[sdm_show_dl_...]`
     * actually render anything. Flagged as a warning per occurrence so
     * every affected page/post is visible in the import summary rather
     * than silently shipping broken-looking content.
     */
    private function flagUnsupportedShortcodes(int $wpId, string $title, string $content): void
    {
        if (str_contains($content, '[sdm_show_dl')) {
            $this->warnings[] = "#{$wpId} (\"{$title}\") still contains a [sdm_show_dl...] shortcode — Simple Download Monitor's download listing has no Lumora Press equivalent yet, so it will show as plain text.";
        }
    }

    /**
     * Plain string search/replace over old attachment URLs found during
     * the media step — a documented first-pass limitation, not a full
     * HTML-aware rewrite (see this feature's implementation plan). Only
     * matches a full-size attachment's own guid URL; WordPress inline
     * `<img>` tags commonly reference a resized variant filename (e.g.
     * `image-300x200.jpg`) that this import never generates or maps, so
     * those references are left pointing at the old site. Regenerating
     * size variants and rewriting every reference is deferred (see the
     * ticket's "Regenerate thumbnails" / URL migration scope).
     *
     * @param array<string, string> $oldUrlToNewUrl
     */
    private function rewriteContentUrls(string $content, array $oldUrlToNewUrl): string
    {
        if ($oldUrlToNewUrl === []) {
            return $content;
        }

        return str_replace(array_keys($oldUrlToNewUrl), array_values($oldUrlToNewUrl), $content);
    }

    /**
     * PostService/PageService require a non-empty title; an empty
     * post_title is rare but real (found in production data — a post
     * with no title at all, still worth migrating rather than dropping).
     */
    private function resolveTitle(string $title): string
    {
        return trim($title) !== '' ? $title : '(Untitled)';
    }

    private function mapPostStatus(string $wpStatus): PostStatus
    {
        return match ($wpStatus) {
            'draft' => PostStatus::Draft,
            'pending' => PostStatus::PendingReview,
            'future' => PostStatus::Scheduled,
            default => PostStatus::Published,
        };
    }

    private function mapPageStatus(string $wpStatus): PageStatus
    {
        return match ($wpStatus) {
            'draft' => PageStatus::Draft,
            'pending' => PageStatus::PendingReview,
            'future' => PageStatus::Scheduled,
            default => PageStatus::Published,
        };
    }

    private function mapCommentStatus(string $wpApproved): CommentStatus
    {
        return match ($wpApproved) {
            '1' => CommentStatus::Approved,
            'spam' => CommentStatus::Spam,
            'trash' => CommentStatus::Trash,
            default => CommentStatus::Pending,
        };
    }

    /**
     * WordPress stores an unset date as '0000-00-00 00:00:00' rather
     * than NULL — never a valid DateTimeImmutable, so it must be
     * special-cased to null (meaning "no source date available, let the
     * target service stamp now") instead of throwing.
     */
    private function parseWpDate(string $value): ?DateTimeImmutable
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
