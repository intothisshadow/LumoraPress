# Changelog

All notable changes to Lumora Press are documented in this file.

## [Unreleased] — 2026-07-25

### Added

- Theme Browser (LP-044): modernized the Appearance → Themes screen with
  visual previews and a details panel before activation.
  `LumoraPress\Core\Theme\ThemeRegistry` now parses the rest of the
  classic style.css header block — `Theme URI:`, `Author URI:`,
  `License:`, `License URI:`, `Requires at least:`, `Requires PHP:`, and
  `Tags:` — and detects `preview.*`/`thumbnail.*`/`screenshot.*` preview
  images (now including AVIF, alongside the existing PNG/JPG/WebP), with
  numbered variants (`screenshot-2.png`, `screenshot-3.png`, ...)
  collected into a multi-screenshot gallery; the first match by basename
  priority becomes the card thumbnail, and a placeholder renders when
  none exists. A theme's `README`/`CHANGELOG` file (if present) is
  detected too, and its contents are readable from the new details
  panel via `ThemeRegistry::documentContent()`. The Appearance screen
  (`admin/views/appearance.php`) gained a live search box (client-side,
  `admin/assets/js/theme-browser.js`) and a "Details" button per card
  that opens a native `<dialog>` showing the full parsed metadata,
  screenshot gallery, README/CHANGELOG contents, and Activate/Delete
  actions; a new `lp_theme_details_panel` action hook lets plugins
  extend that panel. `LumoraPress\Services\ThemeInstaller::delete()`
  backs the new "Delete inactive themes" action — the active theme
  can't be deleted, guarded both in the admin view and left to the
  caller in `ThemeInstaller` itself, which only knows about the
  filesystem. Card and details-panel preview thumbnails are capped at
  `max-width: 250px` with `height: auto` and no `object-fit`, so a
  theme's preview image is never cropped or stretched to fill a fixed
  box — only scaled down, proportionally, to fit. "Preview theme" opens
  the real front end in a new tab (`?lp_preview_theme={slug}`, "Preview"
  link on inactive themes): `index.php` checks the requester is logged
  in with `manage_themes` before honouring it, then swaps
  `ThemeRenderer`'s active theme for that request only — the site-wide
  `active_theme` option is never touched, so no other visitor is
  affected — and a new static bridge, `LumoraPress\Core\Theme\ThemePreview`
  (mirrors `SiteBranding`/`FeaturedImages`), marks the request so
  `index.php` can inject a "Previewing theme: {name} · Exit Preview" bar
  right after the rendered page's `<body>` tag, styled by its own
  stylesheet (`admin/assets/css/theme-preview-bar.css`) rather than the
  previewed theme's, so it works unmodified with every theme including
  custom ones. "List bundled theme options" is deferred — see `TODO.md`'s
  LP-044 section.
- Media Manager (LP-005, first pass, renamed from "media library"):
  broadened upload support beyond images/PDF to documents/archives (ZIP,
  CSS, TXT, XML, JSON) and audio/video, via new extensions/MIME types in
  `LumoraPress\Services\MediaService`'s allow-list (max upload size also
  raised from 10MB to 100MB to accommodate audio/video). A new
  `LumoraPress\Services\FolderService` backs a real, unlimited-depth
  virtual folder tree (new `{prefix}media_folders` table, migration
  `0012_add_media_folders_and_metadata.sql`, which also adds `folder_id`/
  `alt_text`/`caption`/`description`/`notes`/`file_hash` columns to
  `{prefix}media`) — unlike Categories'/Pages' existing `parent_id`
  hierarchy, which only guards against a record becoming its own *direct*
  parent, `FolderService::descendantIds()` walks the full tree so
  `update()` can reject a folder being moved inside any of its own
  descendants, not just itself. Folder assignment is purely
  organizational: `MediaService::move()` only ever updates `folder_id`,
  never `file_path`, so a file's public URL never changes when it's
  reorganized (verified manually — moved a file between folders,
  confirmed the download URL was byte-for-byte identical before and
  after). `FolderService::delete()` refuses to delete a non-empty folder
  (child folders or assigned media) rather than orphaning its contents,
  per the ticket's explicit "delete **empty** folders" wording — a
  deliberate difference from `CategoryService::delete()`'s
  orphan-on-delete precedent. New `LumoraPress\Services\MediaUsageChecker`
  warns before deleting a referenced file: it checks a post's featured
  image (new `PostService::titlesByFeaturedImage()`, mirroring the
  existing `countByAuthor()` "block because referenced" pattern used by
  the admin Users screen) and the site logo/favicon options (LP-034),
  and is extensible via a new `media_usage` filter for plugins or later
  features (e.g. a page featured-image field) without editing the class
  — the same extensibility pattern `FeedService`/`SearchService`/
  `MaintenanceGate` already established. Deleting an in-use file requires
  an explicit "Delete Anyway" confirmation after the warning is shown;
  bulk delete never force-deletes — an in-use file is silently skipped
  and reported rather than destroyed. `MediaService::browse()`/`search()`
  were replaced with one filterable `query()` (folder — including
  subfolders, search term, type category, upload date range). The new
  admin Media Manager screen (`admin/views/media.php`, filling in the
  `'media'` menu entry that has existed since the admin menu was defined
  but fell through to `placeholder.php`) has a recursively-rendered
  folder-tree sidebar, an upload form with a destination-folder picker, a
  per-file edit screen (metadata, move, delete-with-usage-check), and the
  app's first multi-select bulk-action UI (move/delete). Bundled
  alongside this rework: `MediaService`'s file-move operation is now
  injectable (`Closure`, defaulting to `move_uploaded_file()`), closing a
  previously-documented test gap (`PHP-TEST-SUITE.md`'s "Known gaps") —
  `upload()`'s success path (row insert, hash computation, folder
  assignment) is now actually covered, not just its validation/rejection
  branches. Bulk rename/replace/change-metadata, dimension/file-size
  search filters, folder search, Smart Collections, and a future S3/
  R2-compatible storage backend are deferred — see `TODO.md`'s LP-005
  section for exact scope.
