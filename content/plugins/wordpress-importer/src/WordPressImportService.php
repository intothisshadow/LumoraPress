<?php

/**
 * Orchestrates a full WordPress-site import (users, categories/tags, media, pages, posts, comments) via the shared Core import layer.
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
use DateTimeZone;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Theme\Permalinks;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use LumoraPress\Plugins\Downloads\DownloadCategoryService;
use LumoraPress\Plugins\Downloads\DownloadService;
use LumoraPress\Plugins\Downloads\DownloadType;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\Import\CommentImporter;
use LumoraPress\Services\Import\ExistingContentMode;
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
use LumoraPress\Services\ThumbnailService;
use LumoraPress\Services\UserService;
use RuntimeException;
use Throwable;

/**
 * Orchestrates a WordPress import through the shared Importer layer, in dependency order:
 * Users -> Categories/Tags -> Media -> NextGEN Gallery -> Downloads -> Pages -> Posts ->
 * Comments -> Menus -> Widgets, then thumbnail regeneration and an integrity check. Media
 * imports before Pages/Posts so in-content `<img>` URLs can be rewritten to local media.
 *
 * Only one 'wordpress_import' batch may be in progress at a time (removeAll() clears it).
 * Progress and cross-stage id maps persist after every stage so an interrupted import can
 * resume via startOrResume()/runNextStage(), but source DB credentials are never stored.
 */
final class WordPressImportService
{
    public const SOURCE = 'wordpress_import';

    /** WordPress attachments always use post_status 'inherit', not the caller-selectable $statuses option. */
    private const ATTACHMENT_STATUSES = ['inherit'];

    /**
     * Post types this importer handles explicitly. 'revision' is excluded as
     * auto-generated; anything else is reported as unsupported.
     *
     * @var array<int, string>
     */
    private const HANDLED_POST_TYPES = ['post', 'page', 'attachment', 'nav_menu_item', 'revision', 'sdm_downloads', 'ngg_gallery', 'ngg_pictures'];

    /**
     * Plugin directory slugs this importer migrates real data from — see this plugin's README.
     *
     * @var array<int, string>
     */
    private const HANDLED_PLUGIN_SLUGS = ['simple-download-monitor', 'folders', 'nextgen-gallery'];

    /**
     * Permalink tokens PermalinkService actually replaces. A structure containing any other
     * token is refused rather than applied, to avoid producing broken URLs.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_PERMALINK_TOKENS = ['%postname%', '%year%', '%monthnum%', '%day%', '%category%', '%author%'];

    /** @var array<int, string> */
    private array $warnings = [];

