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
use DateTimeZone;
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
use LumoraPress\Services\ThumbnailService;
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
 * for symmetry with Stage 8's own "Menus, Widgets" naming. Two
 * finalization stages (Post-Import) always run last, regardless of
 * which content types were selected: regenerateThumbnails() (media
 * imported here never goes through the normal upload-time thumbnail
 * generation path) and verifyImport() (a defensive integrity check over
 * everything this batch actually created).
 *
 * Scope for this first pass (see LPP-004's own TODO entry for the full
 * list of what's deferred): no WXR .xml path, no skip-vs-overwrite-
 * existing-content semantics. Site settings write-back (Stage 1) covers
 * title/tagline/timezone/date & time format/permalink structure only —
 * see importSiteSettings() for why Homepage/Reading/Discussion/Media/
 * Privacy settings stay out of scope (no Lumora Press equivalent exists
 * to write them into).
 *
 * Idempotency still follows DummyContentGenerator's own hard guard —
 * starting a *new* import refuses to begin while a previous
 * 'wordpress_import' batch still exists; removeAll() must be called
 * first. What changed (Import Options — dry run, resume, stage delay):
 * run() itself no longer executes every stage inline in one pass. It's
 * a thin loop over startOrResume()/runNextStage() — the same two
 * primitives the admin view uses directly to drive a real progress bar
 * and offer Resume after an interruption. Each stage's cross-stage id
 * maps (wpUserIdToLocalId and friends) and completed-stage list are
 * persisted to the database (ContentImportRegistry's 'progress_snap'
 * snapshot, via the new upsertSnapshot()) after every single stage, not
 * just at the end — so if the PHP process running an import dies
 * mid-way (a host's execution time limit, the admin's own browser
 * losing its connection with `ignore_user_abort()` off, the default),
 * whatever got recorded survives and inProgressBatch() can find it.
 * Resuming re-enters at the next incomplete stage rather than
 * restarting from scratch; it can only be resumed with the *original*
 * request's own options (including source DB credentials — the
 * connection itself is per-request and never persisted, so resuming
 * still requires the admin to re-enter them, same as before).
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

    /**
     * The only permalink tokens PermalinkService actually replaces (see
     * that class's own buildPostUrl()) — a source structure containing
     * any other token (WordPress core also supports %post_id%, %hour%,
     * %minute%, %second%) would leave that token as dead literal text in
     * every generated URL, so importSiteSettings() refuses to apply a
     * structure containing one instead of silently producing broken URLs.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_PERMALINK_TOKENS = ['%postname%', '%year%', '%monthnum%', '%day%', '%category%', '%author%'];

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
     * @param array{users?: bool, categories?: bool, media?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, site_settings?: bool, statuses?: array<int, string>, stage_delay_ms?: int} $options
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
     * The ordered stage list a given set of options will actually run —
     * pure and side-effect-free, so both runNextStage() (to know what's
     * left) and the admin view (to render a full stage checklist up
     * front, including stages not reached yet) can share it. Order
     * matches this class's own docblock on why it's fixed: media before
     * pages/posts so in-content image URLs can be rewritten while
     * content is built, menus/widgets last since they depend on
     * pages/posts/categories/tags already existing.
     *
     * 'internal_links', 'thumbnails', and 'verify' are always appended
     * last, unconditionally — none of the three is a content type with
     * its own toggle; they're finalization steps for whatever *did* get
     * imported. All three degrade to a real no-op when there's nothing
     * to do (an empty `idsForBatch()` loop, or no post/page slugs to
     * link to), so including them even when, say, every content type
     * was deselected costs nothing.
     *
     * @param array{users?: bool, categories?: bool, media?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, site_settings?: bool} $options
     * @return array<int, string>
     */
    public function plannedStages(array $options): array
    {
        $requested = [
            'site_settings' => $options['site_settings'] ?? false,
            'users' => $options['users'] ?? true,
            'categories' => $options['categories'] ?? true,
            'media' => $options['media'] ?? true,
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
     * Starts a fresh import, or resumes an already-incomplete one found
     * via inProgressBatch() — resuming always continues with that
     * batch's *original* options (including which content types were
     * selected), never the freshly-submitted $options, since changing
     * what's selected partway through a batch would leave its already-
     * completed stages inconsistent with the rest. A genuinely complete
     * batch still refuses to start a new import until it's removed —
     * the same guard this method replaces from the old single-pass
     * run().
     *
     * @param array{users?: bool, categories?: bool, media?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, site_settings?: bool, statuses?: array<int, string>, stage_delay_ms?: int} $options
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

        if ($this->existingBatchIds() !== []) {
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
     * @param array{users?: bool, categories?: bool, media?: bool, downloads?: bool, pages?: bool, posts?: bool, comments?: bool, menus?: bool, widgets?: bool, statuses?: array<int, string>} $options
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
     * @param array<string, string> $options
     * @param array<string, mixed> $maps
     */
    private function executeStage(string $batchId, string $stage, array $options, array &$maps): void
    {
        $statuses = $options['statuses'] ?? ['publish', 'draft', 'pending', 'future', 'private'];

        switch ($stage) {
            case 'site_settings':
                $this->importSiteSettings($batchId);
                break;
            case 'users':
                $maps['wpUserIdToLocalId'] = $this->importUsers($batchId);
                break;
            case 'categories':
                $maps['wpCategoryTermIdToLocalId'] = $this->importCategories($batchId);
                $maps['wpTagTermIdToLocalId'] = $this->importTags($batchId);
                break;
            case 'media':
                [$maps['wpAttachmentIdToLocalMediaId'], $maps['oldRelativePathToNewUrl']] = $this->importMedia($batchId, $maps['wpUserIdToLocalId'] ?? []);
                break;
            case 'downloads':
                $this->importDownloads($batchId, $maps['wpUserIdToLocalId'] ?? []);
                break;
            case 'pages':
                $maps['wpPageIdToLocalId'] = $this->importPages($batchId, $statuses, $maps['wpUserIdToLocalId'] ?? [], $maps['wpAttachmentIdToLocalMediaId'] ?? [], $maps['oldRelativePathToNewUrl'] ?? []);

                if ($options['site_settings'] ?? false) {
                    $this->applyPageDependentSiteSettings($maps['wpPageIdToLocalId']);
                }

                break;
            case 'posts':
                $maps['wpPostIdToLocalId'] = $this->importPosts($batchId, $statuses, $maps['wpUserIdToLocalId'] ?? [], $maps['wpAttachmentIdToLocalMediaId'] ?? [], $maps['oldRelativePathToNewUrl'] ?? []);
                break;
            case 'comments':
                if (($maps['wpPostIdToLocalId'] ?? []) !== []) {
                    $this->importComments($batchId, $maps['wpPostIdToLocalId'], $maps['wpUserIdToLocalId'] ?? []);
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
                break;
            case 'thumbnails':
                $this->regenerateThumbnails($batchId);
                break;
            case 'verify':
                $this->verifyImport($batchId);
                break;
        }
    }

    /**
     * URL & Link Migration — rewrites in-content `<a href>` links between
     * imported posts/pages so they point at the new local URL instead of
     * the old site. Runs after both 'pages' and 'posts' (needs every
     * local id already assigned, and rewrites cross-references between
     * the two content types), which means it re-saves each imported
     * post/page's content a second time via PostService::update()/
     * PageService::update() rather than doing this inline during
     * import() the way ContentImageRewriter's image pass does — image
     * URLs are already known the moment media import finishes, but a
     * link's *target* post/page might not be imported yet if it comes
     * later in iteration order, so this has to be a genuinely separate
     * pass once everything exists. See InternalLinkRewriter's own
     * docblock for how a plain URL string (no structured metadata the
     * way a menu item has) gets matched back to a WordPress post/page id
     * without needing to reconstruct the source's permalink structure.
     * Category/tag/author archive links are a deliberate scope boundary
     * — left untouched, same as any other link this class doesn't
     * recognize.
     *
     * update() requires every field, not just content — each post/page's
     * own current values are re-passed unchanged rather than risking a
     * default clobbering something (PageService::update()'s own
     * $commentsOpen, for instance, has no "keep existing" fallback at
     * all if omitted).
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
     * Post-Import — regenerates size variants for every image this batch
     * imported. Both importMedia() and importDownloads() bring files in
     * via MediaImporter::importFromLocalFile() -> MediaService::
     * registerExistingFile(), which only ever inserts the media row's own
     * metadata (dimensions, hash) — unlike a normal admin upload
     * (admin/views/media/upload.php), nothing along that path calls
     * ThumbnailService at all, so an imported image would otherwise have
     * no thumbnail rows until something else happened to regenerate them.
     * ThumbnailService::regenerate() itself already no-ops safely for a
     * non-image file, a missing source file, or a corrupt/unsupported
     * image (see its own docblock) — never throws — so every id
     * recorded under this batch is regenerated unconditionally rather
     * than pre-filtering by mime type here too.
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
     * LPP-004 Stage 1 — the site-wide `options` values a WordPress
     * install exposes on Settings > General/Permalinks/Reading/
     * Discussion/Media/Privacy, mapped onto the subset Lumora Press has
     * a real config key for (`PressConfig::option()`, the same keys the
     * corresponding admin/views/settings/*.php screens read/write).
     *
     * Homepage and Privacy settings reference a WordPress *page ID*
     * (`page_on_front`/`page_for_posts`/`wp_page_for_privacy_policy`),
     * which can't be resolved until the 'pages' stage has actually
     * imported that page and assigned it a local ID — this method only
     * ever runs as the (early) 'site_settings' stage, before 'pages'.
     * That resolution instead happens in
     * applyPageDependentSiteSettings(), called from the 'pages' case in
     * executeStage() once its wpPageIdToLocalId map exists. Every key
     * either method writes is still snapshotted here, up front, since
     * nothing else touches these keys in between.
     *
     * The pre-import value of every key this method (and
     * applyPageDependentSiteSettings()) writes is snapshotted as one
     * JSON blob (content type 'site_settings_snap', $contentId 0 as an
     * arbitrary placeholder — nothing ever looks this row up by id)
     * before anything is overwritten, mirroring importMenus()/
     * importWidgets()'s own snapshot-and-restore pattern for the same
     * reason: none of these are a real, individually delete()-able
     * content row, so removeAll() restores the exact pre-import values
     * instead.
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
     * Resolves Homepage and Privacy settings once the 'pages' stage has
     * run — see importSiteSettings()'s own docblock for why these three
     * keys can't be written any earlier: each is a WordPress page ID
     * (`page_on_front`, `page_for_posts`, `wp_page_for_privacy_policy`)
     * that only maps to a local page ID after that page has actually
     * been imported. Called from executeStage()'s 'pages' case, gated on
     * the same $options['site_settings'] opt-in importSiteSettings()
     * itself is gated on.
     *
     * A source page ID that doesn't resolve (the page wasn't imported —
     * excluded by the selected statuses, or import failed) is skipped
     * with a warning rather than writing a dangling local page ID that
     * would silently 404.
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
     * WordPress prefers `timezone_string` (a real IANA identifier, e.g.
     * "Europe/Helsinki") but falls back to a plain numeric UTC offset
     * (`gmt_offset`, e.g. "2" or "-5.5") when the admin picked "UTC+2"
     * from the dropdown instead of a city — Lumora Press's own timezone
     * setting only ever accepts a real IANA identifier (see
     * admin/views/settings/general.php's own validation against
     * DateTimeZone::listIdentifiers()), so a numeric offset needs
     * translating. `Etc/GMT` zones only exist at whole-hour offsets and
     * use inverted sign conventions from gmt_offset's own (UTC+2 is
     * "Etc/GMT-2", not "Etc/GMT+2") — a fractional offset (India's
     * UTC+5:30, for instance) has no `Etc/GMT` equivalent at all and is
     * left unresolved (null) rather than silently rounded to the wrong
     * zone.
     *
     * `Etc/GMT*` identifiers only appear in
     * `DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC)`, not the
     * plain `DateTimeZone::listIdentifiers()` admin/views/settings/
     * general.php's own timezone dropdown validates/populates from — a
     * real, working identifier either way
     * (`date_default_timezone_set()` in include/bootstrap.php accepts it
     * regardless), but a resolved `Etc/GMT` value won't show
     * pre-selected in that dropdown until the admin picks something from
     * it directly. A cosmetic gap in that screen, not a functional one
     * here.
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

        if ($remaining === []) {
            return $localIdByWpTermId;
        }

        // Every sdm_categories term nests under one top-level "Downloads"
        // folder rather than landing at the Media Library's own root — a
        // migrated site's downloads are Simple Download Monitor's own
        // category tree, not part of the site's regular media
        // organization, so keeping them under a single parent avoids
        // mixing the two. Only created when there's at least one category
        // to nest under it, so a source site using Simple Download
        // Monitor without categories doesn't get an empty "Downloads"
        // folder for nothing.
        $downloadsFolder = $this->folders->create('Downloads', null);
        $this->registry->record($batchId, self::SOURCE, 'folder', $downloadsFolder->id);

        while ($remaining !== []) {
            $stillRemaining = [];
            $progressed = false;

            foreach ($remaining as $term) {
                if ($term['parent'] === 0) {
                    $parentId = $downloadsFolder->id;
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
                    $folder = $this->folders->create($term['name'], $downloadsFolder->id);
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
            // Simple Download Monitor's own editor always stores this field
            // as raw HTML (see both recordExisting() calls below), never
            // Markdown/plain text — matches how post/page content is tagged.
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
                        $newDownload = $this->downloads->recordExisting($download['post_title'], $description, $folderId, DownloadType::File, (int) $media['id'], null, ContentFormat::Html);
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
                    $newDownload = $this->downloads->recordExisting($download['post_title'], $description, $folderId, DownloadType::Url, null, (int) $redirect['id'], ContentFormat::Html);
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
     * @return array{0: array<int, int>, 1: array<string, string>} [wpAttachmentId => local media id, old _wp_attached_file relative path (e.g. "2020/03/cover.png") => new Lumora media URL]
     */
    private function importMedia(string $batchId, array $wpUserIdToLocalId): array
    {
        $wpAttachmentIdToLocalMediaId = [];
        $oldRelativePathToNewUrl = [];

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
                // _wp_attachment_image_alt is a plain-text field rendered
                // via esc_attr() — postMeta() returns raw postmeta values
                // as-is (some meta keys carry serialized/structural data
                // that must not be entity-decoded), so unlike
                // WordPressSource's own fixed-shape methods, the decode
                // for this one known plain-text key happens here at its
                // point of use (LP-113 — see WordPressSource::
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
                $media = $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data);
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
    ): array {
        $externalMap = [];
        $imageRewriter = new ContentImageRewriter();

        foreach ($this->source->posts(['page'], $statuses) as $wpPage) {
            $meta = $this->source->postMeta($wpPage['ID']);
            $featuredImageId = isset($meta['_thumbnail_id'])
                ? ($wpAttachmentIdToLocalMediaId[(int) $meta['_thumbnail_id']] ?? null)
                : null;

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
     * @param array<string, string> $oldRelativePathToNewUrl
     * @return array<int, int> wpPostId => local post id
     */
    private function importPosts(
        string $batchId,
        array $statuses,
        array $wpUserIdToLocalId,
        array $wpAttachmentIdToLocalMediaId,
        array $oldRelativePathToNewUrl,
    ): array {
        $map = [];
        $imageRewriter = new ContentImageRewriter();

        foreach ($this->source->posts(['post'], $statuses) as $wpPost) {
            $meta = $this->source->postMeta($wpPost['ID']);
            $featuredImageId = isset($meta['_thumbnail_id'])
                ? ($wpAttachmentIdToLocalMediaId[(int) $meta['_thumbnail_id']] ?? null)
                : null;

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
