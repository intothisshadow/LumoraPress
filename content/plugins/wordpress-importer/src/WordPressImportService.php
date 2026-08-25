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
 * The orchestrator DummyContentGenerator (LPP-005) plays for synthetic
 * data — this class plays the same role for a real source: a
 * WordPressSource read connection plus the same batch-tagged
 * import()/importOrReuse() calls into the shared Importer layer.
 *
 * Ordering mirrors the dependency chain the Importer layer already
 * requires: Users -> Categories/Tags -> Media (attachments) -> NextGEN
 * Gallery galleries (a separate plugin table, folder-organized one
 * folder per gallery — see importNextGenGalleries()) -> Downloads
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
     * Post types this importer already has explicit support for —
     * either queried directly via posts() ('post'/'page'/'attachment'),
     * imported through a dedicated non-post-type mechanism of their own
     * (NextGEN Gallery's 'ngg_gallery'/'ngg_pictures', via their own
     * database tables — see importNextGenGalleries()), or handled
     * elsewhere in this class ('nav_menu_item' by importMenus(),
     * 'sdm_downloads' by importDownloads()). 'revision' is WordPress's
     * own auto-generated post history, not content an admin created, so
     * it's excluded here too rather than reported as unsupported.
     * Anything else found by reportUnsupportedPostTypes() has no Lumora
     * Press equivalent at all.
     *
     * @var array<int, string>
     */
    private const HANDLED_POST_TYPES = ['post', 'page', 'attachment', 'nav_menu_item', 'revision', 'sdm_downloads', 'ngg_gallery', 'ngg_pictures'];

    /**
     * Plugin directory slugs (an `active_plugins` entry's own directory
     * name, e.g. "folders/folders.php" -> "folders") this importer
     * already recognizes and migrates real data from — see this
     * plugin's own README for exactly what each one imports.
     *
     * @var array<int, string>
     */
    private const HANDLED_PLUGIN_SLUGS = ['simple-download-monitor', 'folders', 'nextgen-gallery'];

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
     * WordPress site at all — can be used without opening a real
     * database connection or parsing a WXR file first
     * (WordPressSource::connect() connects eagerly). Only run() actually
     * requires it.
     *
     * Typed against WordPressSourceInterface, not the concrete
     * WordPressSource, specifically so a WXR export file
     * (WordPressXmlSource) can be driven through the exact same stage
     * pipeline below — every importXxx() method already only calls
     * $this->source's interface methods, never anything
     * WordPressSource-specific.
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
         * Null when the Downloads plugin (LPP-008) isn't active — every
         * download still imports as a plain Media item/Redirect exactly
         * as before, just without the new `downloads` table's own
         * identity/metadata row on top. See importDownloads()'s own
         * docblock.
         */
        private readonly ?DownloadService $downloads = null,
        /**
         * A local filesystem copy of the source site's
         * `wp-content/gallery` folder — NextGEN Gallery's own image
         * store, a sibling of `wp-content/uploads`, never a subfolder of
         * it, so it needs its own path rather than being derived from
         * $sourceUploadsPath. Null (the default) when the admin didn't
         * supply one — importNextGenGalleries() then skips entirely
         * rather than trying to read from an empty path, the same
         * "nothing to do" degradation every other optional content type
         * already has when its own source data doesn't exist.
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
     * URL & Link Migration — every redirect this batch created (Simple
     * Download Monitor's own external-URL downloads, and `_wp_old_slug`
     * entries — see importDownloads()/importOldSlugRedirects()), as a
     * plain old-URL/new-URL table for the admin to review or export. A
     * pure ContentImportRegistry + RedirectService read, so — like
     * lastImportSummary()/removeAll() — it needs no source connection at
     * all, just a real batch id.
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
     * warning (a missing file, a skipped duplicate, and the like) —
     * used by the post-import summary screen (admin/views/maintenance/
     * import.php) to surface "you should look at this" items in their
     * own section rather than buried in a long flat warnings list.
     *
     * Deliberately a fixed set of substrings matched against the exact
     * wording reportUnsupportedPostTypes()/reportUnsupportedPlugins()/
     * flagUnsupportedShortcodes()/ContentImageRewriter::
     * unsupportedMediaConstructWarnings() already produce, rather than
     * a structured warning type threaded through every one of this
     * class's many warning call sites — those four are the only "needs
     * a human decision" warnings this importer currently produces;
     * everything else just documents what was skipped and why, with
     * nothing further for the admin to act on. `'still contains a '`
     * alone covers every leftover-shortcode/block variant
     * flagUnsupportedShortcodes()/unsupportedMediaConstructWarnings()
     * produce (sdm_show_dl/ngg/gallery/tiled-gallery/slideshow) without
     * needing to list each one by name here too.
     */
    public static function isActionNeededWarning(string $warning): bool
    {
        return str_contains($warning, 'still contains a ')
            || str_contains($warning, 'were not imported — no Lumora Press equivalent exists for it.')
            || str_contains($warning, 'other active plugin(s) with no Lumora Press equivalent');
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
     * Starts a fresh import, or resumes an already-incomplete one found
     * via inProgressBatch() — resuming always continues with that
     * batch's *original* options (including which content types were
     * selected), never the freshly-submitted $options, since changing
     * what's selected partway through a batch would leave its already-
     * completed stages inconsistent with the rest. A genuinely complete
     * batch still refuses to start a new import until it's removed —
     * the same guard this method replaces from the old single-pass
     * run() — *unless* `$options['existing_content']` is `'skip'` or
     * `'overwrite'` (Import Options — "Skip existing content"/
     * "Overwrite existing content"), in which case a brand new batch is
     * allowed to start right alongside an already-complete one: every
     * *Importer's own import() call (see ExistingContentMode) resolves
     * each row's external id against *every* previous batch of this
     * source via ContentImportRegistry::existingLocalId() — not just
     * this new batch — and either reuses or updates the row it finds
     * there instead of creating a duplicate. A row with no previous
     * match is still created fresh and recorded under this new batch,
     * same as always. Scoped to Users (already always reused by
     * username/email, unconditionally — see UserImporter), Posts,
     * Pages, Comments, and Media's main attachment stage only —
     * Downloads and NextGEN Gallery images are still always created
     * fresh on every import, a deliberate first-pass scope boundary
     * (see this ticket's own TODO entry).
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
     * URL & Link Migration — `_wp_old_slug` is WordPress's own automatic
     * record of every slug a published post/page ever had before its
     * current one (added by `wp_unique_post_slug()` whenever a slug
     * changes), so a link a search engine or an old bookmark still uses
     * doesn't just 404 after migration. A real production source can
     * carry more than one per post (confirmed against a real database —
     * two posts with 2 and 3 recorded renames respectively; see
     * `WordPressSource::oldSlugs()`'s own docblock) — every one of them
     * gets its own redirect, not just the most recent.
     *
     * Runs in the same 'internal_links' stage as rewriteInternalLinks()
     * (right after it, in executeStage()) — the natural home once every
     * imported post/page's own final local id and current permalink are
     * already known, and the ticket item this implements explicitly
     * folds into that same existing redirect-mapping work.
     *
     * Only ever considers a post_id that was actually imported as a Post
     * or Page this batch — `_wp_old_slug` can just as easily sit on an
     * attachment or a plugin-specific post type (both confirmed in real
     * data), neither of which has its own public single page in Lumora
     * Press to redirect to.
     *
     * Skipped, with a warning, rather than silently overwritten or
     * silently dropped, when the computed old-slug path already has a
     * redirect (from an earlier stage, or a previous old slug in this
     * same loop) or matches the post/page's own *current* path — the
     * latter happens when a slug was changed back to something it used
     * to be.
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

            foreach ($this->source->oldSlugs((int) $wpPageIdString) as $oldSlug) {
                $oldUrl = site_url('page/' . $oldSlug);
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
     * own listing type, both confirmed against a real production
     * database. Never a per-row warning: a real multi-plugin site can
     * easily carry thousands of rows of a single unsupported type (1866
     * in one real case, though that particular one — NextGEN Gallery's
     * own `ngg_pictures` — is itself excluded via HANDLED_POST_TYPES
     * since its actual content *is* imported, just via a dedicated table
     * rather than by post_type).
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
     * The source's own `active_plugins` option (a serialized array of
     * plugin-directory/main-file paths, e.g. "folders/folders.php") —
     * every one whose own directory slug isn't in HANDLED_PLUGIN_SLUGS
     * is aggregated into a single warning, rather than one per plugin,
     * since a real multi-plugin site can easily have 25+ active plugins
     * with no data of their own to migrate at all (most of a real
     * production site's own active list turned out to be admin/security/
     * editor-UI utilities with no content, confirmed against real data —
     * this warning doesn't try to distinguish those from a plugin that
     * genuinely has unmigrated content, since that distinction isn't
     * something this importer can determine generically).
     *
     * Never available from a WXR-sourced import — WXR carries no
     * `wp_options` table at all (WordPressXmlSource::option() always
     * returns null), so this degrades to "nothing to report" exactly
     * like every other database-only diagnostic in this class.
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
                    // after it, since a nested folder tree (this
                    // ticket's own Media Library Folders and Downloads
                    // category imports both create one) has no
                    // guaranteed row order to rely on.
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
                $this->categories->update($existingId, $term['name'], '', $parentId);
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
    private function importFolders(string $batchId, ?ExistingContentMode $existingContentMode = null): array
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
        // folder for nothing. Has no WordPress term of its own to key
        // off, unlike every other folder here — given a fixed synthetic
        // external id instead, so a Skip/Overwrite re-import finds the
        // same wrapper folder instead of creating a duplicate "Downloads"
        // tree.
        $downloadsFolderId = $this->createOrUpdateFolder($batchId, 'Downloads', null, 'sdm-downloads-root', $existingContentMode);

        while ($remaining !== []) {
            $stillRemaining = [];
            $progressed = false;

            foreach ($remaining as $term) {
                if ($term['parent'] === 0) {
                    $parentId = $downloadsFolderId;
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
                foreach ($stillRemaining as $term) {
                    $localIdByWpTermId[$term['term_id']] = $this->createOrUpdateFolder($batchId, $term['name'], $downloadsFolderId, (string) $term['term_id'], $existingContentMode);
                }

                $stillRemaining = [];
            }

            $remaining = $stillRemaining;
        }

        return $localIdByWpTermId;
    }

    /**
     * Shared by importFolders()/importMediaFolders()/
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
     * A download's own `_thumbnail_id` postmeta (Simple Download
     * Monitor supports setting a featured image on an `sdm_downloads`
     * post the same way a Post/Page does) is resolved via
     * $wpAttachmentIdToLocalMediaId the same way importPosts()/
     * importPages() already resolve theirs — only ever set when the
     * referenced attachment was itself imported (media selected, and
     * that particular attachment imported without error); left null
     * otherwise, same "skip rather than point at nothing" behavior as
     * every other featured-image resolution in this file.
     *
     * @param array<int, int> $wpUserIdToLocalId
     * @param array<int, int> $wpAttachmentIdToLocalMediaId
     * @param array<string, string> $oldRelativePathToNewUrl
     */
    private function importDownloads(string $batchId, array $wpUserIdToLocalId, array $wpAttachmentIdToLocalMediaId, array $oldRelativePathToNewUrl, ?ExistingContentMode $existingContentMode = null): void
    {
        $wpFolderIdByWpTermId = $this->importFolders($batchId, $existingContentMode);
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

            $folderId = $this->resolveDownloadFolder($download['ID'], $wpFolderIdByWpTermId);

            // DownloadService::update() already updates everything an
            // Overwrite needs in one call — a File-typed download's
            // underlying Media description, or a Url-typed one's
            // *existing* Redirect target url in place (never creating a
            // second one) — so a matched download needs none of the
            // file-existence/media-import work below, mirroring
            // importMedia()'s own "Skip/Overwrite never need this
            // attachment's file at all" precedent. Only reachable when
            // the Downloads plugin (LPP-008) is active, since that's the
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

                    $this->downloads->update($existingDownloadId, $download['post_title'], $description, $folderId, $uploadUrl, ContentFormat::Html);
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
                    $media = $this->mediaImporter->importFromLocalFile($batchId, self::SOURCE, $data, $existingContentMode);
                    $this->mediaStats->seed((int) $media['id'], $stats['count'], $stats['lastDownloadedAt']);

                    if ($this->downloads !== null) {
                        $newDownload = $this->downloads->recordExisting($download['post_title'], $description, $folderId, DownloadType::File, (int) $media['id'], null, ContentFormat::Html, $thumbnailMediaId);
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
                    $newDownload = $this->downloads->recordExisting($download['post_title'], $description, $folderId, DownloadType::Url, null, (int) $redirect['id'], ContentFormat::Html, $thumbnailMediaId);
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
     * The "Media Library Folders" plugin (`folders/folders.php`) organizes the regular
     * Media Library into a real, standard WordPress taxonomy —
     * `media_folder`, term_relationships-based exactly like `category` —
     * confirmed against a real production database (not guessed, per
     * this ticket's own established practice) rather than against a
     * second, inactive-looking plugin's own leftover `mgmlp_*` tables,
     * whose folder names turned out to just mirror the physical
     * `uploads/YYYY/MM` directory layout rather than anything an admin
     * ever organized by hand. Needs no new hierarchy-resolution code at
     * all — the same multi-pass parent walk importCategories() already
     * does over `terms()`'s own `parent` column — but, unlike
     * importFolders()'s Downloads-only synthetic "Downloads" wrapper,
     * lands a top-level folder at the Media Manager's real root, since
     * this *is* the site's own regular Media Library organization rather
     * than a foreign taxonomy being given a home. Returns an empty map,
     * with no folder created at all, when the source never ran that
     * plugin (no `media_folder` terms exist) — mirrors importFolders()'s
     * own early return.
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
     * Mirrors resolveDownloadFolder()'s own "prefer a child term over its
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
     * NextGEN Gallery's own `ngg_gallery`/`ngg_pictures` tables — each
     * gallery becomes a real Media Manager Folder named after the
     * gallery (its `title`, falling back to `name` when a gallery was
     * never given one), and each of its non-excluded pictures becomes a
     * real Media item filed into that folder — mirroring how Simple
     * Download Monitor categories and the "Media Library Folders"
     * plugin are already handled above. Unlike either of those, a
     * gallery is a flat list with no hierarchy of its own, so this needs
     * none of resolveDownloadFolder()/resolveMediaFolder()'s multi-term
     * "prefer the more specific parent" logic — one gallery, one folder,
     * always at the Media Manager's real root.
     *
     * A gallery's own `path` column (e.g.
     * `/wp-content/gallery/some-gallery-name/`) names its actual on-disk
     * folder — not necessarily the same as `slug` (see
     * WordPressSource::nextGenGalleries()'s own docblock) — so a
     * picture's file is resolved from $sourceGalleryPath plus that exact
     * folder name plus its own `filename`, never from `slug`.
     *
     * Out of scope for this pass (see this ticket's own TODO entry): the
     * `[ngg_...]` shortcodes themselves are never rendered — a page/post
     * still containing one is flagged as an import warning by
     * flagUnsupportedShortcodes() instead, same as
     * `[sdm_show_dl_from_category]`'s own handling.
     *
     * NextGEN's own `ngg_album` grouping is imported too: each album
     * becomes a real parent Media Manager Folder (mirroring
     * importFolders()/importMediaFolders()'s own nesting), and each of
     * its member galleries' folder is created underneath it instead of
     * at root — see buildGalleryIdToAlbumId()'s own docblock for how a
     * gallery belonging to more than one album is resolved. A gallery
     * that belongs to no album keeps today's flat root-level placement.
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
     * resolveMediaFolder()/resolveDownloadFolder() already use for an
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
     * WordPress shortcodes this import has no equivalent for (Simple
     * Download Monitor's own display shortcode, and NextGEN Gallery's
     * own gallery-embed shortcode) are left as literal, inert text in
     * imported content — this import brings the underlying download/
     * gallery-image data in via importDownloads()/importNextGenGalleries()
     * above, but has no shortcode processor of its own to make either
     * one actually render anything. Flagged as a warning per occurrence
     * so every affected page/post is visible in the import summary
     * rather than silently shipping broken-looking content.
     */
    private function flagUnsupportedShortcodes(int $wpId, string $title, string $content): void
    {
        if (str_contains($content, '[sdm_show_dl')) {
            $this->warnings[] = "#{$wpId} (\"{$title}\") still contains a [sdm_show_dl...] shortcode — Simple Download Monitor's download listing has no Lumora Press equivalent yet, so it will show as plain text.";
        }

        if (str_contains($content, '[ngg')) {
            $this->warnings[] = "#{$wpId} (\"{$title}\") still contains a [ngg...] shortcode — NextGEN Gallery's own gallery display has no Lumora Press equivalent yet, so it will show as plain text. The gallery's images were still imported into Media Manager.";
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