    /**
     * $source is nullable so removeAll()/lastImportSummary() — pure
     * registry lookups that never touch the source site — can be used
     * without opening a connection or parsing a WXR file first. Only
     * run() actually requires it.
     *
     * Typed against WordPressSourceInterface, not the concrete
     * WordPressSource, so a WXR export file (WordPressXmlSource) can be
     * driven through the exact same stage pipeline below.
     */
    public function __construct(
        private readonly ?WordPressSourceInterface $source,
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
        private readonly ThumbnailService $thumbnails,
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
         * Null when the Downloads plugin isn't active — downloads still
         * import as a plain Media item/Redirect, just without the
         * `downloads` table's own row. See importDownloads().
         */
        private readonly ?DownloadService $downloads = null,
        /**
         * Null under the same condition as $downloads — its category
         * taxonomy only exists while that plugin is active.
         */
        private readonly ?DownloadCategoryService $downloadCategories = null,
        /**
         * A local filesystem copy of the source site's `wp-content/gallery`
         * folder — a sibling of `wp-content/uploads`, so it needs its own
         * path. Null when the admin didn't supply one; importNextGenGalleries()
         * then skips entirely.
         */
        private readonly ?string $sourceGalleryPath = null,
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
     * Every redirect this batch created, as a plain old-URL/new-URL table
     * for the admin to review or export. A pure registry + RedirectService
     * read, so it needs no source connection, just a real batch id.
     *
     * @return array<int, array{sourcePath: string, targetUrl: string}>
     */
    public function redirectMappingReport(string $batchId): array
    {
        $report = [];

        foreach ($this->registry->idsForBatch($batchId, 'redirect') as $entry) {
            $redirect = $this->redirects->find($entry['contentId']);

            if ($redirect === null) {
                continue;
            }

            $report[] = [
                'sourcePath' => (string) $redirect['source_path'],
                'targetUrl' => (string) $redirect['target_url'],
            ];
        }

        return $report;
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Distinguishes a genuine follow-up action from a routine per-item
     * warning — used by the post-import summary screen to surface "you
     * should look at this" items in their own section.
     *
     * Matches a fixed set of substrings against the exact wording the
     * relevant warning-producing methods already emit, rather than a
     * structured warning type threaded through every call site. A
     * Download's missing-file warning and a post/page's missing-featured-
     * image warning are singled out because the item still gets created
     * but stays silently incomplete until an admin notices — everything
     * else just documents what was skipped with nothing further to act on.
     */
    public static function isActionNeededWarning(string $warning): bool
    {
        return str_contains($warning, 'still contains a ')
            || str_contains($warning, 'were not imported — no Lumora Press equivalent exists for it.')
            || str_contains($warning, 'other active plugin(s) with no Lumora Press equivalent')
            || (str_contains($warning, 'Download #') && str_contains($warning, 'file not found at'))
            || str_contains($warning, 'its featured image (attachment #');
    }

    /**
     * @param array{users?: bool, categories?: bool, media?: bool, nextgen_galleries?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, site_settings?: bool, statuses?: array<int, string>, stage_delay_ms?: int, existing_content?: string} $options
     * @return array<string, int>
     */
    public function run(array $options): array
    {
        $started = $this->startOrResume($options);

        do {
            $result = $this->runNextStage($started['batchId']);
        } while ($result['done'] === false);

        return $result['counts'];
    }

    /**
     * The ordered stage list a given set of options will actually run — pure and
     * side-effect-free, so both runNextStage() and the admin view's checklist share it.
     * 'internal_links', 'thumbnails', and 'verify' always run last as finalization steps;
     * each degrades to a no-op when there's nothing to do.
     *
     * @param array{users?: bool, categories?: bool, media?: bool, nextgen_galleries?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, site_settings?: bool} $options
     * @return array<int, string>
     */
    public function plannedStages(array $options): array
    {
        $requested = [
            'site_settings' => $options['site_settings'] ?? false,
            'users' => $options['users'] ?? true,
            'categories' => $options['categories'] ?? true,
            'media' => $options['media'] ?? true,
            'nextgen_galleries' => $options['nextgen_galleries'] ?? true,
            'downloads' => $options['downloads'] ?? true,
            'pages' => $options['pages'] ?? true,
            'posts' => $options['posts'] ?? true,
            'comments' => $options['comments'] ?? true,
            'menus' => $options['menus'] ?? true,
            'widgets' => $options['widgets'] ?? true,
        ];

        $stages = array_values(array_filter(array_keys($requested), static fn (string $stage): bool => $requested[$stage]));
        $stages[] = 'internal_links';
        $stages[] = 'thumbnails';
        $stages[] = 'verify';

        return $stages;
    }

    /**
     * A short human label per stage key, for the admin view's progress
     * checklist — a plain lookup, not translated content, since it only
     * ever describes this plugin's own fixed, internal stage names.
     */
    public static function stageLabel(string $stage): string
    {
        return match ($stage) {
            'site_settings' => 'Importing site settings',
            'users' => 'Importing users',
            'categories' => 'Importing categories & tags',
            'media' => 'Importing media',
            'nextgen_galleries' => 'Importing NextGEN Gallery images',
            'downloads' => 'Importing downloads',
            'pages' => 'Importing pages',
            'posts' => 'Importing posts',
            'comments' => 'Importing comments',
            'menus' => 'Importing menus',
            'widgets' => 'Importing widgets',
            'internal_links' => 'Updating internal links',
            'thumbnails' => 'Regenerating thumbnails',
            'verify' => 'Verifying imported content',
            default => ucfirst(str_replace('_', ' ', $stage)),
        };
    }

    /**
     * Starts a fresh import, or resumes an in-progress one found via inProgressBatch() —
     * resuming always continues with that batch's original options, never the
     * freshly-submitted ones, since changing selected content types mid-batch would leave
     * completed stages inconsistent. A completed batch blocks a new import unless
     * `existing_content` is `'skip'`/`'overwrite'`, in which case each *Importer resolves
     * rows against every previous batch (ContentImportRegistry::existingLocalId()) and
     * reuses/updates matches instead of duplicating — scoped to Users, Posts, Pages,
     * Comments, and Media's main attachment stage; Downloads/NextGEN images are always
     * created fresh.
     *
     * @param array{users?: bool, categories?: bool, media?: bool, nextgen_galleries?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, site_settings?: bool, statuses?: array<int, string>, stage_delay_ms?: int, existing_content?: string} $options
     * @return array{batchId: string, resumed: bool}
     */
    public function startOrResume(array $options): array
    {
        if ($this->source === null) {
            throw new RuntimeException('No source database connection was provided.');
        }

        $inProgress = $this->inProgressBatch();

        if ($inProgress !== null) {
            return ['batchId' => $inProgress['batchId'], 'resumed' => true];
        }

        $allowsReimport = in_array($options['existing_content'] ?? null, ['skip', 'overwrite'], true);

        if (!$allowsReimport && $this->existingBatchIds() !== []) {
            throw new RuntimeException('A WordPress import already exists. Remove it before importing again.');
        }

        $options['statuses'] ??= ['publish', 'draft', 'pending', 'future', 'private'];
        $batchId = $this->registry->newBatch();

        $this->persistState($batchId, [
            'options' => $options,
            'completedStages' => [],
            'warnings' => [],
            'maps' => [],
        ]);

        return ['batchId' => $batchId, 'resumed' => false];
    }

    /**
     * Runs exactly the next not-yet-completed stage for $batchId and
     * persists the result (which stage just finished, the accumulated
     * cross-stage id maps, and warnings so far) before returning — so
     * whether the caller is run()'s own loop or the admin view driving
     * stages one at a time for a live progress bar, every stage's
     * progress survives independently of whether a *later* stage in the
     * same run ever gets the chance to execute at all.
     *
     * @return array{stage: ?string, done: bool, counts: array<string, int>, warnings: array<int, string>}
     */
    public function runNextStage(string $batchId): array
    {
        if ($this->source === null) {
            throw new RuntimeException('No source database connection was provided.');
        }

        $state = $this->loadState($batchId);

        if ($state === null) {
            throw new RuntimeException('No in-progress import was found for this batch.');
        }

        $options = $state['options'] ?? [];
        $planned = $this->plannedStages($options);
        $completed = $state['completedStages'] ?? [];
        $remaining = array_values(array_diff($planned, $completed));

        $this->warnings = $state['warnings'] ?? [];

        if ($remaining === []) {
            return ['stage' => null, 'done' => true, 'counts' => $this->registry->countsForBatch($batchId), 'warnings' => $this->warnings];
        }

        $stage = $remaining[0];
        $maps = $state['maps'] ?? [];

        // Only delays *between* stages, never before the very first one —
        // $completed being non-empty is exactly "a previous stage in this
        // batch already ran". Meant for a live production source only
        // (see this option's own admin-facing hint), so it's opt-in and
        // 0 (no delay) unless the admin explicitly set it.
        $delayMs = (int) ($options['stage_delay_ms'] ?? 0);

        if ($delayMs > 0 && $completed !== []) {
            usleep($delayMs * 1000);
        }

        $this->executeStage($batchId, $stage, $options, $maps);

        $completed[] = $stage;

        $this->persistState($batchId, [
            'options' => $options,
            'completedStages' => $completed,
            'warnings' => $this->warnings,
            'maps' => $maps,
        ]);

        return [
            'stage' => $stage,
            'done' => array_diff($planned, $completed) === [],
            'counts' => $this->registry->countsForBatch($batchId),
            'warnings' => $this->warnings,
        ];
    }

    /**
     * The one batch (per this source) that's been started but hasn't
     * finished every stage its own options called for — null if there
     * either isn't one, or the only existing batch predates this
     * feature (no 'progress_snap' state at all, e.g. an import created
     * by an older version of this plugin), which is treated as already
     * complete rather than resumable, matching that version's own
     * always-fully-synchronous behavior.
     *
     * @return array{batchId: string, completedStages: array<int, string>, plannedStages: array<int, string>}|null
     */
    public function inProgressBatch(): ?array
    {
        foreach ($this->existingBatchIds() as $batchId) {
            $state = $this->loadState($batchId);

            if ($state === null) {
                continue;
            }

            $planned = $this->plannedStages($state['options'] ?? []);
            $completed = $state['completedStages'] ?? [];

            if (array_diff($planned, $completed) !== []) {
                return ['batchId' => $batchId, 'completedStages' => $completed, 'plannedStages' => $planned];
            }
        }

        return null;
    }

    /**
     * Approximate, upper-bound counts of what a real import *would*
     * bring in per content type, read straight from the source with no
     * writes to this site at all — "approximate" because it can't
     * replicate every real-import skip condition (a missing uploads
     * file, a malformed row, an unsupported widget type) without
     * actually attempting each one, so the real import can land on a
     * lower count than this preview showed. Good enough for the "am I
     * pointed at the right database, and roughly how much content is
     * this going to bring in" check a dry run is meant to answer.
     *
     * @param array{users?: bool, categories?: bool, media?: bool, nextgen_galleries?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, statuses?: array<int, string>} $options
     * @return array<string, int>
     */
    public function dryRunCounts(array $options): array
    {
        if ($this->source === null) {
            throw new RuntimeException('No source database connection was provided.');
        }

        $statuses = $options['statuses'] ?? ['publish', 'draft', 'pending', 'future', 'private'];
        $counts = [];

        if ($options['users'] ?? true) {
            $counts['user'] = count($this->source->users());
        }

        if ($options['categories'] ?? true) {
            $counts['category'] = count($this->source->terms('category'));
            $counts['tag'] = count($this->source->terms('post_tag'));
        }

        if ($options['media'] ?? true) {
            $counts['media'] = count($this->source->posts(['attachment'], self::ATTACHMENT_STATUSES));
        }

        if ($options['nextgen_galleries'] ?? true) {
            $galleries = $this->source->nextGenGalleries();
            $counts['nextgen_gallery'] = count($galleries);
            $pictureCount = 0;

            foreach ($galleries as $gallery) {
                foreach ($this->source->nextGenPictures($gallery['gid']) as $picture) {
                    if ($picture['exclude'] !== 1) {
                        $pictureCount++;
                    }
                }
            }

            $counts['nextgen_picture'] = $pictureCount;
        }

        $sourcePosts = ($options['posts'] ?? true) ? $this->source->posts(['post'], $statuses) : [];

        if ($options['downloads'] ?? true) {
            $counts['download'] = count($this->source->posts(['sdm_downloads'], ['publish']));
        }

        if ($options['pages'] ?? true) {
            $counts['page'] = count($this->source->posts(['page'], $statuses));
        }

        if ($options['posts'] ?? true) {
            $counts['post'] = count($sourcePosts);
        }

        if (($options['comments'] ?? true) && $sourcePosts !== []) {
            $commentCount = 0;

            foreach ($sourcePosts as $wpPost) {
                $commentCount += count($this->source->comments($wpPost['ID']));
            }

            $counts['comment'] = $commentCount;
        }

        if ($options['menus'] ?? true) {
            $counts['nav_menu'] = count($this->source->terms('nav_menu'));
        }

        if ($options['widgets'] ?? true) {
            $counts['widget_instance'] = $this->countSourceWidgetSlugs();
        }

        return $counts;
    }

    /**
     * Every raw widget slug WordPress has assigned to any sidebar
     * (active or inactive) — an upper bound for dryRunCounts()'s own
     * widget count, since the real import only counts a type with a
     * Lumora Press equivalent (see importWidgets()) and this doesn't
     * replicate that filtering.
     */
    private function countSourceWidgetSlugs(): int
    {
        $sidebarsWidgetsRaw = $this->source->option('sidebars_widgets');
        $sidebarsWidgets = $sidebarsWidgetsRaw !== null ? @unserialize($sidebarsWidgetsRaw, ['allowed_classes' => false]) : null;

        if (!is_array($sidebarsWidgets)) {
            return 0;
        }

        $count = 0;

        foreach ($sidebarsWidgets as $key => $slugs) {
            if (is_string($key) && $key !== 'array_version' && is_array($slugs)) {
                $count += count($slugs);
            }
        }

        return $count;
    }

    /**
     * Import Options — "Skip existing content"/"Overwrite existing
     * content": `$options['existing_content']` is `'skip'`, `'overwrite'`,
     * or anything else (including unset) for the default `'block'`
     * behavior — see startOrResume()'s own guard, which is the only
     * place `'block'` itself is actually checked; every *Importer call
     * site below only ever needs to know Skip/Overwrite/"don't bother
     * checking at all" (null), never the block case, since reaching
     * executeStage() at all already means block would have refused to
     * start.
     *
     * @param array<string, mixed> $options
     */
    private function resolveExistingContentMode(array $options): ?ExistingContentMode
    {
        return match ($options['existing_content'] ?? null) {
            'skip' => ExistingContentMode::Skip,
            'overwrite' => ExistingContentMode::Overwrite,
            default => null,
        };
    }

    /**
     * @param array<string, string> $options
     * @param array<string, mixed> $maps
     */
    private function executeStage(string $batchId, string $stage, array $options, array &$maps): void
    {
        $statuses = $options['statuses'] ?? ['publish', 'draft', 'pending', 'future', 'private'];
        $existingContentMode = $this->resolveExistingContentMode($options);

        switch ($stage) {
            case 'site_settings':
                $this->importSiteSettings($batchId);
                break;
            case 'users':
                $maps['wpUserIdToLocalId'] = $this->importUsers($batchId);
                break;
            case 'categories':
                $maps['wpCategoryTermIdToLocalId'] = $this->importCategories($batchId, $existingContentMode);
                $maps['wpTagTermIdToLocalId'] = $this->importTags($batchId, $existingContentMode);
                break;
            case 'media':
                [$maps['wpAttachmentIdToLocalMediaId'], $maps['oldRelativePathToNewUrl']] = $this->importMedia($batchId, $maps['wpUserIdToLocalId'] ?? [], $existingContentMode);
                break;
            case 'nextgen_galleries':
                $this->importNextGenGalleries($batchId, $maps['wpUserIdToLocalId'] ?? [], $existingContentMode);
                break;
            case 'downloads':
                $this->importDownloads($batchId, $maps['wpUserIdToLocalId'] ?? [], $maps['wpAttachmentIdToLocalMediaId'] ?? [], $maps['oldRelativePathToNewUrl'] ?? [], $existingContentMode);
                break;
            case 'pages':
                $maps['wpPageIdToLocalId'] = $this->importPages($batchId, $statuses, $maps['wpUserIdToLocalId'] ?? [], $maps['wpAttachmentIdToLocalMediaId'] ?? [], $maps['oldRelativePathToNewUrl'] ?? [], $existingContentMode);

                if ($options['site_settings'] ?? false) {
                    $this->applyPageDependentSiteSettings($maps['wpPageIdToLocalId']);
                }

                break;
            case 'posts':
                $maps['wpPostIdToLocalId'] = $this->importPosts($batchId, $statuses, $maps['wpUserIdToLocalId'] ?? [], $maps['wpAttachmentIdToLocalMediaId'] ?? [], $maps['oldRelativePathToNewUrl'] ?? [], $existingContentMode);
                break;
            case 'comments':
                if (($maps['wpPostIdToLocalId'] ?? []) !== []) {
                    $this->importComments($batchId, $maps['wpPostIdToLocalId'], $maps['wpUserIdToLocalId'] ?? [], $existingContentMode);
                }

                break;
            case 'menus':
                $this->importMenus($batchId, $maps['wpPageIdToLocalId'] ?? [], $maps['wpPostIdToLocalId'] ?? [], $maps['wpCategoryTermIdToLocalId'] ?? [], $maps['wpTagTermIdToLocalId'] ?? []);
                break;
            case 'widgets':
                $this->importWidgets($batchId);
                break;
            case 'internal_links':
                $this->rewriteInternalLinks($statuses, $maps['wpPageIdToLocalId'] ?? [], $maps['wpPostIdToLocalId'] ?? []);
                $this->importOldSlugRedirects($batchId, $maps['wpPageIdToLocalId'] ?? [], $maps['wpPostIdToLocalId'] ?? []);
                break;
            case 'thumbnails':
                $this->regenerateThumbnails($batchId);
                break;
            case 'verify':
                $this->verifyImport($batchId);
                $this->reportUnsupportedContent();
                break;
        }
    }

    /**
     * Rewrites in-content `<a href>` links between imported posts/pages to point at the new
     * local URL. Runs after both 'pages' and 'posts' as a separate pass — unlike image URLs,
     * a link's target post/page might not be imported yet if it comes later in iteration
     * order, so every local id must already exist. Category/tag/author archive links are a
     * deliberate scope boundary and are left untouched.
     *
     * @param array<int, string> $statuses
     * @param array<string, int> $wpPageIdToLocalId
     * @param array<int, int> $wpPostIdToLocalId
     */
    private function rewriteInternalLinks(array $statuses, array $wpPageIdToLocalId, array $wpPostIdToLocalId): void
    {
        if ($wpPageIdToLocalId === [] && $wpPostIdToLocalId === []) {
            return;
        }

        $sourceHost = (string) (parse_url($this->source->siteOptions()['home'] ?? '', PHP_URL_HOST) ?: parse_url($this->source->siteOptions()['siteurl'] ?? '', PHP_URL_HOST) ?: '');

        [$postIdToNewUrl, $postSlugToNewUrl, $postGuidToNewUrl] = $this->buildPostLinkMaps($statuses, $wpPostIdToLocalId);
        [$pageIdToNewUrl, $pageSlugToNewUrl, $pageGuidToNewUrl] = $this->buildPageLinkMaps($statuses, $wpPageIdToLocalId);

        $rewriter = new InternalLinkRewriter(
            $sourceHost,
            $postIdToNewUrl,
            $postSlugToNewUrl,
            $postGuidToNewUrl,
            $pageIdToNewUrl,
            $pageSlugToNewUrl,
            $pageGuidToNewUrl,
        );

        foreach ($wpPostIdToLocalId as $localPostId) {
            $post = $this->posts->findById($localPostId);

            if ($post === null) {
                continue;
            }

            $rewritten = $rewriter->rewrite($post->content);

            if (!$rewritten['changed']) {
                continue;
            }

            $this->posts->update(
                id: $post->id,
                title: $post->title,
                content: $rewritten['content'],
                excerpt: $post->excerpt,
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
            );
        }

        foreach ($wpPageIdToLocalId as $localPageId) {
            $page = $this->pages->findById($localPageId);

            if ($page === null) {
                continue;
            }

            $rewritten = $rewriter->rewrite($page->content);

            if (!$rewritten['changed']) {
                continue;
            }

            $this->pages->update(
                id: $page->id,
                title: $page->title,
                content: $rewritten['content'],
                excerpt: $page->excerpt,
                status: $page->status,
                publishedAt: $page->publishedAt,
                parentId: $page->parentId,
                featuredImageId: $page->featuredImageId,
                slug: $page->slug,
                contentFormat: $page->contentFormat,
                featuredImageCrop: $page->featuredImageCrop,
                visibility: $page->visibility,
                commentsOpen: $page->commentsOpen,
            );
        }
    }

    /**
     * @param array<int, string> $statuses
     * @param array<int, int> $wpPostIdToLocalId
     * @return array{0: array<int, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function buildPostLinkMaps(array $statuses, array $wpPostIdToLocalId): array
    {
        $idMap = [];
        $slugMap = [];
        $guidMap = [];

        foreach ($this->source->posts(['post'], $statuses) as $wpPost) {
            $localId = $wpPostIdToLocalId[$wpPost['ID']] ?? null;

            if ($localId === null) {
                continue;
            }

            $post = $this->posts->findById($localId);

            if ($post === null) {
                continue;
            }

            $newUrl = post_permalink($post);
            $idMap[$wpPost['ID']] = $newUrl;

            if ($wpPost['post_name'] !== '') {
                $slugMap[$wpPost['post_name']] = $newUrl;
            }

            if ($wpPost['guid'] !== '') {
                $guidMap[$wpPost['guid']] = $newUrl;
            }
        }

        return [$idMap, $slugMap, $guidMap];
    }

    /**
     * @param array<int, string> $statuses
     * @param array<string, int> $wpPageIdToLocalId
     * @return array{0: array<int, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function buildPageLinkMaps(array $statuses, array $wpPageIdToLocalId): array
    {
        $idMap = [];
        $slugMap = [];
        $guidMap = [];

        foreach ($this->source->posts(['page'], $statuses) as $wpPage) {
            $localId = $wpPageIdToLocalId[(string) $wpPage['ID']] ?? null;

            if ($localId === null) {
                continue;
            }

            $page = $this->pages->findById($localId);

            if ($page === null) {
                continue;
            }

            $newUrl = page_permalink($page);
            $idMap[$wpPage['ID']] = $newUrl;

            if ($wpPage['post_name'] !== '') {
                $slugMap[$wpPage['post_name']] = $newUrl;
            }

            if ($wpPage['guid'] !== '') {
                $guidMap[$wpPage['guid']] = $newUrl;
            }
        }

        return [$idMap, $slugMap, $guidMap];
    }

    /**
     * Creates redirects from every `_wp_old_slug` WordPress recorded for a post/page (each
     * prior rename gets its own redirect) so old bookmarks/search links don't 404. Only
     * considers post_ids imported as a Post or Page this batch, not attachments or other
     * post types. A redirect that would collide with an existing one or the current path is
     * skipped with a warning rather than overwritten.
     *
     * @param array<string, int> $wpPageIdToLocalId
     * @param array<int, int> $wpPostIdToLocalId
     */
    private function importOldSlugRedirects(string $batchId, array $wpPageIdToLocalId, array $wpPostIdToLocalId): void
    {
        foreach ($wpPostIdToLocalId as $wpPostId => $localPostId) {
            $post = $this->posts->findById($localPostId);

            if ($post === null) {
                continue;
            }

            $currentUrl = post_permalink($post);

            foreach ($this->source->oldSlugs($wpPostId) as $oldSlug) {
                $oldUrl = Permalinks::service()->postUrlForSlugAndDate($oldSlug, $post->publishedAt);
                $this->createOldSlugRedirect($batchId, $wpPostId, $post->title, $oldUrl, $currentUrl);
            }
        }

        foreach ($wpPageIdToLocalId as $wpPageIdString => $localPageId) {
            $page = $this->pages->findById($localPageId);

            if ($page === null) {
                continue;
            }

            $currentUrl = page_permalink($page);

            // A page's URL is its ancestor chain plus its own slug, not
            // a flat 'page/{slug}' — a previous WordPress slug
            // is swapped in for the page's own slug only, keeping the
            // same (current) ancestor chain, since WP's own slug history
            // has nothing to say about Lumora Press's parent/child URL
            // structure.
            $ancestorSegments = array_map(
                static fn (\LumoraPress\Models\Page $ancestor): string => $ancestor->slug,
                $this->pages->ancestors($page->id),
            );

            foreach ($this->source->oldSlugs((int) $wpPageIdString) as $oldSlug) {
                $oldUrl = home_url(implode('/', [...$ancestorSegments, $oldSlug]));
                $this->createOldSlugRedirect($batchId, (int) $wpPageIdString, $page->title, $oldUrl, $currentUrl);
            }
        }
    }

    private function createOldSlugRedirect(string $batchId, int $wpId, string $title, string $oldUrl, string $currentUrl): void
    {
        $sourcePath = $this->sourcePathFromUrl($oldUrl);

        if ($sourcePath === $this->sourcePathFromUrl($currentUrl)) {
            // A slug that was later changed back to a previous value —
            // the "old" URL is the current one, so there's nothing to
            // redirect from.
            return;
        }

        if ($this->redirects->findBySourcePath($sourcePath) !== null) {
            $this->warnings[] = "#{$wpId} (\"{$title}\"): a previous slug (\"{$sourcePath}\") already has a redirect — left as-is rather than overwritten.";

            return;
        }

        $redirect = $this->redirects->create($sourcePath, $currentUrl);
        $this->registry->record($batchId, self::SOURCE, 'redirect', (int) $redirect['id'], (string) $wpId);
    }

    /**
     * A permalink helper (post_permalink()/page_permalink(), both
     * built on home_url()) always returns a full absolute URL, but
     * RedirectService::create()'s $sourcePath is matched against the
     * install's own base-path-stripped request path (see
     * RedirectService::findBySourcePath()'s own docblock) — so the
     * host and, for a subdirectory install, the install's own base path
     * both need stripping first, or a redirect built this way would
     * never actually match a real incoming request.
     */
    private function sourcePathFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return ltrim(BasePath::stripFrom($path), '/');
    }

    /**
     * Regenerates size variants for every image this batch imported. Unlike a normal admin
     * upload, the import path (MediaImporter::importFromLocalFile()) never calls
     * ThumbnailService, so imported images have no thumbnail rows until this runs.
     * regenerate() already no-ops safely for non-images, so ids aren't pre-filtered here.
     */
    private function regenerateThumbnails(string $batchId): void
    {
        foreach ($this->registry->idsForBatch($batchId, 'media') as $entry) {
            $this->thumbnails->regenerate($entry['contentId']);
        }
    }

    /**
     * Post-Import — a defensive integrity check, not a redundant repeat
     * of what run() already caught inline: every id this batch's own
     * ContentImportRegistry rows point at should still resolve to a
     * real row (catching, for instance, something else deleting content
     * mid-import, or a future regression in one of the Importer
     * classes), and a post/page's own featured_image_id should still
     * resolve to a real Media row rather than a media id whose own
     * import step failed *after* the post/page referencing it had
     * already been created. Findings are appended to warnings() the
     * same way every other stage's own problems are, rather than a
     * separate report — the admin already reads one combined warnings
     * list after every import.
     */
    private function verifyImport(string $batchId): void
    {
        $lookups = [
            'user' => fn (int $id): bool => $this->users->findById($id) !== null,
            'category' => fn (int $id): bool => $this->categories->findById($id) !== null,
            'tag' => fn (int $id): bool => $this->tags->findById($id) !== null,
            'folder' => fn (int $id): bool => $this->folders->findById($id) !== null,
            'media' => fn (int $id): bool => $this->media->find($id) !== null,
            'page' => fn (int $id): bool => $this->pages->findById($id) !== null,
            'post' => fn (int $id): bool => $this->posts->findById($id) !== null,
            'comment' => fn (int $id): bool => $this->comments->findById($id) !== null,
            'redirect' => fn (int $id): bool => $this->redirects->find($id) !== null,
            'download' => fn (int $id): bool => $this->downloads === null || $this->downloads->findById($id) !== null,
            'download_category' => fn (int $id): bool => $this->downloadCategories === null || $this->downloadCategories->findById($id) !== null,
        ];

        foreach ($lookups as $contentType => $exists) {
            foreach ($this->registry->idsForBatch($batchId, $contentType) as $entry) {
                if (!$exists($entry['contentId'])) {
                    $this->warnings[] = "Verification: imported {$contentType} #{$entry['contentId']} could not be found after import.";
                }
            }
        }

        foreach ($this->registry->idsForBatch($batchId, 'post') as $entry) {
            $post = $this->posts->findById($entry['contentId']);

            if ($post?->featuredImageId !== null && $this->media->find($post->featuredImageId) === null) {
                $this->warnings[] = "Verification: post #{$post->id} (\"{$post->title}\") has a featured image reference that no longer resolves.";
            }
        }

        foreach ($this->registry->idsForBatch($batchId, 'page') as $entry) {
            $page = $this->pages->findById($entry['contentId']);

            if ($page?->featuredImageId !== null && $this->media->find($page->featuredImageId) === null) {
                $this->warnings[] = "Verification: page #{$page->id} (\"{$page->title}\") has a featured image reference that no longer resolves.";
            }
        }
    }

    /**
     * Compatibility — a read-only diagnostic scan over the *whole*
     * source (not just what was selected to import), run once as part
     * of the always-on 'verify' stage regardless of which content types
     * were actually selected, since it's reporting on data this import
     * never touches at all rather than verifying anything it created.
     */
    private function reportUnsupportedContent(): void
    {
        $this->reportUnsupportedPostTypes();
        $this->reportUnsupportedPlugins();
    }

    /**
     * Every post_type this importer has no explicit support for at all
     * (see HANDLED_POST_TYPES's own docblock) gets one aggregated
     * warning naming it and its total row count — e.g. Contact Form 7's
     * own `wpcf7_contact_form` type, or a business-directory plugin's
     * own listing type. Never a per-row warning: a real multi-plugin site
     * can easily carry thousands of rows of a single unsupported type.
     */
    private function reportUnsupportedPostTypes(): void
    {
        $unsupported = [];

        foreach ($this->source->postTypeCounts() as $postType => $count) {
            if ($count > 0 && !in_array($postType, self::HANDLED_POST_TYPES, true)) {
                $unsupported[$postType] = $count;
            }
        }

        if ($unsupported === []) {
            return;
        }

        ksort($unsupported);

        foreach ($unsupported as $postType => $count) {
            $this->warnings[] = "{$count} item" . ($count === 1 ? '' : 's') . " of custom post type \"{$postType}\" were not imported — no Lumora Press equivalent exists for it.";
        }
    }

    /**
     * Aggregates every active source plugin not in HANDLED_PLUGIN_SLUGS into a single
     * warning, since a site can have 25+ active plugins with no migratable data of their
     * own. Always a no-op for a WXR-sourced import, since WXR has no `wp_options` table.
     */
    private function reportUnsupportedPlugins(): void
    {
        $raw = $this->source->option('active_plugins');

        if ($raw === null) {
            return;
        }

        $plugins = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($plugins)) {
            return;
        }

        $unsupported = [];

        foreach ($plugins as $pluginFile) {
            if (!is_string($pluginFile) || $pluginFile === '') {
                continue;
            }

            $slug = strstr($pluginFile, '/', true);
            $slug = $slug !== false ? $slug : $pluginFile;

            if (!in_array($slug, self::HANDLED_PLUGIN_SLUGS, true)) {
                $unsupported[$slug] = true;
            }
        }

        if ($unsupported === []) {
            return;
        }

        $unsupported = array_keys($unsupported);
        sort($unsupported);

        $this->warnings[] = 'This site also has ' . count($unsupported) . ' other active plugin(s) with no Lumora Press equivalent, so any data of their own was not imported: ' . implode(', ', $unsupported) . '.';
    }