- Appearance (LP-034, first pass): Theme Management, Branding, and Custom
  CSS. `LumoraPress\Core\Theme\ThemeRegistry::discover()` scans
  `content/themes/*` and parses each theme's `style.css` comment header
  (`Theme Name:`/`Description:`/`Version:`/`Author:` — the convention
  already existed in the default theme, but nothing parsed it before
  now), falling back to a title-cased slug rather than hiding a theme
  when its header is missing/malformed, plus a
  `screenshot.{png,jpg,jpeg,webp}` if present. A new admin Appearance
  screen (`admin/views/appearance.php`, filling in the `'appearance'`
  menu entry that has existed since the admin menu was defined but fell
  through to `placeholder.php`) lists every discovered theme as a card
  with a one-click "Activate" button (`setOption('active_theme', ...)`).
  New themes can be installed from a ZIP upload via
  `LumoraPress\Services\ThemeInstaller`: it reads `style.css` straight out
  of the still-open archive (so the theme's own name can drive its slug
  and a missing header is rejected before anything touches the
  filesystem), reuses the path-traversal/entry-count/size-cap safety
  checks LP-026's `UpdatePackageValidator` established (smaller limits —
  a theme isn't a whole application), and unwraps a GitHub-style wrapping
  folder — but is otherwise deliberately much simpler than the core
  update machinery, since installing a theme only ever adds one new,
  independent `content/themes/{slug}/` directory and never overlays live
  core files, so no staging/backup/rollback/migration ceremony is
  needed. Branding: a site logo and favicon (both reuse
  `MediaService::upload()`, storing the returned media ID in a new
  `site_logo_media_id`/`favicon_media_id` option, the same "store an ID,
  not a raw path" convention `Post::featuredImageId` already uses; `.ico`
  plus `image/x-icon`/`image/vnd.microsoft.icon` were added to
  `MediaService`'s allow-list alongside the already-supported PNG), and
  the `site_name` option — saved by the installer since LP-028, but never
  read back by anything until now — replaces the hardcoded "Lumora Press"
  text in the theme's `<title>`, header brand, footer copyright, and
  homepage heading. Since theme templates have no direct route to
  `PressConfig`/`MediaService` (and threading the same page-independent
  value through every one of `SiteController`'s render() calls would mean
  touching every action), these render through a new static bridge,
  `LumoraPress\Core\Theme\SiteBranding`, mirroring the existing
  `SiteUrl`/`BasePath`/`ActiveTheme` pattern — set once in
  `include/bootstrap.php`, exposed to themes via new `site_name()`/
  `site_logo_url()`/`favicon_url()`/`custom_css()` helpers in
  `include/helpers.php`. Custom Code: a single Custom CSS field (stored in
  a new `custom_css` option — the options table's `option_value` column
  is already `LONGTEXT`, so no migration was needed), rendered inside a
  `<style>` tag in `<head>` on every public page; `</style` sequences are
  stripped as a defensive measure against accidental markup breakage
  (not a security boundary — only `manage_options`/`manage_themes`
  administrators can set it, the same trust level arbitrary theme/plugin
  PHP already assumes). The Theme Options API, Layout/Typography/Color
  customization, Navigation/Footer content options, and Accessibility
  settings are deferred — see `TODO.md`'s LP-034 section for exact scope.
- Maintenance Mode (LP-033, first pass): a new
  `LumoraPress\Core\Http\MaintenanceGate`, checked once in `index.php`
  between BasePath stripping and `Router::dispatch()` — there is no
  pre-dispatch hook in `Router` itself, so this was the earliest point
  with access to config, auth, and the resolved request path. `/admin/*`
  is always exempt (an Administrator must always be able to log in and
  turn maintenance mode off); `/install/*` needed no special-casing since
  it's a wholly separate front controller that never reaches this code
  path. Maintenance mode can be switched on manually (a new "Maintenance
  Mode" section in `admin/views/settings.php`, or a one-click button on
  the dashboard) or scheduled via optional `maintenance_start_at`/
  `maintenance_end_at` options, checked at request time — the same
  "no background job" approach `PostService`/`PageService` already use
  for scheduled content, so no cron integration was needed. Administrators
  always bypass; an "Allow Editors to bypass" checkbox reuses the existing
  `moderate_comments` capability as the bypass boundary (Editor has it,
  Author/Contributor don't) rather than adding a new capability to
  `UserRole`. A blocked request gets a real `503 Service Unavailable`,
  `X-Robots-Tag: noindex`, and a `Retry-After` header (computed from the
  scheduled end time when set and in the future, otherwise a configurable
  `maintenance_retry_after_seconds`, `'0'` meaning omit it), then a
  theme-rendered `content/themes/default/maintenance.php` page (title,
  message, and a static estimated-return line — no JS countdown this
  pass) via the same `get_header()`/`get_footer()` pattern `404.php`
  already uses, so it's theme-customizable and picks up the site's
  branding automatically. `admin/views/dashboard.php` gained a warning
  banner (visible to `manage_options` users) while maintenance mode is
  active, and `admin/views/settings.php`'s POST dispatch gained two more
  branches (`maintenance_settings`, `maintenance_toggle` — the latter a
  single global control, so unlike per-row buttons elsewhere it needs no
  per-ID CSRF-action uniqueness) alongside LP-013/LP-014's existing
  `feed_settings`/`search_settings`. Plugins can register their own
  bypass rules via `apply_filters('maintenance_mode_bypass', bool
  $bypasses, ?User $user)` and react to state changes via
  `do_action('maintenance_mode_toggled', bool $enabled)` — `MaintenanceGate`
  takes `HookManager` via constructor injection rather than calling the
  global hook bridge directly, matching `UpdateService`'s/`FeedService`'s
  existing convention. IP whitelisting, a secret bypass URL/cookie, and
  email notifications are deferred — see `TODO.md`'s LP-033 section for
  exact scope.
- Search (LP-014, first pass): a unified, relevance-ranked search across
  Posts and Pages, backed by a new `LumoraPress\Services\SearchService`
  and real MySQL/MariaDB FULLTEXT indexes (new migration
  `0011_add_fulltext_index_to_posts_and_pages.sql` — two indexes per
  table, one on `title, content` and one on `title` alone, since MySQL
  requires a `MATCH()` column list to exactly match a defined FULLTEXT
  index; a single combined index can't also serve the title-only scoring
  query). Title matches are weighted higher than body-only matches
  (`MATCH(title, content) AGAINST(...) + MATCH(title) AGAINST(...) * 2`).
  Posts and Pages are queried independently, each respecting the same
  published/scheduled-and-due visibility rule as the homepage and
  archives, then merged into one relevance-ordered, paginated result set
  (`SearchService::paginateResults()`, a pure merge/sort/paginate step
  factored out separately from the MySQL-only fetch so it stays
  unit-testable). `SiteController::search()` (previously a stub that
  always rendered "No results found.") now calls `SearchService` for
  real. Matching terms are highlighted in results via a new
  `highlight_terms()` helper (`include/helpers.php`); a missing excerpt
  falls back to `make_excerpt()` (added in LP-013's Feeds work — same
  fallback, reused as-is). The admin Settings screen
  (`admin/views/settings.php`) gains a "Search" section controlling
  `search_min_length` (default 3) and `search_max_results` (default 50,
  combined across both content types) — this required retrofitting that
  view's POST handling to the `admin/views/comments.php`-style
  `form`-hidden-field dispatch pattern, since it previously assumed only
  one settings form would ever exist on the page (the existing Feeds
  section now also carries its own `form=feed_settings` field so the two
  sections save independently). The default theme's header gained a real
  search form (`role="search"`, plain GET, no JavaScript,
  `.lp-search-form*` classes in `style.css`), and `search.php` was
  rewritten from its "No results found." stub into a real, paginated
  results listing (`.lp-search-results*` classes) mirroring `archive.php`'s
  post-list markup. Categories/Tags/Authors/Comments/Media search,
  filters, live search/AJAX, "did you mean" suggestions, and a
  pluggable/filter-based Developer API are deferred — see `TODO.md`'s
  LP-014 section for exact scope. Since MySQL's `NATURAL LANGUAGE MODE`
  matches whole words only, exact-phrase, prefix, partial-substring, and
  fuzzy matching are not supported this pass either.
- RSS & Atom Feeds (LP-013, first pass): a single site-wide feed of
  published posts, backed by a new `LumoraPress\Services\FeedService`.
  `/feed` and `/feed/rss` serve RSS 2.0; `/feed/atom` serves Atom 1.0.
  Both formats reuse `PostService::paginatePublished()`, so feed items
  respect the same published/scheduled-and-due visibility rule as the
  homepage and archives — drafts and not-yet-due scheduled posts never
  appear. Each item includes title, permalink, publish date, author
  (`dc:creator`/`<author><name>`), and either the stored excerpt or a new
  `make_excerpt()`-generated one (55-word plain-text fallback, stripped of
  tags) when none is stored; full post content can additionally be
  included (`<content:encoded>`/`<content type="html">`) via a new
  `feed_full_content` option. New admin Settings screen
  (`admin/views/settings.php`, behind the existing `settings` menu entry,
  which previously fell through to a placeholder page) controls
  `feeds_enabled`, `feed_full_content`, `feed_item_limit` (clamped
  1–100), `feed_cache_lifetime`, and `feed_description`. Responses set
  `ETag`/`Last-Modified`/`Cache-Control` and honor conditional GET
  (`If-None-Match` → `304`) so a feed reader's routine poll doesn't
  regenerate the feed. Plugins can filter channel metadata and individual
  items via `apply_filters('feed_channel', ...)` and
  `apply_filters('feed_item', ...)` (`FeedService` takes `HookManager` via
  constructor injection, matching `UpdateService`'s existing convention,
  rather than calling the global hook bridge directly). The default theme
  auto-discovers both feeds via `<link rel="alternate">` in `header.php`
  and links to the RSS feed from the site footer
  (`.lp-site-footer__feed-link`, `style.css`). Category/tag/author/comment
  feeds, JSON Feed, page feeds, and enclosures are deferred — see
  `TODO.md`'s LP-013 section for exact scope.
- Comments (LP-012, first pass): a commenting system for Posts (Pages,
  media, and albums are out of scope — media/albums belong to Lumora
  Gallery, a separate application), backed by a new `{prefix}comments`
  table (migration `0009_create_comments_table.sql`) and
  `LumoraPress\Services\CommentService`. Guests can comment with a
  name/email/optional website; any signed-in dashboard user (not just
  Administrator/Editor) can comment as themselves without retyping their
  details, reusing the existing admin session rather than a separate
  public login system. Comments default to Pending unless the commenter
  is trusted — either they hold `moderate_comments`, or (for guests)
  their email has a prior Approved comment
  (`CommentService::hasPreviouslyApprovedComment()`). Replies nest to
  unlimited depth via a self-referencing `parent_id`; deleting a comment
  orphans its replies (`parent_id` cleared) rather than cascading the
  delete, the same trade-off `CategoryService::delete()` makes for child
  categories — while deleting a post now cascades to its comments
  (`PostService::delete()`). Basic spam protection: a honeypot field, 30
  seconds minimum between comments from the same IP, and CSRF on every
  form. Posts gained a `comment_status` (open/closed) column via
  `0010_add_comment_status_to_posts.sql` — the project's first
  `ALTER TABLE` migration, since every table until now shipped its full
  schema in one `CREATE TABLE` — exposed as an "Allow Comments" checkbox
  in the Post editor; there is also a site-wide "Allow comments
  site-wide" toggle on the new admin Comments screen
  (`admin/views/comments.php`, behind the `comments` menu entry, which
  previously fell through to a placeholder page). Moderators can
  approve/unapprove/mark spam/trash/permanently delete and edit a
  comment's text from that screen, filtered by status; the Dashboard's
  "Recent Comments" widget now shows real data instead of a placeholder.
  URLs in comment content are auto-linked
  (`format_comment_content()`, `rel="nofollow ugc noopener"`). Theme
  integration follows the existing `get_header()`/`get_footer()`/
  `get_sidebar()` pattern: a new `comments_template()` helper renders
  `content/themes/default/comments.php`, called from `single.php`.
  **A real bug was found and fixed during this feature's own browser
  testing**: `Csrf::field()` overwrites the session token for a given
  action name on every call, so any page rendering more than one form
  under the same action — the admin moderation screen's Approve/Spam/
  Trash buttons for one comment all shared `comment_moderate_{id}` —
  leaves every button but the last-rendered one silently submitting an
  already-invalidated token (the POST still redirects successfully, but
  the change never applies). Every CSRF action name in this feature is
  now scoped to be unique per form on the page (per comment *and* per
  target status for moderation buttons; per post *and* per reply target
  for public comment forms) — see `CommentService`'s and
  `SiteController::submitComment()`'s docblocks for the full
  explanation, since the same mistake is easy to reintroduce in future
  admin screens with multiple same-purpose buttons per row.
- User Management (LP-032, first pass): an admin Users screen
  (`admin/views/users.php`, mirroring Categories/Tags' list/create/edit/
  delete pattern) behind the `users` admin menu entry, which previously
  fell through to a placeholder page. Administrators can create/edit/
  delete user accounts, assign a role (Administrator/Editor/Author/
  Contributor/Subscriber — Guest is excluded, since it isn't a stored
  account role), and change a user's password from the edit screen
  (leave blank to keep the current one). Three safety guards on delete:
  an admin cannot delete their own account, the last remaining
  Administrator cannot be deleted or demoted to another role, and a user
  who has authored posts or pages cannot be deleted (there is no admin
  reassignment UI yet, so deleting them would orphan that content's
  `author_id`). Deleting a user also revokes their "Remember Me" tokens
  via `RememberMeService::forgetUserTokens()`. `UserService` gained
  `update()`, `delete()`, `changePassword()`, `listAll()`,
  `countByRole()`, and `usernameOrEmailExistsForOther()`; `PostService`
  and `PageService` each gained `countByAuthor()` to support the
  authored-content delete guard.
- Tags (LP-011, first pass): a flat, non-hierarchical taxonomy for Posts,
  backed by new `{prefix}tags` and `{prefix}post_tags` tables (migration
  `0008_create_tags_tables.sql`) and `LumoraPress\Services\TagService`.
  Tags are typed by name rather than picked from a list — the Post editor
  gets a comma-separated "Tags" field, and `TagService::findOrCreateByName()`
  resolves each name case-insensitively (so "Sci-Fi" and "sci-fi" reuse the
  same tag rather than creating a duplicate) and creates it automatically
  if it doesn't exist yet. That field is progressively enhanced by the
  project's first admin JavaScript file
  (`admin/assets/js/tag-input.js`, loaded on every admin page via
  `admin/views/layout-footer.php`): a dependency-free chip + live-filtered-
  suggestions widget (Arrow Up/Down, Enter, comma, Backspace, Escape, and
  mouse all work) that reads/writes the same underlying plain text field,
  which still works correctly with JavaScript disabled. `PostService`
  gained `paginateByTag()` (same shape as `paginateByCategory()`) and
  `delete()` now also cleans up `post_tags` rows. The admin Tags screen
  (`admin/views/tags.php`) mirrors Categories' list/create/edit/delete with
  a post count, minus the parent selector (tags have no hierarchy).
  `/tag/{slug}` now renders a real archive (previously a placeholder);
  `content/themes/default/archive.php`'s category-only description block
  was generalized to a plain `archive_description` value so both
  Categories and Tags share it.
- Categories (LP-010, first pass): a hierarchical taxonomy for Posts,
  backed by new `{prefix}categories` and `{prefix}post_categories` tables
  (migration `0007_create_categories_tables.sql`) and
  `LumoraPress\Services\CategoryService`. A post can be assigned any
  number of categories (a real many-to-many relationship, via a new
  "Categories" checklist in the Post editor) rather than just one.
  Categories support a basic, flat parent selector like Pages (a category
  can never be made its own parent or its own parent's parent), and
  deleting a category orphans its children (`parent_id` becomes `NULL`)
  instead of cascading the delete to them. The admin Categories screen
  (`admin/views/categories.php`) provides create/edit/delete with a
  per-category post count (one `LEFT JOIN ... GROUP BY` query, not one
  count per row). `/category/{slug}` now renders a real category archive
  (previously a placeholder) listing only posts directly assigned to that
  exact category — posts in child categories are not included — via a new
  `PostService::paginateByCategory()`; the category's description, if set,
  is shown above the list (`content/themes/default/archive.php`, new
  `.lp-archive__description` style). `PostService::delete()` now also
  removes that post's category assignments, so deleting a post doesn't
  leave orphaned rows in `post_categories`.
- Pages (LP-009, first pass): a new static-content type alongside Posts,
  backed by a new `{prefix}pages` table (migration
  `0006_create_pages_table.sql`) and `LumoraPress\Services\PageService`.
  Mirrors Posts closely: create/edit/delete, automatic slug generation with
  duplicate resolution, and draft/published/scheduled status (a scheduled
  page becomes visible automatically once its `published_at` time passes,
  same mechanism as Posts). Adds a basic, flat "Parent Page" selector
  (`parent_id`) so a page can be marked as a child of another — no tree
  view, drag-and-drop ordering, or hierarchical URLs yet, and a page can
  never be made its own parent or its own parent's parent. The admin Pages
  screen (`admin/views/pages.php`) provides the full list/create/edit/
  delete flow, reusing the existing Posts capabilities (`edit_posts`,
  `publish_posts`, `delete_posts`, `edit_others_posts`) since no dedicated
  page permissions exist yet. `/page/{slug}` now renders a real page via
  the default theme's `page.php` (previously a placeholder); a draft or
  not-yet-due scheduled page correctly 404s. `render_pagination()`
  (`include/helpers.php`) now accepts an optional `$label` for its
  `aria-label`, defaulting to the previous "Posts pagination" text, so the
  new Pages list can render its own "Pages pagination" landmark instead of
  a mislabeled one.
- Leftover installer directory warning (LP-030): the Dashboard now shows a
  prominent alert if `install/` is still present on disk — whether left
  over from a fresh install that couldn't delete itself, or restored by a
  manual ZIP update (LP-026's `install/` is one of the paths a package
  overlays back onto the installation).
- Manual updates via ZIP upload (LP-026): a new Updates admin page lets an
  Administrator upload an official Lumora Press release ZIP and apply it
  in place, without FTP/SSH access. The pipeline validates the archive
  (integrity, size caps, path-traversal protection, required-file
  structure, version number), runs compatibility checks (PHP version,
  required extensions, disk space, directory writability), and blocks
  downgrades unless explicitly confirmed. On confirmation it automatically
  backs up the core application files and the database (a pure-PDO SQL
  dump — no `mysqldump`/shell dependency) before overlaying the new
  version's files, running any new database migrations, and verifying the
  installed version matches. Any failure after the backup triggers an
  automatic rollback of both files and database. Every attempt is recorded
  in a new `{prefix}update_log` table (migration
  `0005_create_update_log_table.sql`), shown as a "Recent Updates" list on
  the Updates page. New service classes: `UpdatePackageValidator`,
  `UpdateBackupService`, `UpdateService` (`LumoraPress\Services`), and a
  small `UpdateStatus` enum. The Dashboard's "Update Status" widget now
  links to the new Updates page instead of a hardcoded placeholder.
- Version number display (LP-029): the Dashboard's System Information
  widget now shows the codename alongside the version number (e.g.
  "0.2.0 (Posts)"), and the admin sidebar's "Lumora Press" brand now
  shows a short version label underneath it, visible on every admin page
  rather than only the Dashboard.

- Thumbnail Generation (LP-001): new `LumoraPress\Services\ThumbnailService`
  derives resized copies of image uploads using GD only (no Imagick
  dependency). Three configurable sizes ship by default — small (150x150,
  crop), medium (300x300, fit), large (1024x1024, fit) — each independently
  adjustable (dimensions, crop-vs-fit mode, enabled/disabled) from a new
  "Thumbnails" section on the Settings screen, alongside JPEG/WebP quality,
  an optional fixed unsharp-style sharpen pass, and a maximum-source-pixels
  safeguard that skips (and logs) images too large to safely decode. EXIF
  orientation is corrected before resizing, upscaling is never allowed
  (a size smaller than the source is simply skipped), and WebP/AVIF output
  is only ever attempted when the running GD build actually supports it —
  skipped with a logged notice otherwise, never a hard failure. New
  `{prefix}media_thumbnails` table (migration
  `0013_create_media_thumbnails_table.sql`). The admin Media Manager now
  generates thumbnails automatically on upload, shows generated sizes with
  a per-item "Regenerate thumbnails" button on the edit screen, uses the
  small thumbnail (falling back to the original) in the file grid, and
  gained a "Bulk regenerate thumbnails" tool (with a "missing only" option)
  plus a "Clean up orphaned thumbnails" action — bulk regeneration
  processes in batches of 10 per request with a progress bar, the same
  "no queue/cron infrastructure" batch-per-request shape already used
  elsewhere in this codebase, progressively enhanced by a new
  `admin/assets/js/thumbnail-bulk.js` to auto-continue between batches
  (still fully usable, one click per batch, with JavaScript disabled).
  Deleting a file now cleans up its thumbnails alongside the original.
  Extensible via a new `thumbnail_sizes` filter (register custom sizes),
  `thumbnail_max_pixels` filter, and `thumbnail_generated`/
  `thumbnail_generation_failed` actions — a corrupt or unsupported source
  image is logged and skipped per-size rather than aborting the whole
  upload or a bulk run.
- Featured Images (LP-040): Posts and Pages can now have a designated
  featured image — Pages gained a `featured_image_id` column (migration
  `0014_add_featured_image_to_pages.sql`); Posts already had one from an
  earlier session but nothing used it until now. Both editors gained a
  "Featured Image" meta box (choose from an existing image via a
  `<select>`, or upload a new one — a real modal media picker is
  explicitly out of scope, no precedent for one exists in this codebase),
  and `MediaUsageChecker` now also checks Pages before allowing a
  referenced image to be deleted. A new Theme API
  (`include/media-functions.php`): `has_post_thumbnail()`,
  `post_thumbnail_url()`, `the_post_thumbnail()` (real `srcset`/`sizes`
  built from whichever LP-001 thumbnail sizes actually exist for that
  image, falling back to the original then a configurable default
  featured image then nothing), and `post_thumbnail_caption()` — backed
  by a new `LumoraPress\Core\Theme\FeaturedImages` static bridge (same
  "hold the live service" shape `ActiveTheme` uses, unlike `SiteBranding`'s
  snapshot-value shape, since featured images need a fresh per-post
  lookup every time). `get_header()` now accepts an optional vars array
  (`comments_template()`'s existing shape) so `header.php` can render
  Open Graph (`og:title`/`og:description`/`og:url`/`og:image`) and Twitter
  Card meta tags for the current single post/page — previously
  impossible, since `get_header()` had no way to see the calling
  template's `$post`/`$page`. The default theme now shows the featured
  image on single posts/pages and as a thumbnail in the homepage/archive
  post list. `FeedService` optionally includes a featured image as an
  RSS2 `<enclosure>` / Atom `<link rel="enclosure">`, gated by a new
  "Include featured images in feed items" setting (default on). Bulk
  assign/remove featured images is explicitly deferred (the ticket lists
  it as optional).
- FTP Media Import (LP-041): new `LumoraPress\Services\MediaImportService`
  registers media files that already exist on the server's filesystem
  (dropped there via FTP/SFTP/a hosting file manager) into the Media
  Manager, without a browser upload round-trip. Administrators configure
  one or more allowed absolute server directories on the Settings page;
  scanning/importing is only ever permitted inside a `realpath()`-resolved
  descendant of one of those directories, re-validated immediately before
  every filesystem operation (never trusting a path round-tripped through
  the preview form) — the same "prevent directory traversal" posture
  `ThemeInstaller` already established for ZIP entries, adapted for real
  paths. A new "Import from Server" screen (`admin/views/media.php?action=import`,
  reusing the existing action-based dispatch rather than adding a new
  top-level admin page) lets you pick a directory, scan it (recursively
  or not), preview the files found (already-imported duplicates flagged
  and unchecked by default via the existing SHA-256 `file_hash` column —
  the same mechanism doubles as "detect moved or renamed files", since a
  rename doesn't change the hash), pick a destination folder or mirror
  the scanned directory structure into new folders automatically, and
  optionally use each file's modification time as its stored upload date.
  Import reuses `MediaService`'s allow-list/filename-sanitizing (now
  exposed as public `isAllowedExtension()`/`isAllowedMimeType()`/
  `sanitizeFilename()` methods, rather than being duplicated) and
  `ThumbnailService::generate()`, so imported images get thumbnails the
  same as an upload. Large imports process in batches of 10 with a
  progress bar, the same batch-per-request/redirect-loop/auto-continuing-
  JavaScript pattern LP-001 built for bulk thumbnail regeneration (no
  queue/cron infrastructure exists in this codebase) — an interrupted run
  can simply be restarted, since duplicate detection makes it naturally
  skip everything already imported. Bulk assign/remove of categories/tags
  during import and CLI/API support are out of scope: media has no
  category/tag fields in this app, and this codebase has no CLI
  entrypoint to build the latter on (both were listed as optional/not
  applicable in the ticket).
- Media Viewer & Lightbox (LP-031): a PhotoSwipe 5 lightbox for images,
  loaded from the jsDelivr CDN at a pinned version (5.4.4) rather than
  vendored locally, per explicit decision. `SearchResult` gained its own
  `featuredImageId` (mirroring Post/Page from LP-040) specifically so
  search results are a real navigable gallery rather than an empty one.
  Every list-view page — homepage, archive, category, tag, search
  results — groups its post thumbnails into one lightbox with prev/next
  navigation across that page's items (clicking a thumbnail now opens the
  lightbox instead of navigating to the post; the post title link still
  does that); a single post/page's featured image is a lightbox "gallery"
  of one. New theme API: `the_post_thumbnail_lightbox()`
  (`include/media-functions.php`) wraps `the_post_thumbnail()`'s existing
  output in an anchor carrying the `data-pswp-*` attributes PhotoSwipe
  needs, and shows a caption (from the media's `caption`/`alt_text`)
  via a small custom PhotoSwipe UI element — no extra plugin needed. A
  new `LumoraPress\Core\Theme\MediaViewer` static flag (same "bridge"
  shape as `FeaturedImages`/`ActiveTheme`) tracks whether the current
  request actually rendered a lightbox-wrapped image, so the PhotoSwipe
  `<script>`/`<link>` tags are only emitted on pages that need them — the
  ticket's own "load viewer JavaScript only on pages containing media"
  requirement. The admin Media Manager's edit/preview page gained the
  same lightbox treatment for its image preview, plus native inline
  MP4/WebM video, MP3/OGG/WAV/M4A audio, and PDF viewers (all via plain
  HTML5 `<video>`/`<audio>`/`<iframe>`, no extra JavaScript) — the grid
  view itself keeps its existing thumbnail → edit-page click target
  rather than gaining a competing lightbox click target. Album/Favorites/
  Most-viewed/Custom-collections navigation from the ticket's own wording
  are explicitly out of scope: none of those concepts exist anywhere in
  Lumora Press (that's Lumora Gallery's domain, and CLAUDE.md requires
  Lumora Press stay independent of it).
- REST API (LP-021): a new, versioned JSON REST API under `/api/v1/...`
  (`LumoraPress\Controllers\ApiController`) covering Posts, Pages,
  Categories, Tags, Comments, and Search — reads are always public and
  only ever return published/approved content; writes require a new
  bearer-token authentication scheme (`Authorization: Bearer
  {selector}:{validator}`, `LumoraPress\Core\Security\ApiTokenService`,
  structurally mirroring `RememberMeService`'s selector/validator/SHA-256
  shape but non-rotating, since an API token needs to stay stable across
  many requests rather than being single-use) and repeat the exact same
  capability + ownership checks the admin UI already enforces (e.g. a
  Contributor's post always saves as a draft via the API too, exactly
  like the admin editor). Tokens are named, revocable, and self-managed
  by every authenticated role (including Subscriber) on a new "API
  Tokens" admin page. Every response goes through a new `ApiResponse`
  helper for a consistent `{"data": ...}` / `{"data": [...], "meta":
  {...}}` / `{"error": {"message": ..., "code": ...}}` envelope, with
  real pagination (`meta.page`/`perPage`/`total`/`totalPages`, built from
  each service's existing `paginate*()` methods — a new
  `PostService::paginateByAuthor()` was the only new query needed) and
  filtering (`?category=`/`?tag=`/`?author=` on Posts). Authenticated
  reads of your own drafts and API rate limiting are explicitly out of
  scope for this pass (see LP-039 below for the latter).
- REST API Access Controls (LP-039 retargeted — see `DECISIONS.md`: this
  ticket used to be "XML-RPC API Controls", but Lumora Press has no
  XML-RPC endpoint and isn't getting one, so it was pivoted to apply the
  same access-control spirit to the REST API LP-021 just added): a new
  "REST API" Settings section with a global enable/disable toggle
  (default **on** — a first-party feature this project is actively
  building, unlike legacy XML-RPC's "off by default" advice), independent
  per-resource toggles (posts/pages/categories/tags/comments/search), and
  a dedicated "allow public comment submission via the API" toggle (the
  closest analog to XML-RPC's "remote publishing" concern, since
  anonymous comment POSTs are the only API write reachable with no
  token). Disabled resources return a clean JSON 403
  (`api_disabled`/`resource_disabled`/`public_submission_disabled`) via
  the same `ApiResponse` envelope, never an HTML page or PHP error, and
  every request (blocked or not) fires a new `rest_api_request` action;
  `rest_api_enabled`/`rest_api_resource_enabled` filters let a plugin
  override either toggle programmatically. Blocked requests are logged
  via a single `error_log()` line, the same ephemeral-logging choice
  LP-001/LP-041 already made rather than a new database table.

### Changed

- Reorganized Admin Settings and Navigation (LP-042, LP-043): the admin
  sidebar now supports two-level menus. **Settings** is a new parent menu
  with General, Media, Cache, Maintenance Mode, and Security sub-pages —
  Feeds/Search/REST API moved to Settings &rsaquo; General, Thumbnails/Media
  Import moved to Settings &rsaquo; Media, and Maintenance Mode (LP-033) moved
  to its own sub-page, all off the old single `admin/views/settings.php`
  with the same option keys and CSRF action names (no data migration
  needed). **Maintenance** is a new parent menu grouping Updates alongside
  new Import/Export/Tools/System Information/Logs entries (most still
  placeholders — see `TODO.md`). Every admin page now shows breadcrumbs,
  and a "View Site" link sits under the version number in the sidebar.
  Old bookmarks to `/admin/settings`, `/admin/updates`, and `/admin/tools`
  redirect to their new locations. Cache and Security are new Settings
  sub-pages but are mostly stubs today — no site-wide caching engine or
  configurable security hardening exists yet in Lumora Press; see
  `TODO.md`'s LP-042 section for exactly what's real versus deferred. The
  Settings/Maintenance parent menu items are now collapsible: a chevron
  toggle button (`admin/assets/js/nav-toggle.js`, progressively enhanced —
  each section still expands on its own when active with no JavaScript)
  expands or collapses that section's sub-items independently of which
  page is active, with `aria-expanded` kept in sync and the open/closed
  choice remembered across page loads via `localStorage`.

### Fixed

- Auto-linked URLs in comment content (LP-012) with more than one query
  parameter rendered a broken link: `format_comment_content()` (see
  `include/helpers.php`) escapes the raw comment text first, then
  regex-matches URLs within the already-escaped text — but the matched
  text was being passed through `esc_url()` a second time when building
  the `href` attribute, which re-encoded the literal `&amp;` already
  present in the escaped text into `&amp;amp;`, corrupting the URL for
  any browser navigating it. Fixed by using the already-escaped matched
  text directly for both the `href` and the visible link text, with no
  second escaping pass. Caught while manually browser-testing the
  Comments feature with a URL containing two query parameters; a new
  `Unit/Core/HelpersTest.php` now covers this specifically.
- Saving or editing a Page (LP-009) threw "An unexpected error occurred"
  on a real MySQL/MariaDB server: `PageService::listAllForParentSelect()`
  used the same named placeholder (`:id`) twice in one query. Real
  (non-emulated) prepared statements — `Database::connect()` sets
  `PDO::ATTR_EMULATE_PREPARES => false` — don't allow a named placeholder
  to repeat within a single query; MySQL's native prepare protocol throws
  `SQLSTATE[HY093]: Invalid parameter number` at execute() time. SQLite,
  which the unit test suite runs against, doesn't have this restriction,
  so `PageServiceTest` passed while the same query broke immediately on a
  live site. Fixed by using two distinct placeholders bound to the same
  value. A new `Integration/PageServiceIntegrationTest.php` (runs only
  against a real MySQL/MariaDB server) now covers this specifically, since
  no SQLite-backed unit test can catch this class of bug.
- Manual ZIP updates (LP-026) never cleaned up the `install/` directory:
  `install/` is one of the core paths a release package overlays onto the
  installation, so an update that shipped it would resurrect it even on a
  site where the administrator had already deleted it after their
  original install — silently reopening exactly the surface LP-030's
  Dashboard alert warns about. `UpdateService::install()` now removes
  `install/` (best-effort, via the same `InstallerCleanup` the installer
  itself uses) immediately after a successful update.

## [0.3.0] — 2026-07-25 — "Admin"

### Added

- Markdown Editor (LP-015) and WYSIWYG Editor (LP-016): posts and pages
  gained a real content-authoring pipeline in place of the old plain
  `<textarea>` — an "Editor" dropdown (Markdown / Visual-HTML / Plain
  text, backed by a new `content_format` column, migration `0016`) now
  swaps in either EasyMDE (Markdown) or TinyMCE (Visual/HTML), both
  self-hosted via jsDelivr at a pinned version rather than bundled
  (matching the existing PhotoSwipe precedent), with a shared media
  picker and upload button, live word/character/reading-time stats, and
  autosave/crash-recovery built into each library. Switching between
  Markdown and Visual/HTML round-trips the current content through a
  best-effort converter and asks for confirmation first, rather than
  silently mutating it. Behind both editors sits a new, dependency-free
  Markdown-to-HTML parser and an allowlist HTML sanitizer
  (`LumoraPress\Core\Content\{MarkdownParser,HtmlSanitizer,
  HtmlToMarkdownConverter}`) — the single XSS boundary for every format,
  wired through a new `ContentRenderer` service that replaces the old
  `nl2br(esc_html($content))` render path everywhere content is shown
  (single/page templates, RSS/Atom feeds, search excerpts, the REST
  API's new `content_format`/`content_html` fields). Existing posts and
  pages keep rendering exactly as before (`content_format` defaults to
  `plain` for rows that predate this column); new content defaults to
  Markdown. The site's Content-Security-Policy gained a `csp_directives`
  filter registration allowing jsDelivr for `script-src`/`style-src`/
  `font-src` — without it, EasyMDE/TinyMCE (and the pre-existing
  PhotoSwipe lightbox) fail to load silently, and EasyMDE's toolbar
  icons — Font Awesome 4 glyphs, loaded from jsDelivr alongside EasyMDE
  itself rather than via its own less predictable auto-download — render
  blank without the actual font file; both are easy-to-miss bugs this
  surfaced and fixed along the way. Supported Markdown: headings, bold/italic/
  strikethrough, inline code, fenced code blocks, GFM tables,
  blockquotes, horizontal rules, ordered/unordered/task lists, links,
  images, footnotes, and a `[[toc]]` table-of-contents marker.

## [0.2.0] — 2026-07-21 — "Posts"

### Added

- Website URL detection during installation (LP-028):
  `LumoraPress\Core\Http\SiteUrl` detects the site's absolute URL (scheme +
  host + install path) from the installer's own request, the same way
  `BasePath` already detects the install's subdirectory. Installer step 2
  now shows an editable "Website URL" field pre-filled with the detected
  value (validated as a URL on submit) and stores it as a DB-backed
  `site_url` option — unlike `base_path` (fixed file config), the site's
  public URL is editable later without re-running the installer. A new
  `home_url()` helper (mirroring `site_url()`/`admin_url()`) exposes it to
  core, themes, and plugins as an absolute URL rather than a root-relative
  one; installs made before this option existed fall back to live
  detection automatically. Nothing consumes it yet (no RSS/sitemaps/emails
  exist), but it's the foundation those will need.
- Posts (Phase 2): the first real content type, backed by a new `{prefix}posts`
  table (migration `0004_create_posts_table.sql`) and
  `LumoraPress\Services\PostService`. Supports create/edit/delete, automatic
  slug generation from the title with numeric-suffix duplicate resolution
  (`hello-world`, `hello-world-2`, …), and draft/published/scheduled status.
  A scheduled post becomes publicly visible automatically once its
  `published_at` time passes — the visibility query checks
  `status = 'published' OR (status = 'scheduled' AND published_at <= NOW())`
  directly, so nothing needs to run a background job to flip its status.
  The admin Posts screen (`admin/views/posts.php`) provides the full list/
  create/edit/delete flow with pagination and a status filter, and respects
  each role's capabilities: a Contributor can only ever save drafts, and an
  Author/Contributor without `edit_others_posts` can only touch their own
  posts. The dashboard's previously-disabled "Quick Draft" widget now saves
  a real draft, and its "Recent Posts" widget lists real posts. The
  homepage and the `/archive` route now list published posts with
  pagination via a new shared `render_pagination()` helper; `/post/{slug}`
  renders the full post. `admin/index.php` now wraps its page dispatch in
  an output buffer so that a page view required after
  `views/layout-header.php` (like `views/posts.php`) can still call
  `header()` for its own process-POST-then-redirect and per-record
  authorization handling.

## [0.1.0] — 2026-07-21 — "Foundation"

### Added

- Initial application foundation: directory structure, PDO database service
  with migrations, configuration service, session-based authentication with
  CSRF protection, user roles, the admin dashboard framework, the plugin
  hook system (`add_action`/`do_action`/`add_filter`/`apply_filters`), the
  widget and navigation menu systems, a basic media library, and a
  two-step installer.
- Default theme (`content/themes/default`): classic template set
  (`header.php`, `footer.php`, `sidebar.php`, `index.php`, `single.php`,
  `page.php`, `archive.php`, `search.php`, `404.php`, `functions.php`) with
  a responsive, accessible `style.css` supporting light and dark mode, a
  primary widget area, and primary/footer/social/secondary nav menu
  locations.
- Login attempt throttling (Phase 1.17): `LumoraPress\Core\Security\LoginThrottle`
  tracks failed admin logins by IP address, persisted in a new
  `{prefix}login_attempts` table (migration `0002_create_login_attempts_table.sql`)
  rather than the session, since an attacker won't necessarily carry
  cookies across requests. After 5 failed attempts from the same IP within
  15 minutes, further attempts are blocked for 15 minutes with a friendly
  "try again in N minutes" message. Successful logins clear that IP's
  history. The throttle check fails open (never blocks a login) if its
  underlying table can't be read — see `LoginThrottle`'s docblock for the
  tradeoff. Existing installs pick up the new migration automatically the
  next time any admin page (including the login page) is visited, without
  needing to re-run the installer.
- "Remember Me" persistent login (Phase 1.7): a new "Remember Me" checkbox
  on the admin login form issues a long-lived (30-day) cookie via
  `LumoraPress\Core\Security\RememberMeService`, backed by a new
  `{prefix}remember_tokens` table (migration
  `0003_create_remember_tokens_table.sql`). Uses the selector/validator
  pattern: only a hash of the token is stored, comparisons are
  timing-safe, and every token is single-use — a successful auto-login
  immediately rotates to a fresh token, and a validator that doesn't match
  its selector revokes every remember-me token for that user as a
  precaution against a stolen cookie being replayed. Logging out clears
  the cookie and its underlying token on this device.
- Content Security Policy support (Phase 1.17): `LumoraPress\Core\Security\ContentSecurityPolicy`
  sends a strict, same-origin-only `Content-Security-Policy` header on
  every response — the front end, the admin area, and the installer.
  Nothing shipped in core needed loosening for this (no inline scripts,
  inline styles, or third-party origins anywhere in the codebase).
  Themes and plugins can loosen individual directives via the new
  `csp_directives` filter; site owners can turn the header off entirely
  via the new `csp_enabled` config option (e.g. if a reverse proxy already
  sets its own policy).
- Automatic installer removal (LP-004): once a fresh install completes
  successfully, `install/` is deleted automatically via the new
  `LumoraPress\Core\InstallerCleanup`, reducing the chance of leaving the
  installer accessible after deployment. If deletion isn't possible (e.g.
  restrictive shared-hosting permissions), the success page tells the
  administrator to remove the directory by hand instead — installation
  itself has already fully completed by the time cleanup is attempted, so
  a cleanup failure never blocks it.
- Installer system requirements check: before attempting anything else,
  the installer now checks the PHP version, the `pdo`, `pdo_mysql`,
  `session`, and `json` extensions, and that `config/`, `storage/logs/`,
  `storage/sessions/`, `storage/cache/`, and `content/uploads/` are
  writable, via the new `LumoraPress\Core\RequirementsCheck`. If anything
  is missing, a clear page lists exactly what needs fixing instead of a
  generic "Installation failed: ..." message appearing partway through
  the wizard.

### Fixed

- Subdirectory installs (e.g. `https://example.com/lumorapress/` rather than
  the domain root): the installer's step 1 redirect and every hardcoded
  `/install/`, `/admin/`, and `/admin/assets/css/admin.css` reference in
  `install/views/*.php` previously assumed the app lived at the domain root,
  sending subdirectory installs to a 404 partway through. The installer now
  derives its own URL from the request instead of assuming it, and the root
  `index.php`'s pre-install redirect to `install/` does the same. The
  install-time location is also persisted to `config/config.php` as
  `base_path`, and the `site_url()`/`admin_url()` helpers (used throughout
  the admin area, the default theme, and RSS output) now read it via a new
  `LumoraPress\Core\Http\BasePath` bridge instead of hardcoding a leading
  `/`, so every internal link continues to resolve correctly after
  installing to a subdirectory.
- Root `.htaccess`: the rewrite bypass for `install/` and `content/` matched
  against `%{REQUEST_URI}` with a pattern anchored to the domain root
  (`^/(admin|install|content)`), which never matched under a subdirectory
  install (`%{REQUEST_URI}` is always the full path, e.g.
  `/lumorapress/install/`) — every request to the installer was silently
  rewritten to the front controller instead, which then redirected back to
  the same broken path, producing an infinite redirect loop on step 1.
  Replaced with a match against `%{REQUEST_FILENAME}` (an absolute
  filesystem path, already location-independent) for a `/install/` or
  `/content/` path segment. `admin` was also removed from the bypass
  entirely — `admin/.htaccess` already denies direct requests to
  `admin/index.php` as defense in depth, since it's only meant to be
  reached through the front controller's router, so bypassing the rewrite
  for it would have made the admin area unreachable (403) the moment
  someone got past installation.
- Subdirectory installs (continued): the previous subdirectory-install
  fixes above only addressed *outgoing* URLs (`site_url()`/`admin_url()`
  building correct links). The incoming side was still broken: `index.php`
  passed the raw `$_SERVER['REQUEST_URI']` (which always carries the
  install's subdirectory prefix, e.g. `/lumorapress/admin/`) straight into
  `Router::dispatch()`, but every route pattern in `include/bootstrap.php`
  is registered without that prefix (`/`, `/admin`, `/post/{slug}`, …) — so
  on any subdirectory install, every single route failed to match and
  every request, including the homepage, returned the application's own
  404. This went unnoticed through all of Phase 1 because a domain-root
  install has an empty prefix and nothing to strip; it surfaced via a real
  subdirectory install returning "Page Not Found" for both `/admin/` and
  the site root itself. Fixed by adding `BasePath::stripFrom(string $uri):
  string` and calling it in `index.php` before dispatch; see
  `DECISIONS.md`'s "Subdirectory installs never matched a route" for the
  full root-cause writeup.
- Subdirectory installs (continued): the admin stylesheet
  (`admin/assets/css/admin.css`) was still hardcoded as a domain-root
  path (`href="/admin/assets/css/admin.css"`) in `admin/views/login.php`,
  `forbidden.php`, and `layout-header.php`, 404ing on a subdirectory
  install (rendered, but completely unstyled) — missed by the earlier
  `site_url()`/`admin_url()` fix above since a static asset `<link>` isn't
  a page link. Added a new `admin_asset_url()` helper and used it in all
  three views.

### Removed

- The empty `public/` directory. Its `index.php` was a dead redirect stub
  left over from before the document-root architecture decision (see
  `DECISIONS.md`), and no static asset ever moved into `public/assets/`.
  Nothing referenced a `/public/...` URL. Also corrected two stale
  comments (`app/Core/Kernel.php`, `admin/.htaccess`) that still described
  `public/index.php` as the application's entry point, and removed a
  leftover `public` entry from the root `.htaccess`'s rewrite-bypass
  pattern.
- `admin/.htaccess` entirely, matching how this developer's other
  applications protect their own admin folders (no per-directory Apache
  config at all). It was also the likely actual cause, all along, of a
  persistent 403 a live subdirectory install kept hitting on bare
  `/admin/` requests (see `cPGuard-403-support-ticket.md`) — disabling it
  reliably made the 403 go away in every test performed. Every file in
  `admin/views/` now carries the same `isset($kernel)` direct-access
  guard `admin/index.php` already had (previously only Apache's `Require
  all denied` stopped a direct request to e.g. `admin/views/dashboard.php`
  from running with no `$kernel` in scope), and `admin/assets/*.css` is
  now just served as a normal static file. See `DECISIONS.md`'s "admin/
  has no .htaccess of its own" for the full writeup.

### Changed

- Installer (LP-002): the database table prefix field is now pre-filled with
  a cryptographically random suggestion (e.g. `lum_a8f3d1_`) instead of the
  predictable `lp_` default, while still allowing manual override.
- `Database` (LP-007): replaced the public constructor with two named
  constructors — `Database::connect(...)` for a real MySQL/MariaDB
  connection (used everywhere in the running application) and
  `Database::fromPdo(PDO $pdo)` for wrapping an already-open PDO connection.
  This is a testability-motivated refactor with no behavior change for the
  running application; every call site (`include/bootstrap.php`,
  `install/index.php`) was updated in the same change.
- `PluginManager` and `ThemeRenderer` no longer take an unused `HookManager`
  constructor dependency (flagged by PHPStan's first-ever run against this
  codebase this session — see `PHP Test Suite/TEST_LOG.md`). Plugins and
  themes still register hooks the same way, via the procedural
  `add_action()`/`add_filter()` bridge, not through these classes directly.