    /**
     * @return array{options: array<string, mixed>, completedStages: array<int, string>, warnings: array<int, string>, maps: array<string, mixed>}|null
     */
    private function loadState(string $batchId): ?array
    {
        $raw = $this->registry->snapshotForBatch($batchId, 'progress_snap');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{options: array<string, mixed>, completedStages: array<int, string>, warnings: array<int, string>, maps: array<string, mixed>} $state
     */
    private function persistState(string $batchId, array $state): void
    {
        $this->registry->upsertSnapshot($batchId, self::SOURCE, 'progress_snap', (string) json_encode($state));
    }

    /**
     * Deletion order: comments before their posts, posts/pages before authors, media/
     * categories/tags last. Menus/widgets have no per-id delete path, so their pre-import
     * `nav_menus`/`widgets_config` option snapshots are written straight back instead.
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

            $siteSettingsSnapshot = $this->registry->snapshotForBatch($batchId, 'site_settings_snap');

            if ($siteSettingsSnapshot !== null) {
                $previousSiteSettings = json_decode($siteSettingsSnapshot, true);

                if (is_array($previousSiteSettings)) {
                    foreach ($previousSiteSettings as $key => $value) {
                        $this->config->setOption((string) $key, $value);
                    }
                }
            }

            $navMenusSnapshot = $this->registry->snapshotForBatch($batchId, 'nav_menus_snap');

            if ($navMenusSnapshot !== null) {
                $this->config->setOption('nav_menus', $navMenusSnapshot);
            }

            $widgetsSnapshot = $this->registry->snapshotForBatch($batchId, 'widgets_snap');

            if ($widgetsSnapshot !== null) {
                $this->config->setOption('widgets_config', $widgetsSnapshot);
            }

            foreach (['comment', 'post', 'page', 'download', 'download_category', 'media', 'redirect', 'folder', 'category', 'tag', 'user'] as $contentType) {
                $ids = array_map(
                    static fn (array $entry): int => $entry['contentId'],
                    $this->registry->idsForBatch($batchId, $contentType),
                );

                if ($contentType === 'folder') {
                    // FolderService::delete() refuses to delete a folder
                    // that still has child folders — a plain in-order
                    // delete over idsForBatch()'s own (unordered)
                    // rows silently leaves an imported parent folder
                    // behind whenever its child happens to be deleted
                    // after it, since a nested folder tree (both the
                    // Media Library Folders and Downloads category
                    // imports create one) has no guaranteed row order
                    // to rely on.
                    $this->deleteFoldersDeepestFirst($ids);

                    continue;
                }

                foreach ($ids as $id) {
                    $this->deleteOne($contentType, $id);
                }
            }

            $this->registry->clearBatch($batchId);
        }

        return $totals;
    }

    /**
     * Repeatedly attempts to delete every folder in $ids, in passes —
     * each pass removes whatever's now childless (a leaf, or a folder
     * whose only children were already deleted in an earlier pass),
     * regardless of what order $ids arrived in. A folder that still
     * can't be deleted once no pass makes further progress (e.g. an
     * admin manually filed extra media into an imported folder before
     * removing the import) is left behind rather than looping forever —
     * the same "refuse rather than orphan its contents" behavior
     * FolderService::delete() already documents for a manual delete.
     *
     * @param array<int, int> $ids
     */
    private function deleteFoldersDeepestFirst(array $ids): void
    {
        $remaining = $ids;

        while ($remaining !== []) {
            $stillRemaining = [];
            $progressed = false;

            foreach ($remaining as $id) {
                if ($this->folders->delete($id)) {
                    $progressed = true;

                    continue;
                }

                $stillRemaining[] = $id;
            }

            if (!$progressed) {
                break;
            }

            $remaining = $stillRemaining;
        }
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
            // Null under the same condition as 'download' above — see
            // that case's own comment.
            'download_category' => $this->downloadCategories?->delete($id),
            'media' => $this->media->delete($id),
            'redirect' => $this->redirects->delete($id),
            // 'folder' is never reached here — removeAll() special-cases
            // it via deleteFoldersDeepestFirst() before this match runs.
            'category' => $this->categories->delete($id),
            'tag' => $this->tags->delete($id),
            'user' => $this->users->delete($id),
            default => null,
        };
    }

    /**
     * Maps WordPress General/Permalinks/Reading/Discussion/Media/Privacy options onto the
     * subset PressConfig has a matching key for. Homepage/Privacy settings reference a page
     * ID that can't resolve until the 'pages' stage runs, so that part is deferred to
     * applyPageDependentSiteSettings(); every key either method writes is snapshotted here
     * up front (JSON blob, content type 'site_settings_snap') so removeAll() can restore
     * pre-import values, the same pattern importMenus()/importWidgets() use.
     */
    private function importSiteSettings(string $batchId): void
    {
        $wpOptions = $this->source->siteOptions();

        $previous = [
            'site_name' => $this->config->option('site_name', ''),
            'site_tagline' => $this->config->option('site_tagline', ''),
            'timezone' => $this->config->option('timezone', 'UTC'),
            'date_format' => $this->config->option('date_format', 'F j, Y'),
            'time_format' => $this->config->option('time_format', 'g:i a'),
            'permalink_structure' => $this->config->option('permalink_structure', '/post/%postname%/'),
            // Homepage — resolved in applyPageDependentSiteSettings().
            'homepage_display' => $this->config->option('homepage_display', 'posts'),
            'homepage_page_id' => $this->config->option('homepage_page_id', ''),
            'homepage_posts_page_id' => $this->config->option('homepage_posts_page_id', ''),
            // Reading.
            'posts_per_page' => $this->config->option('posts_per_page', '10'),
            'discourage_search_engines' => $this->config->option('discourage_search_engines', '0'),
            'feed_item_limit' => $this->config->option('feed_item_limit', '10'),
            'feed_full_content' => $this->config->option('feed_full_content', '1'),
            // Discussion.
            'comment_default_status_for_new_posts' => $this->config->option('comment_default_status_for_new_posts', 'open'),
            'comment_moderation_manual_all' => $this->config->option('comment_moderation_manual_all', '0'),
            'comment_moderation_auto_approve_previous' => $this->config->option('comment_moderation_auto_approve_previous', '1'),
            'comment_close_after_days' => $this->config->option('comment_close_after_days', '0'),
            'comment_threading_enabled' => $this->config->option('comment_threading_enabled', '1'),
            'comment_max_nesting_level' => $this->config->option('comment_max_nesting_level', '5'),
            'comment_pagination_enabled' => $this->config->option('comment_pagination_enabled', '0'),
            'comment_per_page' => $this->config->option('comment_per_page', '50'),
            'comment_default_page' => $this->config->option('comment_default_page', 'last'),
            'comment_order' => $this->config->option('comment_order', 'asc'),
            'comment_notify_admin_new' => $this->config->option('comment_notify_admin_new', '1'),
            'comment_notify_admin_moderation' => $this->config->option('comment_notify_admin_moderation', '1'),
            'comment_author_name_required' => $this->config->option('comment_author_name_required', '1'),
            'comment_author_email_required' => $this->config->option('comment_author_email_required', '1'),
            'comment_require_registration' => $this->config->option('comment_require_registration', '0'),
            'comment_moderation_keywords' => $this->config->option('comment_moderation_keywords', ''),
            'comment_disallowed_keywords' => $this->config->option('comment_disallowed_keywords', ''),
            'comment_moderation_link_limit' => $this->config->option('comment_moderation_link_limit', '0'),
            'avatars_enabled' => $this->config->option('avatars_enabled', '1'),
            'avatar_max_rating' => $this->config->option('avatar_max_rating', 'G'),
            'avatar_default' => $this->config->option('avatar_default', 'mp'),
            // Media.
            'thumbnail_size_small_width' => $this->config->option('thumbnail_size_small_width', '150'),
            'thumbnail_size_small_height' => $this->config->option('thumbnail_size_small_height', '150'),
            'thumbnail_size_small_mode' => $this->config->option('thumbnail_size_small_mode', 'crop'),
            'thumbnail_size_medium_width' => $this->config->option('thumbnail_size_medium_width', '300'),
            'thumbnail_size_medium_height' => $this->config->option('thumbnail_size_medium_height', '300'),
            'thumbnail_size_large_width' => $this->config->option('thumbnail_size_large_width', '1024'),
            'thumbnail_size_large_height' => $this->config->option('thumbnail_size_large_height', '1024'),
            // Privacy — resolved in applyPageDependentSiteSettings().
            'privacy_policy_page_id' => $this->config->option('privacy_policy_page_id', ''),
        ];

        $this->registry->record($batchId, self::SOURCE, 'site_settings_snap', 0, null, json_encode($previous));

        $blogname = html_entity_decode($wpOptions['blogname'] ?? '', ENT_QUOTES, 'UTF-8');

        if (trim($blogname) !== '') {
            $this->config->setOption('site_name', $blogname);
        }

        $blogdescription = html_entity_decode($wpOptions['blogdescription'] ?? '', ENT_QUOTES, 'UTF-8');

        if (trim($blogdescription) !== '') {
            $this->config->setOption('site_tagline', $blogdescription);
        }

        $timezone = $this->resolveTimezone($wpOptions['timezone_string'] ?? '', $wpOptions['gmt_offset'] ?? '');

        if ($timezone !== null) {
            $this->config->setOption('timezone', $timezone);
        }

        if (($wpOptions['date_format'] ?? '') !== '') {
            $this->config->setOption('date_format', $wpOptions['date_format']);
        }

        if (($wpOptions['time_format'] ?? '') !== '') {
            $this->config->setOption('time_format', $wpOptions['time_format']);
        }

        $structure = $this->resolvePermalinkStructure($wpOptions['permalink_structure'] ?? '');

        if ($structure !== null) {
            $this->config->setOption('permalink_structure', $structure);
        }

        // Reading.
        if (is_numeric($wpOptions['posts_per_page'] ?? '')) {
            $this->config->setOption('posts_per_page', (string) max(1, min(200, (int) $wpOptions['posts_per_page'])));
        }

        if (($wpOptions['blog_public'] ?? '') !== '') {
            $this->config->setOption('discourage_search_engines', $wpOptions['blog_public'] === '0' ? '1' : '0');
        }

        if (is_numeric($wpOptions['posts_per_rss'] ?? '')) {
            $this->config->setOption('feed_item_limit', (string) max(1, min(100, (int) $wpOptions['posts_per_rss'])));
        }

        if (($wpOptions['rss_use_excerpt'] ?? '') !== '') {
            $this->config->setOption('feed_full_content', $wpOptions['rss_use_excerpt'] === '1' ? '0' : '1');
        }

        // Discussion.
        if (in_array($wpOptions['default_comment_status'] ?? '', ['open', 'closed'], true)) {
            $this->config->setOption('comment_default_status_for_new_posts', $wpOptions['default_comment_status']);
        }

        if (($wpOptions['comment_moderation'] ?? '') !== '') {
            $this->config->setOption('comment_moderation_manual_all', $wpOptions['comment_moderation'] === '1' ? '1' : '0');
        }

        if (($wpOptions['comment_whitelist'] ?? '') !== '') {
            $this->config->setOption('comment_moderation_auto_approve_previous', $wpOptions['comment_whitelist'] === '1' ? '1' : '0');
        }

        if (($wpOptions['close_comments_for_old_posts'] ?? '') === '1') {
            $days = is_numeric($wpOptions['close_comments_days_old'] ?? '') ? (int) $wpOptions['close_comments_days_old'] : 14;
            $this->config->setOption('comment_close_after_days', (string) max(0, $days));
        } elseif (($wpOptions['close_comments_for_old_posts'] ?? '') === '0') {
            $this->config->setOption('comment_close_after_days', '0');
        }

        if (($wpOptions['thread_comments'] ?? '') !== '') {
            $this->config->setOption('comment_threading_enabled', $wpOptions['thread_comments'] === '1' ? '1' : '0');
        }

        if (is_numeric($wpOptions['thread_comments_depth'] ?? '')) {
            $this->config->setOption('comment_max_nesting_level', (string) max(1, min(20, (int) $wpOptions['thread_comments_depth'])));
        }

        if (($wpOptions['page_comments'] ?? '') !== '') {
            $this->config->setOption('comment_pagination_enabled', $wpOptions['page_comments'] === '1' ? '1' : '0');
        }

        if (is_numeric($wpOptions['comments_per_page'] ?? '')) {
            $this->config->setOption('comment_per_page', (string) max(1, min(500, (int) $wpOptions['comments_per_page'])));
        }

        if (($wpOptions['default_comments_page'] ?? '') !== '') {
            $this->config->setOption('comment_default_page', $wpOptions['default_comments_page'] === 'oldest' ? 'first' : 'last');
        }

        if (in_array($wpOptions['comment_order'] ?? '', ['asc', 'desc'], true)) {
            $this->config->setOption('comment_order', $wpOptions['comment_order']);
        }

        if (($wpOptions['comments_notify'] ?? '') !== '') {
            $this->config->setOption('comment_notify_admin_new', $wpOptions['comments_notify'] === '1' ? '1' : '0');
        }

        if (($wpOptions['moderation_notify'] ?? '') !== '') {
            $this->config->setOption('comment_notify_admin_moderation', $wpOptions['moderation_notify'] === '1' ? '1' : '0');
        }

        if (($wpOptions['require_name_email'] ?? '') !== '') {
            $required = $wpOptions['require_name_email'] === '1' ? '1' : '0';
            $this->config->setOption('comment_author_name_required', $required);
            $this->config->setOption('comment_author_email_required', $required);
        }

        if (($wpOptions['comment_registration'] ?? '') !== '') {
            $this->config->setOption('comment_require_registration', $wpOptions['comment_registration'] === '1' ? '1' : '0');
        }

        if (trim($wpOptions['moderation_keys'] ?? '') !== '') {
            $this->config->setOption('comment_moderation_keywords', trim($wpOptions['moderation_keys']));
        }

        // WordPress 5.5 renamed 'blacklist_keys' to 'disallowed_keys' —
        // prefer the current name, fall back to the legacy one.
        $disallowedKeys = trim(($wpOptions['disallowed_keys'] ?? '') !== '' ? $wpOptions['disallowed_keys'] : ($wpOptions['blacklist_keys'] ?? ''));

        if ($disallowedKeys !== '') {
            $this->config->setOption('comment_disallowed_keywords', $disallowedKeys);
        }

        if (is_numeric($wpOptions['comment_max_links'] ?? '')) {
            $this->config->setOption('comment_moderation_link_limit', (string) max(0, (int) $wpOptions['comment_max_links']));
        }

        if (($wpOptions['show_avatars'] ?? '') !== '') {
            $this->config->setOption('avatars_enabled', $wpOptions['show_avatars'] === '1' ? '1' : '0');
        }

        $avatarRating = strtoupper((string) ($wpOptions['avatar_rating'] ?? ''));

        if (in_array($avatarRating, ['G', 'PG', 'R', 'X'], true)) {
            $this->config->setOption('avatar_max_rating', $avatarRating);
        }

        $avatarDefault = $this->resolveAvatarDefault((string) ($wpOptions['avatar_default'] ?? ''));

        if ($avatarDefault !== null) {
            $this->config->setOption('avatar_default', $avatarDefault);
        }

        // Media — WordPress's own "Thumbnail"/"Medium"/"Large" sizes map
        // directly onto Lumora Press's small/medium/large (both use the
        // same 150×150/300×300/1024×1024 defaults).
        if (is_numeric($wpOptions['thumbnail_size_w'] ?? '') && is_numeric($wpOptions['thumbnail_size_h'] ?? '')) {
            $this->config->setOption('thumbnail_size_small_width', (string) max(1, (int) $wpOptions['thumbnail_size_w']));
            $this->config->setOption('thumbnail_size_small_height', (string) max(1, (int) $wpOptions['thumbnail_size_h']));
        }

        if (($wpOptions['thumbnail_crop'] ?? '') !== '') {
            $this->config->setOption('thumbnail_size_small_mode', $wpOptions['thumbnail_crop'] === '1' ? 'crop' : 'fit');
        }

        if (is_numeric($wpOptions['medium_size_w'] ?? '') && is_numeric($wpOptions['medium_size_h'] ?? '')) {
            $this->config->setOption('thumbnail_size_medium_width', (string) max(1, (int) $wpOptions['medium_size_w']));
            $this->config->setOption('thumbnail_size_medium_height', (string) max(1, (int) $wpOptions['medium_size_h']));
        }

        if (is_numeric($wpOptions['large_size_w'] ?? '') && is_numeric($wpOptions['large_size_h'] ?? '')) {
            $this->config->setOption('thumbnail_size_large_width', (string) max(1, (int) $wpOptions['large_size_w']));
            $this->config->setOption('thumbnail_size_large_height', (string) max(1, (int) $wpOptions['large_size_h']));
        }
    }

    /**
     * WordPress's `avatar_default` option names a handful of built-in
     * generator styles Lumora Press's own avatar_default field
     * (admin/views/settings/discussion.php) mirrors by name, plus two
     * WordPress-only values with no Lumora Press equivalent:
     * 'gravatar_default' (Gravatar's own logo) and legacy aliases for
     * "Mystery Person" ('mystery'/'mm'). Returns null for anything
     * unrecognized so the caller leaves the existing setting untouched
     * rather than storing a value discussion.php doesn't know how to
     * render.
     */
    private function resolveAvatarDefault(string $wpValue): ?string
    {
        return match ($wpValue) {
            'mystery', 'mm' => 'mp',
            'identicon', 'wavatar', 'retro', 'monsterid', 'robohash', 'blank' => $wpValue,
            default => null,
        };
    }

    /**
     * Resolves Homepage/Privacy settings once the 'pages' stage has run, since each
     * references a WordPress page ID that only maps to a local ID after that page is
     * imported. A source page ID that doesn't resolve is skipped with a warning rather than
     * writing a dangling local page ID.
     *
     * @param array<string, int> $wpPageIdToLocalId
     */
    private function applyPageDependentSiteSettings(array $wpPageIdToLocalId): void
    {
        $wpOptions = $this->source->siteOptions();

        if (($wpOptions['show_on_front'] ?? 'posts') === 'page') {
            $wpFrontPageId = (string) ((int) ($wpOptions['page_on_front'] ?? '0'));
            $localFrontPageId = $wpFrontPageId !== '0' ? ($wpPageIdToLocalId[$wpFrontPageId] ?? null) : null;

            if ($localFrontPageId !== null) {
                $this->config->setOption('homepage_display', 'page');
                $this->config->setOption('homepage_page_id', (string) $localFrontPageId);

                $wpPostsPageId = (string) ((int) ($wpOptions['page_for_posts'] ?? '0'));
                $localPostsPageId = $wpPostsPageId !== '0' ? ($wpPageIdToLocalId[$wpPostsPageId] ?? null) : null;

                if ($localPostsPageId !== null && $localPostsPageId !== $localFrontPageId) {
                    $this->config->setOption('homepage_posts_page_id', (string) $localPostsPageId);
                }
            } else {
                $this->warnings[] = 'Site settings: the source site\'s static homepage was not imported — kept "Your latest posts" instead.';
            }
        } else {
            $this->config->setOption('homepage_display', 'posts');
        }

        $wpPrivacyPageId = (string) ((int) ($wpOptions['wp_page_for_privacy_policy'] ?? '0'));

        if ($wpPrivacyPageId !== '0') {
            $localPrivacyPageId = $wpPageIdToLocalId[$wpPrivacyPageId] ?? null;

            if ($localPrivacyPageId !== null) {
                $this->config->setOption('privacy_policy_page_id', (string) $localPrivacyPageId);
            } else {
                $this->warnings[] = 'Site settings: the source site\'s privacy policy page was not imported, so no privacy policy page was set.';
            }
        }
    }

    /**
     * Falls back to WordPress's numeric `gmt_offset` when `timezone_string` is empty (the
     * admin picked "UTC+2" instead of a city), translating it to an `Etc/GMT` identifier —
     * note the inverted sign (UTC+2 is "Etc/GMT-2"). A fractional offset (e.g. UTC+5:30) has
     * no `Etc/GMT` equivalent and resolves to null rather than being rounded incorrectly.
     */
    private function resolveTimezone(string $timezoneString, string $gmtOffset): ?string
    {
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);

        if ($timezoneString !== '' && in_array($timezoneString, $identifiers, true)) {
            return $timezoneString;
        }

        if ($gmtOffset === '' || !is_numeric($gmtOffset)) {
            return null;
        }

        $offset = (float) $gmtOffset;

        if ($offset !== floor($offset) || $offset < -12.0 || $offset > 14.0) {
            return null;
        }

        $etcOffset = -(int) $offset;
        $identifier = 'Etc/GMT' . ($etcOffset >= 0 ? '+' . $etcOffset : (string) $etcOffset);

        return in_array($identifier, $identifiers, true) ? $identifier : null;
    }

    /**
     * Mirrors admin/views/settings/permalinks.php's own validation
     * exactly (must contain %postname%, only letters/numbers/hyphens/
     * underscores/slashes/%tag% characters) plus one check that page has
     * no reason to make: every %tag% token actually present must be one
     * PermalinkService::buildPostUrl() knows how to replace (see
     * SUPPORTED_PERMALINK_TOKENS), since an unresolved token would be
     * left as dead literal text in every post URL rather than causing an
     * error anywhere obvious.
     */
    private function resolvePermalinkStructure(string $wpStructure): ?string
    {
        $structure = trim($wpStructure);

        if ($structure === '' || !str_contains($structure, '%postname%')) {
            return null;
        }

        if (preg_match('/^[a-zA-Z0-9%_\-\/]+$/', $structure) !== 1) {
            return null;
        }

        preg_match_all('/%[a-z_]+%/', $structure, $tokenMatches);

        foreach ($tokenMatches[0] as $token) {
            if (!in_array($token, self::SUPPORTED_PERMALINK_TOKENS, true)) {
                $this->warnings[] = "Site settings: source permalink structure \"{$wpStructure}\" uses an unsupported tag ({$token}) — kept this site's existing permalink structure instead.";

                return null;
            }
        }

        return '/' . trim($structure, '/') . '/';
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
     * Preserves WordPress category parent/child hierarchy via multi-pass resolution
     * (repeatedly creating whatever's parent is already resolved), since the source query's
     * order isn't a full topological sort for hierarchies deeper than two levels. An
     * unresolvable parent reference falls back to top-level. Tags have no hierarchy and need
     * no such pass. The returned map is used by importMenus() to resolve category menu items.
     *
     * @return array<int, int> wpTermId => local category id
     */
    private function importCategories(string $batchId, ?ExistingContentMode $existingContentMode = null): array
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

                $localIdByWpTermId[$term['term_id']] = $this->createOrUpdateCategory($batchId, $term, $parentId, $existingContentMode);
                $progressed = true;
            }

            if (!$progressed) {
                // Every remaining term's parent id doesn't exist in the
                // 'category' taxonomy at all (data oddity) — create the
                // rest top-level rather than looping forever.
                foreach ($stillRemaining as $term) {
                    $localIdByWpTermId[$term['term_id']] = $this->createOrUpdateCategory($batchId, $term, null, $existingContentMode);
                }

                $stillRemaining = [];
            }

            $remaining = $stillRemaining;
        }

        return $localIdByWpTermId;
    }

    /**
     * @param array{term_id: int, name: string, parent: int} $term
     */
    private function createOrUpdateCategory(string $batchId, array $term, ?int $parentId, ?ExistingContentMode $existingContentMode): int
    {
        $externalId = (string) $term['term_id'];
        $existingId = $existingContentMode !== null
            ? $this->registry->existingLocalId(self::SOURCE, 'category', $externalId)
            : null;

        if ($existingId !== null) {
            if ($existingContentMode === ExistingContentMode::Overwrite) {
                // Re-importing must never wipe an image the admin assigned locally after the
                // last import — WordPress category terms have no image of their own to overwrite it with.
                $existingImageId = $this->categories->findById($existingId)?->imageId;
                $this->categories->update($existingId, $term['name'], '', $parentId, imageId: $existingImageId);
            }

            return $existingId;
        }

        $category = $this->categories->create($term['name'], '', $parentId);
        $this->registry->record($batchId, self::SOURCE, 'category', $category->id, $externalId);

        return $category->id;
    }

    /**
     * The returned map is used by importMenus() (Stage 8) to resolve a
     * menu item pointing at a tag term.
     *
     * @return array<int, int> wpTermId => local tag id
     */
    private function importTags(string $batchId, ?ExistingContentMode $existingContentMode = null): array
    {
        $localIdByWpTermId = [];

        foreach ($this->source->terms('post_tag') as $term) {
            $externalId = (string) $term['term_id'];
            $existingId = $existingContentMode !== null
                ? $this->registry->existingLocalId(self::SOURCE, 'tag', $externalId)
                : null;

            if ($existingId !== null) {
                if ($existingContentMode === ExistingContentMode::Overwrite) {
                    $this->tags->update($existingId, $term['name'], '');
                }

                $localIdByWpTermId[$term['term_id']] = $existingId;

                continue;
            }

            $tag = $this->tags->findOrCreateByName($term['name']);
            $this->registry->record($batchId, self::SOURCE, 'tag', $tag->id, $externalId);
            $localIdByWpTermId[$term['term_id']] = $tag->id;
        }

        return $localIdByWpTermId;
    }

    /**
     * Imports Simple Download Monitor's `sdm_categories` taxonomy into Downloads' own
     * `download_categories` table, not the shared Media `folders` table, since an SDM
     * download has no real Media Library attachment relationship. Returns an empty map with
     * nothing imported when the Downloads plugin isn't active; the underlying files still
     * import as plain Media/Redirects either way, just uncategorized.
     *
     * @return array<int, int> wpTermId => local download category id
     */
    private function importDownloadCategories(string $batchId, ?ExistingContentMode $existingContentMode = null): array
    {
        $localIdByWpTermId = [];

        if ($this->downloadCategories === null) {
            return $localIdByWpTermId;
        }

        $remaining = $this->source->terms('sdm_categories');

        if ($remaining === []) {
            return $localIdByWpTermId;
        }

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

                $localIdByWpTermId[$term['term_id']] = $this->createOrUpdateDownloadCategory($batchId, $term['name'], $parentId, (string) $term['term_id'], $existingContentMode);
                $progressed = true;
            }

            if (!$progressed) {
                // A term whose parent never resolved (e.g. the parent
                // term itself wasn't tagged on any download and so never
                // appears as its own row) lands top-level rather than
                // looping forever — mirrors the old Folder-based import's
                // identical fallback, just against a real top-level
                // category instead of the synthetic wrapper.
                foreach ($stillRemaining as $term) {
                    $localIdByWpTermId[$term['term_id']] = $this->createOrUpdateDownloadCategory($batchId, $term['name'], null, (string) $term['term_id'], $existingContentMode);
                }

                $stillRemaining = [];
            }

            $remaining = $stillRemaining;
        }

        return $localIdByWpTermId;
    }

    /**
     * DownloadCategoryService's own three-call (create/update/registry
     * record) analog of createOrUpdateFolder() below.
     */
    private function createOrUpdateDownloadCategory(string $batchId, string $name, ?int $parentId, string $externalId, ?ExistingContentMode $existingContentMode): int
    {
        $existingId = $existingContentMode !== null
            ? $this->registry->existingLocalId(self::SOURCE, 'download_category', $externalId)
            : null;

        if ($existingId !== null) {
            if ($existingContentMode === ExistingContentMode::Overwrite) {
                $this->downloadCategories->update($existingId, $name, $parentId);
            }

            return $existingId;
        }

        $category = $this->downloadCategories->create($name, $parentId);
        $this->registry->record($batchId, self::SOURCE, 'download_category', $category->id, $externalId);

        return $category->id;
    }

    /**
     * Shared by importMediaFolders()/
     * importNextGenGalleries() — all three hand a Media Manager Folder
     * the same three FolderService calls (create/update/registry
     * record), differing only in what external id identifies "this
     * folder" across separate import runs (a real WordPress term id, a
     * NextGEN album/gallery id, or the synthetic "Downloads" wrapper's
     * fixed id).
     */
    private function createOrUpdateFolder(string $batchId, string $name, ?int $parentId, string $externalId, ?ExistingContentMode $existingContentMode): int
    {
        $existingId = $existingContentMode !== null
            ? $this->registry->existingLocalId(self::SOURCE, 'folder', $externalId)
            : null;

        if ($existingId !== null) {
            if ($existingContentMode === ExistingContentMode::Overwrite) {
                $this->folders->update($existingId, $name, $parentId);
            }

            return $existingId;
        }

        $folder = $this->folders->create($name, $parentId);
        $this->registry->record($batchId, self::SOURCE, 'folder', $folder->id, $externalId);

        return $folder->id;
    }

    /**
     * Imports Simple Download Monitor's `sdm_downloads` post type. A download whose file
     * lives on the source site becomes a Media item; one that only points at an external URL
     * becomes a Redirect instead, so it still gets a stable local URL and hit counter without
     * hosting the file. Either way its download count is seeded from SDM's own total rather
     * than restarting at 0. When the Downloads plugin is active, each also gets a `downloads`
     * table row via `DownloadService::recordExisting()` (not `create()`, since the underlying
     * Media/Redirect already exists) so it appears on the Downloads admin screen too.
     *
     * @param array<int, int> $wpUserIdToLocalId
     * @param array<int, int> $wpAttachmentIdToLocalMediaId
     * @param array<string, string> $oldRelativePathToNewUrl
     */
    private function importDownloads(string $batchId, array $wpUserIdToLocalId, array $wpAttachmentIdToLocalMediaId, array $oldRelativePathToNewUrl, ?ExistingContentMode $existingContentMode = null): void
    {
        $wpCategoryIdByWpTermId = $this->importDownloadCategories($batchId, $existingContentMode);
        $imageRewriter = new ContentImageRewriter();

        foreach ($this->source->posts(['sdm_downloads'], ['publish']) as $download) {
            $meta = $this->source->postMeta($download['ID']);
            $uploadUrl = $meta['sdm_upload'] ?? null;
            $thumbnailMediaId = isset($meta['_thumbnail_id'])
                ? ($wpAttachmentIdToLocalMediaId[(int) $meta['_thumbnail_id']] ?? null)
                : null;

            if ($uploadUrl === null || $uploadUrl === '') {
                $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): no file/URL recorded, skipped.";
                continue;
            }

            $categoryId = $this->resolveDownloadCategory($download['ID'], $wpCategoryIdByWpTermId);

            // DownloadService::update() already updates everything an
            // Overwrite needs in one call — a File-typed download's
            // underlying Media description, or a Url-typed one's
            // *existing* Redirect target url in place (never creating a
            // second one) — so a matched download needs none of the
            // file-existence/media-import work below, mirroring
            // importMedia()'s own "Skip/Overwrite never need this
            // attachment's file at all" precedent. Only reachable when
            // the Downloads plugin is active, since that's the
            // only place a 'download' registry row is ever recorded;
            // otherwise this download's underlying Media/Redirect can
            // still individually match further down via their own
            // registry rows.
            $existingDownloadId = $existingContentMode !== null && $this->downloads !== null
                ? $this->registry->existingLocalId(self::SOURCE, 'download', (string) $download['ID'])
                : null;

            if ($existingDownloadId !== null) {
                if ($existingContentMode === ExistingContentMode::Overwrite) {
                    $description = ($meta['sdm_description'] ?? '') !== '' ? $meta['sdm_description'] : $download['post_content'];
                    $rewrittenDescription = $imageRewriter->rewrite($description, $oldRelativePathToNewUrl);
                    $description = $rewrittenDescription['content'];

                    foreach ($rewrittenDescription['warnings'] as $warning) {
                        $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): {$warning}";
                    }

                    $this->downloads->update($existingDownloadId, $download['post_title'], $description, null, $uploadUrl, ContentFormat::Html, $categoryId);
                }

                continue;
            }

            $authorId = $wpUserIdToLocalId[$download['post_author']] ?? 1;
            $stats = $this->source->sdmDownloadStats($download['ID']);
            // Simple Download Monitor's own editor always stores this field
            // as raw HTML (see both recordExisting() calls below), never
            // Markdown/plain text — matches how post/page content is tagged.
            // A description typed directly into the download's own post
            // body (rather than SDM's dedicated Description field) falls
            // back to post_content, so it isn't silently dropped.
            $description = ($meta['sdm_description'] ?? '') !== '' ? $meta['sdm_description'] : $download['post_content'];

            $rewrittenDescription = $imageRewriter->rewrite($description, $oldRelativePathToNewUrl);
            $description = $rewrittenDescription['content'];

            foreach ($rewrittenDescription['warnings'] as $warning) {
                $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): {$warning}";
            }

            $uploadsMarker = '/wp-content/uploads/';

            if (str_contains($uploadUrl, $uploadsMarker)) {
                $relativePath = ltrim(strstr($uploadUrl, $uploadsMarker) ?: '', '/');
                $relativePath = substr($relativePath, strlen('wp-content/uploads/'));
                $absolutePath = rtrim($this->sourceUploadsPath, '/') . '/' . $relativePath;

                $existingMediaId = $existingContentMode !== null
                    ? $this->registry->existingLocalId(self::SOURCE, 'media', (string) $download['ID'])
                    : null;

                if ($existingMediaId === null && !is_file($absolutePath)) {
                    // Still creates the Download itself, just with no
                    // file attached (Download::$mediaId stays null,
                    // which DownloadService::hydrate()/recordExisting()
                    // both already handle — a File-typed download with
                    // no media is a normal, supported state, not a
                    // half-built one) — every other real detail (title,
                    // description, category, thumbnail) is still worth
                    // having on the site rather than discarding the
                    // whole item over one missing file, especially with
                    // many affected downloads at once (a host that
                    // deletes zip uploads is exactly this case).
                    // DownloadsShortcode::
                    // renderList() never shows a download with no real
                    // URL on the public site; it stays fully visible and
                    // editable in the admin Downloads list, where
                    // DownloadService::replaceFile()/convertToUrl() (both
                    // already work with a null $mediaId) are the normal
                    // way to attach the real file/URL once it's ready —
                    // far less work than recreating dozens of these from
                    // scratch by hand.
                    $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): file not found at {$absolutePath} — created without a file; attach one from its Edit Download screen once available.";

                    if ($this->downloads !== null) {
                        $newDownload = $this->downloads->recordExisting($download['post_title'], $description, null, DownloadType::File, null, null, ContentFormat::Html, $thumbnailMediaId, categoryId: $categoryId);
                        $this->registry->record($batchId, self::SOURCE, 'download', $newDownload->id, (string) $download['ID']);
                    }

                    continue;
                }

                $data = new ImportedMedia(
                    absolutePath: $absolutePath,
                    uploadedByUserId: $authorId,
                    fileName: basename($relativePath),
                    description: $description !== '' ? $description : null,
                    // SDM's own `sdm_upload` postmeta was never a Media
                    // Library attachment in its own model, so there is
                    // no Folder this file "belongs" in; categorization
                    // lives entirely on $categoryId below, not on where
                    // this Media item sits in the Library.
                    folderId: null,
                    externalId: (string) $download['ID'],
                    uploadedAt: $this->parseWpDate($download['post_date']),
                    relativeDirectory: dirname($relativePath),
                );

                try {
                    $media = $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data, $existingContentMode);
                    $this->mediaStats->seed((int) $media['id'], $stats['count'], $stats['lastDownloadedAt']);

                    if ($this->downloads !== null) {
                        $newDownload = $this->downloads->recordExisting($download['post_title'], $description, null, DownloadType::File, (int) $media['id'], null, ContentFormat::Html, $thumbnailMediaId, categoryId: $categoryId);
                        $this->registry->record($batchId, self::SOURCE, 'download', $newDownload->id, (string) $download['ID']);
                    }
                } catch (Throwable $exception) {
                    $this->warnings[] = "Download #{$download['ID']} (\"{$download['post_title']}\"): {$exception->getMessage()}";
                }

                continue;
            }

            $slug = $download['post_name'] !== '' ? $download['post_name'] : ('download-' . $download['ID']);

            try {
                // folderId: null — see the File-type branch's identical
                // comment above. This does mean a re-import of content
                // still containing the older
                // `[sdm_show_dl_from_category]` shortcode (kept working
                // for already-migrated content — see DownloadsShortcode's
                // own docblock) can no longer resolve for a *freshly* (re-)imported
                // download, since that shortcode's rendering depends on a
                // real Folder/folder_id; already-migrated rows keep
                // whatever folder_id they were given by an earlier import
                // and are unaffected.
                $redirect = $this->redirects->create('/downloads/' . $slug, $uploadUrl, folderId: null);
                $this->redirects->setHitCount((int) $redirect['id'], $stats['count']);
                $this->registry->record($batchId, self::SOURCE, 'redirect', (int) $redirect['id'], (string) $download['ID']);

                if ($this->downloads !== null) {
                    $newDownload = $this->downloads->recordExisting($download['post_title'], $description, null, DownloadType::Url, null, (int) $redirect['id'], ContentFormat::Html, $thumbnailMediaId, categoryId: $categoryId);
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
     * its parent "FocusWriter Themes") — a Download supports only one
     * category, so the more specific one is the more useful choice.
     *
     * @param array<int, int> $wpCategoryIdByWpTermId
     */
    private function resolveDownloadCategory(int $wpPostId, array $wpCategoryIdByWpTermId): ?int
    {
        $candidateTermIds = array_values(array_intersect(
            $this->source->termIdsForPost($wpPostId, 'sdm_categories'),
            array_keys($wpCategoryIdByWpTermId),
        ));

        if ($candidateTermIds === []) {
            return null;
        }

        foreach ($this->source->terms('sdm_categories') as $term) {
            if (in_array($term['term_id'], $candidateTermIds, true) && $term['parent'] !== 0) {
                return $wpCategoryIdByWpTermId[$term['term_id']];
            }
        }

        return $wpCategoryIdByWpTermId[$candidateTermIds[0]];
    }

    /**
     * Imports the "Media Library Folders" plugin's `media_folder` taxonomy (a standard
     * term_relationships-based taxonomy like `category`) into Media Manager folders, reusing
     * importCategories()'s multi-pass parent walk for hierarchy. Returns an empty map when
     * the source never ran that plugin.
     *
     * @return array<int, int> wpTermId => local folder id
     */
    private function importMediaFolders(string $batchId, ?ExistingContentMode $existingContentMode = null): array
    {
        $remaining = $this->source->terms('media_folder');
        $localIdByWpTermId = [];

        if ($remaining === []) {
            return $localIdByWpTermId;
        }

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

                $localIdByWpTermId[$term['term_id']] = $this->createOrUpdateFolder($batchId, $term['name'], $parentId, (string) $term['term_id'], $existingContentMode);
                $progressed = true;
            }

            if (!$progressed) {
                // Every remaining term's parent id doesn't exist in the
                // 'media_folder' taxonomy at all (data oddity) — create
                // the rest top-level rather than looping forever,
                // matching importCategories()'s own fallback.
                foreach ($stillRemaining as $term) {
                    $localIdByWpTermId[$term['term_id']] = $this->createOrUpdateFolder($batchId, $term['name'], null, (string) $term['term_id'], $existingContentMode);
                }

                $stillRemaining = [];
            }

            $remaining = $stillRemaining;
        }

        return $localIdByWpTermId;
    }

    /**
     * Mirrors resolveDownloadCategory()'s own "prefer a child term over its
     * parent" logic for an attachment tagged with more than one
     * `media_folder` term at once — Media items support only one folder,
     * so the more specific one is the more useful choice.
     *
     * @param array<int, int> $wpMediaFolderIdToLocalId
     */
    private function resolveMediaFolder(int $wpAttachmentId, array $wpMediaFolderIdToLocalId): ?int
    {
        $candidateTermIds = array_values(array_intersect(
            $this->source->termIdsForPost($wpAttachmentId, 'media_folder'),
            array_keys($wpMediaFolderIdToLocalId),
        ));

        if ($candidateTermIds === []) {
            return null;
        }

        foreach ($this->source->terms('media_folder') as $term) {
            if (in_array($term['term_id'], $candidateTermIds, true) && $term['parent'] !== 0) {
                return $wpMediaFolderIdToLocalId[$term['term_id']];
            }
        }

        return $wpMediaFolderIdToLocalId[$candidateTermIds[0]];
    }

    /**
     * @param array<int, int> $wpUserIdToLocalId
     * @return array{0: array<int, int>, 1: array<string, string>} [wpAttachmentId => local media id, old _wp_attached_file relative path (e.g. "2020/03/cover.png") => new Lumora media URL]
     */
    private function importMedia(string $batchId, array $wpUserIdToLocalId, ?ExistingContentMode $existingContentMode = null): array
    {
        $wpAttachmentIdToLocalMediaId = [];
        $oldRelativePathToNewUrl = [];
        $wpMediaFolderIdToLocalId = $this->importMediaFolders($batchId, $existingContentMode);

        foreach ($this->source->posts(['attachment'], self::ATTACHMENT_STATUSES) as $attachment) {
            $meta = $this->source->postMeta($attachment['ID']);
            $relativePath = $meta['_wp_attached_file'] ?? null;

            if ($relativePath === null) {
                $this->warnings[] = "Attachment #{$attachment['ID']}: no file path recorded, skipped.";
                continue;
            }

            $absolutePath = rtrim($this->sourceUploadsPath, '/') . '/' . $relativePath;

            // Skip/Overwrite never need this attachment's file at all —
            // MediaImporter::importFromLocalFile() reuses/updates the
            // already-imported row without touching the filesystem —
            // so a source whose uploads copy no longer has this file
            // (or never did) must not block a Skip/Overwrite re-import
            // over a file that was only ever needed the first time.
            $existingId = $existingContentMode !== null
                ? $this->registry->existingLocalId(self::SOURCE, 'media', (string) $attachment['ID'])
                : null;

            if ($existingId === null && !is_file($absolutePath)) {
                $this->warnings[] = "Attachment #{$attachment['ID']}: file not found at {$absolutePath}, skipped.";
                continue;
            }

            $authorId = $wpUserIdToLocalId[$attachment['post_author']] ?? 1;
            $folderId = $wpMediaFolderIdToLocalId !== []
                ? $this->resolveMediaFolder($attachment['ID'], $wpMediaFolderIdToLocalId)
                : null;

            $data = new ImportedMedia(
                absolutePath: $absolutePath,
                uploadedByUserId: $authorId,
                fileName: basename($relativePath),
                folderId: $folderId,
                // _wp_attachment_image_alt is a plain-text field rendered
                // via esc_attr() — postMeta() returns raw postmeta values
                // as-is (some meta keys carry serialized/structural data
                // that must not be entity-decoded), so unlike
                // WordPressSource's own fixed-shape methods, the decode
                // for this one known plain-text key happens here at its
                // point of use (see WordPressSource::
                // decodeEntities()'s docblock for the underlying bug).
                altText: ($meta['_wp_attachment_image_alt'] ?? '') !== '' ? html_entity_decode($meta['_wp_attachment_image_alt'], ENT_QUOTES, 'UTF-8') : null,
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
                $media = $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data, $existingContentMode);
            } catch (RuntimeException $exception) {
                $this->warnings[] = "Attachment #{$attachment['ID']}: {$exception->getMessage()}";
                continue;
            }

            $wpAttachmentIdToLocalMediaId[$attachment['ID']] = (int) $media['id'];
            $oldRelativePathToNewUrl[$relativePath] = $this->media->url($media);
        }

        return [$wpAttachmentIdToLocalMediaId, $oldRelativePathToNewUrl];
    }

    /**
     * Imports NextGEN Gallery's `ngg_gallery`/`ngg_pictures` tables: each gallery becomes a
     * Media Manager Folder (named from `title`, falling back to `name`), and each picture
     * becomes a Media item filed into it — a flat structure with no hierarchy of its own. A
     * picture's file is resolved from the gallery's `path` column plus its own `filename`,
     * never from `slug`, since the two can differ. `[ngg_...]` shortcodes are never rendered;
     * a page still containing one is flagged by flagUnsupportedShortcodes() instead. Each
     * `ngg_album` also imports as a parent Folder with its galleries nested underneath.
     *
     * @param array<int, int> $wpUserIdToLocalId
     */
    private function importNextGenGalleries(string $batchId, array $wpUserIdToLocalId, ?ExistingContentMode $existingContentMode = null): void
    {
        if ($this->sourceGalleryPath === null || $this->sourceGalleryPath === '') {
            return;
        }

        $albums = $this->source->nextGenAlbums();
        $wpAlbumIdToLocalFolderId = [];

        foreach ($albums as $album) {
            $albumName = $album['name'] !== '' ? $album['name'] : $album['slug'];
            $wpAlbumIdToLocalFolderId[$album['id']] = $this->createOrUpdateFolder($batchId, $albumName, null, (string) $album['id'], $existingContentMode);
        }

        $wpGalleryGidToAlbumId = $this->buildGalleryIdToAlbumId($albums);

        foreach ($this->source->nextGenGalleries() as $gallery) {
            $galleryDirName = basename(rtrim($gallery['path'], '/'));
            $absoluteGalleryDir = rtrim($this->sourceGalleryPath, '/') . '/' . $galleryDirName;
            $authorId = $wpUserIdToLocalId[$gallery['author']] ?? 1;
            $folderName = $gallery['title'] !== '' ? $gallery['title'] : $gallery['name'];
            $parentAlbumId = $wpGalleryGidToAlbumId[$gallery['gid']] ?? null;
            $parentFolderId = $parentAlbumId !== null ? ($wpAlbumIdToLocalFolderId[$parentAlbumId] ?? null) : null;
            $folderId = $this->createOrUpdateFolder($batchId, $folderName, $parentFolderId, (string) $gallery['gid'], $existingContentMode);

            foreach ($this->source->nextGenPictures($gallery['gid']) as $picture) {
                if ($picture['exclude'] === 1) {
                    continue;
                }

                $absolutePath = $absoluteGalleryDir . '/' . $picture['filename'];

                if (!is_file($absolutePath)) {
                    $this->warnings[] = "NextGEN picture #{$picture['pid']} (gallery \"{$folderName}\"): file not found at {$absolutePath}, skipped.";
                    continue;
                }

                $data = new ImportedMedia(
                    absolutePath: $absolutePath,
                    uploadedByUserId: $authorId,
                    fileName: $picture['filename'],
                    altText: $picture['alttext'] !== '' ? $picture['alttext'] : null,
                    description: $picture['description'] !== '' ? $picture['description'] : null,
                    folderId: $folderId,
                    // Prefixed to keep NextGEN's own pid numbering from
                    // colliding with a WordPress attachment ID in the
                    // same batch's 'media' provenance records — the two
                    // are entirely separate id spaces that can (and in
                    // practice do) overlap numerically.
                    externalId: 'ngg-' . $picture['pid'],
                    uploadedAt: $this->parseWpDate($picture['imagedate']),
                    relativeDirectory: $galleryDirName,
                );

                try {
                    $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data, $existingContentMode);
                } catch (Throwable $exception) {
                    $this->warnings[] = "NextGEN picture #{$picture['pid']} (gallery \"{$folderName}\"): {$exception->getMessage()}";
                }
            }
        }
    }

    /**
     * A gallery only ever belongs to one album in every real NextGEN
     * database examined so far, but nothing stops two albums from both
     * listing the same gallery id in their own `sortorder` — the first
     * album (by ascending `id`, i.e. iteration order of $albums, which
     * nextGenAlbums() already returns `ORDER BY id ASC`) wins, the same
     * "first match wins" tiebreak importMediaFolders()'s sibling
     * resolveMediaFolder()/resolveDownloadCategory() already use for an
     * analogous multi-parent ambiguity.
     *
     * @param array<int, array{id: int, name: string, slug: string, galleryIds: array<int, int>}> $albums
     * @return array<int, int> NextGEN gallery gid => the NextGEN album id it belongs to
     */
    private function buildGalleryIdToAlbumId(array $albums): array
    {
        $wpGalleryGidToAlbumId = [];

        foreach ($albums as $album) {
            foreach ($album['galleryIds'] as $galleryId) {
                if (!isset($wpGalleryGidToAlbumId[$galleryId])) {
                    $wpGalleryGidToAlbumId[$galleryId] = $album['id'];
                }
            }
        }

        return $wpGalleryGidToAlbumId;
    }

    /**
     * The returned map is used by importMenus() (Stage 8) to resolve a
     * menu item pointing at a page.
     *
     * @param array<int, string> $statuses
     * @param array<int, int> $wpUserIdToLocalId
     * @param array<int, int> $wpAttachmentIdToLocalMediaId
     * @param array<string, string> $oldRelativePathToNewUrl
     * @return array<string, int> wpPageId (string) => local page id
     */
    private function importPages(
        string $batchId,
        array $statuses,
        array $wpUserIdToLocalId,
        array $wpAttachmentIdToLocalMediaId,
        array $oldRelativePathToNewUrl,
        ?ExistingContentMode $existingContentMode = null,
    ): array {
        $externalMap = [];
        $imageRewriter = new ContentImageRewriter();

        foreach ($this->source->posts(['page'], $statuses) as $wpPage) {
            $meta = $this->source->postMeta($wpPage['ID']);
            $featuredImageId = isset($meta['_thumbnail_id'])
                ? ($wpAttachmentIdToLocalMediaId[(int) $meta['_thumbnail_id']] ?? null)
                : null;

            if (isset($meta['_thumbnail_id']) && $featuredImageId === null) {
                $this->warnings[] = "Page #{$wpPage['ID']} (\"{$wpPage['post_title']}\"): its featured image (attachment #{$meta['_thumbnail_id']}) could not be imported, so no featured image is set.";
            }

            $rewritten = $imageRewriter->rewrite($wpPage['post_content'], $oldRelativePathToNewUrl);

            foreach ($rewritten['warnings'] as $warning) {
                $this->warnings[] = "Page #{$wpPage['ID']} (\"{$wpPage['post_title']}\"): {$warning}";
            }

            $data = new ImportedPage(
                title: $this->resolveTitle($wpPage['post_title']),
                content: $rewritten['content'],
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
                $page = $this->pageImporter->import($batchId, self::SOURCE, $data, $externalMap, $existingContentMode);
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
     * @param array<string, string> $oldRelativePathToNewUrl
     * @return array<int, int> wpPostId => local post id
     */
    private function importPosts(
        string $batchId,
        array $statuses,
        array $wpUserIdToLocalId,
        array $wpAttachmentIdToLocalMediaId,
        array $oldRelativePathToNewUrl,
        ?ExistingContentMode $existingContentMode = null,
    ): array {
        $map = [];
        $imageRewriter = new ContentImageRewriter();

        foreach ($this->source->posts(['post'], $statuses) as $wpPost) {
            $meta = $this->source->postMeta($wpPost['ID']);
            $featuredImageId = isset($meta['_thumbnail_id'])
                ? ($wpAttachmentIdToLocalMediaId[(int) $meta['_thumbnail_id']] ?? null)
                : null;

            if (isset($meta['_thumbnail_id']) && $featuredImageId === null) {
                $this->warnings[] = "Post #{$wpPost['ID']} (\"{$wpPost['post_title']}\"): its featured image (attachment #{$meta['_thumbnail_id']}) could not be imported, so no featured image is set.";
            }

            $rewritten = $imageRewriter->rewrite($wpPost['post_content'], $oldRelativePathToNewUrl);

            foreach ($rewritten['warnings'] as $warning) {
                $this->warnings[] = "Post #{$wpPost['ID']} (\"{$wpPost['post_title']}\"): {$warning}";
            }

            $data = new ImportedPost(
                title: $this->resolveTitle($wpPost['post_title']),
                content: $rewritten['content'],
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
                $post = $this->postImporter->import($batchId, self::SOURCE, $data, $existingContentMode);
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
    private function importComments(string $batchId, array $wpPostIdToLocalId, array $wpUserIdToLocalId, ?ExistingContentMode $existingContentMode = null): void
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
                    $comment = $this->commentImporter->import($batchId, self::SOURCE, $data, $externalMap, $existingContentMode);
                    $externalMap[(string) $wpComment['comment_ID']] = $comment->id;
                } catch (\Throwable $exception) {
                    $this->warnings[] = "Comment #{$wpComment['comment_ID']}: {$exception->getMessage()}";
                }
            }
        }
    }

    /**
     * Imports WordPress's nav_menu taxonomy: each term is a menu, each nav_menu_item post is
     * one item, with its target and nesting living in postmeta. Menus always import as named
     * Lumora Press menus — WordPress never stores a location's human-readable label, only an
     * opaque per-theme slug, so there's no reliable way to auto-assign a Lumora Press
     * location; the admin finishes that via Appearance > Menus > Manage Locations. The
     * pre-import "nav_menus" option is snapshotted so removeAll() can restore it verbatim.
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
     * Resolves a WordPress menu item's postmeta into a label/url pair, or null when its
     * target wasn't imported. A 'custom' link always resolves. Every id map here was already
     * built by an earlier stage; this never queries the source database.
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
     * Imports classic widget instances from WordPress's `widget_{type}`/`sidebars_widgets`
     * options. Only types with a direct equivalent are imported (see WIDGET_TYPE_MAP); the
     * rest are aggregated into one warning per type. Anything that doesn't confidently match
     * a sidebar (including `wp_inactive_widgets`) lands in Inactive Widgets rather than being
     * dropped. The pre-import "widgets_config" option is snapshotted for removeAll().
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
     * Flags shortcodes with no import equivalent (Simple Download Monitor's display
     * shortcode, NextGEN's `[nggtags]`/`[ngg_slideshow]`) as a warning per occurrence, since
     * they're left as inert text. NextGEN's other forms are deliberately not flagged —
     * `NextGenGalleryShortcode` rewrites those to `[lumora_folder_gallery]` at render time.
     */
    private function flagUnsupportedShortcodes(int $wpId, string $title, string $content): void
    {
        if (str_contains($content, '[sdm_show_dl')) {
            $this->warnings[] = "#{$wpId} (\"{$title}\") still contains a [sdm_show_dl...] shortcode — Simple Download Monitor's download listing has no Lumora Press equivalent yet, so it will show as plain text.";
        }

        if (str_contains($content, '[nggtags') || str_contains($content, '[ngg_slideshow')) {
            $this->warnings[] = "#{$wpId} (\"{$title}\") still contains a [nggtags...]/[ngg_slideshow...] shortcode — NextGEN Gallery's tag-based gallery and slideshow displays have no Lumora Press equivalent yet, so they will show as plain text. The gallery's images were still imported into Media Manager.";
        }

        if (str_contains($content, '[table ')) {
            $this->warnings[] = "#{$wpId} (\"{$title}\") still contains a [table id=...] shortcode — TablePress has no Lumora Press equivalent yet, so it will show as plain text. Replace it by hand with a real HTML table (Content editor's HTML mode, or the WYSIWYG table tool) using the source site's TablePress data.";
        }

        // WordPress core's own built-in [gallery]/wp-block-gallery
        // shortcode/block is deliberately *not* checked here —
        // ContentImageRewriter::rewrite() already flags it (see that
        // class's own docblock) at the point the content is actually
        // rewritten, so a duplicate check here would double the warning
        // for the same post.
    }

    /**
     * PostService/PageService require a non-empty title; an empty
     * post_title is rare but real — still worth migrating rather than
     * dropping.
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
