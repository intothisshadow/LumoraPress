# Changelog

All notable changes to Lumora Press are documented in this file.

## [Unreleased]

### Added

- Post/Page editors: the "Insert Image" media picker now has a search
  box and a Folder filter, both narrowing the same grid together
  (LP-115). Images load a page at a time (40 per page, "Load More" to
  fetch the next) instead of the whole Media Library loading up front.
  The dialog is also bigger (900px vs. the old 640px) with a fixed
  search/folder/upload header above an independently scrolling grid,
  and gained its own "Upload New" control. TinyMCE's and EasyMDE's
  separate native "Insert/Edit Image" toolbar buttons are gone —
  "Insert Image" (the renamed Media Manager picker) is now each
  editor's single entry point for inserting an image.
- New Post's Categories checklist and New Page's Parent Page field now
  render nested by depth in a scrollable, height-capped list instead of
  a flat unbounded one (LP-105) — matching Appearance &rsaquo; Menus'
  own "Add Items" panel. Several more admin category/parent `<select>`
  pickers (Categories' own "Parent Category" field, Settings &rsaquo;
  Reading's two homepage page pickers, All Pages' parent filter/Quick
  Edit/bulk-move, and All Posts' category filter/bulk-add) now indent
  by depth the same way (LP-106).
- Admin sidebar: Pages now sits directly after Posts (LP-111), matching
  Posts and Pages' status as the two primary content types, ahead of
  Media Manager/Comments/etc.
- All Posts/All Pages: a "View" row action for Published rows (LP-116),
  linking straight to the post/page's live permalink in a new tab —
  Draft/Pending Review/Scheduled rows keep using the editor's own
  Preview link instead, since they have no live permalink yet.
- Text widget is now a Text/HTML widget (LP-112): its Content field is a
  lightweight WYSIWYG editor (bold/italic/underline/strikethrough, lists,
  blockquote, link, alignment, and a Source Code view), and the widget now
  renders real formatted HTML instead of escaped plain text with line
  breaks. Submitted markup is routed through the same sanitizer every
  post/page's HTML-format content already goes through before it's
  rendered publicly.
- New theme template tags: `post_categories()`/`the_post_categories()`
  (the Categories a post belongs to, rendered as a linked list) and
  `edit_post_link()`/`edit_page_link()` (an admin edit-screen link,
  shown only to a signed-in visitor actually allowed to edit that
  specific post/page — the same permission check the admin list
  screens already use).
- Privacy Policy Page: a new Settings &rsaquo; Privacy admin screen lets
  an administrator name an existing Page as the site's privacy policy.
  A new `privacy_policy_url()` template tag resolves it for themes —
  whether and where to link it (footer, comment form notice, etc.) is
  left entirely up to the theme.
- WordPress Importer: Homepage, Reading, Discussion, Media, and Privacy
  settings write-back (LPP-004 Stage 1, completing the Site Settings
  group started earlier): "Your homepage displays," blog pagination
  size, search engine visibility, every comment moderation/threading/
  notification/avatar setting, thumbnail/medium/large image dimensions,
  and the privacy policy page can now all be imported alongside the
  site title/tagline/timezone/permalink structure already covered,
  under the same opt-in "Site settings" checkbox on Maintenance &rsaquo;
  Import. A source homepage or privacy policy page that wasn't actually
  imported is skipped with a warning rather than pointing at content
  that doesn't exist locally.
- WordPress Importer: internal link rewriting between imported posts
  and pages (LPP-004 URL & Link Migration): a plain in-content
  `<a href="...">` carries no structured reference to what it points at
  the way a menu item does, so a new always-on stage tries three
  independent, permalink-structure-agnostic ways to identify the
  target — a `?p=123`/`?page_id=123` query parameter, an exact match
  against the post/page's own `guid`, or the link's last path segment
  matched against a slug — always scoped to the source site's own
  domain, so an unrelated external link sharing a slug or query param
  is never touched. Category/tag/author archive links are a deliberate
  scope boundary and are left as-is.
- WordPress Importer: post-import thumbnail regeneration and content
  verification (LPP-004 Post-Import): two finalization stages now
  always run at the end of every import. Imported media only ever had
  its metadata recorded, not its thumbnail size variants (unlike a
  normal admin upload) — those are now regenerated automatically. A
  verification pass then confirms every row the batch created still
  resolves, and that a post/page's own featured image still resolves to
  a real Media item, adding any problem found to the same warnings list
  every other stage already reports through.
- WordPress Importer: dry run, resumable imports, live progress, and a
  stage delay (LPP-004 Import Options): a new "Preview (Dry Run)" form
  reports approximate per-content-type counts from the source database
  without importing anything. A real import now persists its progress
  to the database after every single stage (Site Settings, Users,
  Categories & Tags, Media, Downloads, Pages, Posts, Comments, Menus,
  Widgets) rather than only at the end — if the process running it is
  ever interrupted (a host's execution time limit, a lost connection),
  revisiting Maintenance &rsaquo; Import offers to **Resume Import**
  from exactly where it stopped (the original run's own content-type
  selection is reused automatically) or **Discard This Import**
  instead. A live per-stage progress checklist shows what's currently
  running while a Start/Resume Import request is in flight, reusing the
  same polling mechanism Maintenance &rsaquo; Updates already has — no
  new JS or CSS needed. An optional delay-between-stages field is meant
  for a live production source, to avoid hammering a shared-hosting
  site's database and web server back-to-back for the whole import.
- WordPress Importer: HTML-aware image reference rewriting (LPP-004 URL
  & Link Migration): the original plain string-replace over each
  attachment's exact full-size guid URL is replaced with a real HTML
  parse (`ContentImageRewriter`) that resolves both the full-size path
  and any WordPress-generated derivative filename (`cover-300x200.jpg`
  — previously left pointing at the old site) back to the same
  imported attachment. `srcset`/`sizes` and WordPress-only classes
  (`wp-image-123`, `size-large`) are stripped; alignment classes are
  kept as-is, since the default theme's own editor already uses the
  same class names; `alt`/`width`/`height`/`title` are left untouched;
  `<figure>`/`<figcaption>` wrappers and an image wrapped in
  `<a href="...">` are handled correctly instead of only rewriting a
  bare `<img>`. A WordPress `[gallery]` shortcode or gallery block has
  no single `<img src>` to resolve and is flagged in the import
  warnings per affected post/page instead of silently left broken.
- WordPress Importer: Site Settings write-back (LPP-004 Stage 1): the
  source site's title, tagline, timezone, date/time format, and
  permalink structure can now optionally be imported (its own
  checkbox on the Start Import form, off by default, since this is the
  one import step that overwrites the target site's own existing
  settings rather than adding new content alongside it). Test
  Connection now shows a read-only preview of the source's values
  first. A numeric UTC-offset timezone (rather than a named one) is
  translated to the closest whole-hour zone; a source permalink
  structure using a tag Lumora Press doesn't support (e.g. WordPress's
  own `%post_id%`) is left untouched and flagged in the import
  warnings instead of leaving broken literal text in every URL. Remove
  All Imported Content restores the exact pre-import settings it
  snapshotted before the import touched them, the same pattern Stage
  8's menu/widget rollback already uses. Homepage, Reading, Discussion,
  Media, and Privacy settings are still not imported — Lumora Press has
  no config key for any of them yet.
- WordPress Importer: Menus & Classic Widgets (LPP-004 Stage 8): each
  WordPress nav menu now becomes a named, reusable Lumora Press menu —
  custom links and links to already-imported pages/posts/categories/
  tags, resolved to their real permalink and preserving parent/child
  nesting. A menu item pointing at content that wasn't imported is
  skipped with a warning rather than creating a broken link. Menus are
  never auto-assigned to a theme location, since WordPress doesn't
  store a location's human-readable name in its database, only an
  opaque per-theme slug — assign each imported menu from Appearance
  &rsaquo; Menus &rsaquo; Manage Locations once the import finishes.
  Classic widgets import too, but only types with a direct Lumora Press
  equivalent (Text, Custom HTML, Search, Pages, Categories, Recent
  Posts, Recent Comments, Archives, Tag Cloud, Meta) — every other
  widget type (WordPress's own Navigation Menu widget, and any
  plugin-provided widget type) is skipped, aggregated into one warning
  per type. A widget's own sidebar is matched to a Lumora Press widget
  area by a small id-based heuristic; anything that doesn't confidently
  match — including WordPress's own inactive-widgets bucket — lands in
  Inactive Widgets instead of being dropped. Remove All Imported
  Content now restores the exact pre-import menus/widgets configuration
  it snapshotted before the import touched them.
- Downloads &rsaquo; All Downloads redesign + Shortcodes docs (LPP-009):
  the admin All Downloads screen is now a real list table — ID,
  Category, Type, Status, and Date columns, a category filter,
  sortable column headers, status-count tabs, bulk actions, and
  pagination — replacing the original grouped-by-category `<details>`
  layout. Downloads can now be moved to Trash and restored (a new
  `trashed_at` column, mirroring Posts/Pages) rather than only deleted
  outright; Trash empties via a Delete Permanently action. A new
  Duplicate row action clones a download (sharing its underlying Media
  item/Redirect rather than copying it — permanently deleting one copy
  no longer breaks the other's link, since the underlying file/redirect
  is only removed once nothing references it). Editing a download now
  has its own screen (Downloads &rsaquo; Add New doubles as the editor
  via `?id=`, the same convention Posts/Pages already use) instead of
  an inline expand-in-place form. New Downloads &rsaquo; Shortcodes
  admin page documents `[lumora_downloads]`'s `category`/`category_id`/
  `show_size` attributes with real examples drawn from the site's own
  categories.
- Downloads plugin (LPP-008): a new bundled, inactive-by-default plugin
  (its own top-level Downloads admin menu entry, once activated from
  Plugins) to manage downloadable files and links directly — add a new
  download (an uploaded file or an external URL), give it a title,
  description, and category, and see every download grouped by category
  in one place. A download doesn't reimplement file storage or URL
  redirection: a file-type download is a normal Media Manager item and
  a url-type download is a normal Redirect (keeping its own hit
  counting), with a new `downloads` table adding the title/description/
  category identity neither had on its own. If the WordPress Importer
  plugin (LPP-004/LPP-007) is also active, a download added here
  automatically shows up in its `[sdm_show_dl_from_category]` shortcode
  output too, with no changes to that plugin — the category a download
  is filed under is passed straight through to the underlying Media/
  Redirect row, the same way an imported download already worked.
  A migrated WordPress download now also gets a real `downloads` table
  row of its own, so it appears on this plugin's own admin screen too,
  not just Media Manager/Settings > Redirects. Show downloads anywhere
  with the new `[lumora_downloads category="..."]` (or
  `category_id="..."`) shortcode this plugin registers — a fresh
  shortcode of its own, distinct from `[sdm_show_dl_from_category]`.
- WordPress Importer plugin (LPP-004), first pass: a new bundled,
  inactive-by-default plugin (Maintenance &rsaquo; Import, once activated
  from Plugins) migrates an existing WordPress site into Lumora Press via
  a direct database connection (host/port/database/username/password/
  table prefix — the site's own live server or a locally restored backup
  copy, either way) plus a local copy of its `wp-content/uploads` folder.
  Imports users (with WordPress role mapped to the closest Lumora Press
  role), categories (preserving parent/child hierarchy) and tags, media
  attachments, pages (preserving parent/child hierarchy), posts (with
  their categories/tags/featured image), and comments on posts
  (preserving threading) — each content type independently toggleable.
  Slugs, publish dates, and (for users/comments/media) their original
  WordPress timestamps are preserved rather than stamped "now". Media
  is copied into the same year/month (or other custom) folder it lived
  in on the source site, rather than every imported file landing in
  today's single upload folder. A Remove All Imported Content action
  rolls back exactly what one import created, the same provenance-tracked
  pattern the Dummy Content plugin (LPP-005) already uses; a "Test
  Connection" check runs before the real import.

  Deferred to a later pass (see `TODO-PLUGINS.md`'s `LPP-004` entry for
  the full list): the WXR `.xml` upload path, site settings/menus/
  widgets migration, dry-run preview, resuming an interrupted import,
  comments on imported pages, and full URL/link rewriting beyond a
  best-effort rewrite of imported media's own URLs in post/page content.
  Built on the same shared import layer LPP-005 introduced
  (`ContentImportRegistry` plus `PostImporter`/`PageImporter`/
  `UserImporter`/`MediaImporter`/`CommentImporter`), which gained three
  small additive changes to preserve source timestamps: `UserService::create()`,
  `CommentService::create()`, and `MediaService::registerExistingFile()`
  each accept a new optional trailing date parameter (defaulting to "now",
  matching every existing caller's behavior unchanged).

  Also imports the Simple Download Monitor plugin's own downloads, if
  the source site has it installed — a download whose file already lives
  on the source site becomes a real Media Manager item, organized into a
  Folder matching Simple Download Monitor's own category (preserving
  parent/child hierarchy); a download that only links to an external URL
  (e.g. a GitHub release — common when a host restricts `.zip` uploads)
  becomes a Redirect instead, giving it a stable local URL and a real
  hit counter without hosting the file. A page/post still containing the
  `[sdm_show_dl_from_category]` shortcode is flagged in the import
  warnings; see LPP-007 below for what actually renders it now. Each
  download's real historical count is preserved too — Simple Download
  Monitor's own `sdm_count_offset` plus its download-event log, added
  together, seeded onto the migrated Media item or Redirect instead of
  every download silently restarting at 0. If the Downloads plugin
  (LPP-008) is also active, each migrated download also gets a row on
  that plugin's own admin screen, not just Media Manager/Settings >
  Redirects.
- Downloads listing display (LPP-007): the WordPress Importer plugin
  now renders the exact `[sdm_show_dl_from_category category_slug="..."
  show_size="1"]` shortcode a migrated page/post still contains,
  instead of leaving it as inert text — a real, styled list of every
  download in the matching Media Manager Folder, mixing locally-hosted
  files and external-link Redirects in one list, sorted by name. No
  manual page editing needed for content already imported by LPP-004.
  `Redirect`s gained an optional `folder_id` (nullable, additive
  migration) so an external-link download can be grouped the same way a
  real Media item already was. New `.lp-downloads-list` styling added
  to both the default theme and the `duskline` custom theme (per this
  project's Public-Facing CSS Rule and Custom Theme Rules). A category
  is matched by slugifying its Folder's name against the shortcode's
  `category_slug` attribute, since Folders have no slug column of their
  own — works for every category on this project's real source site,
  but not guaranteed if a WordPress category's slug was hand-edited away
  from its name.
- Font Awesome diagnostics (LPP-002): the Appearance &rsaquo; Font Awesome
  settings screen gained a Diagnostics section showing the active version,
  delivery method, and source, plus a heuristic check that flags a likely
  duplicate Font Awesome load — a hardcoded reference found in the active
  theme's own files or another active plugin's main file, independently of
  this plugin. Detection only; removing the conflicting reference is still
  a manual step once flagged.
- Developer documentation (LP-008): two new reference docs for theme and
  plugin authors — `docs/THEME-DEVELOPMENT.md` (the template-tag API,
  template hierarchy, and widget/menu/Theme Options registration) and
  `docs/DEVELOPER-APIS.md` (every hook and filter core fires, plugin
  file structure and lifecycle). Previously undocumented outside reading
  the source directly.
- Admin dark mode toggle (LP-087): the admin's color scheme no longer
  has to follow whatever your OS/browser is set to. My Profile gained
  a new "Appearance" panel with a Light / Dark / Follow System choice,
  saved per account like the existing Default Editor setting; a small
  sun/moon button in the sidebar also gives a one-click Light/Dark
  switch from any admin screen without visiting Profile. The choice
  applies server-side before the stylesheet even loads, so there's no
  flash of the wrong theme on page load.
- Admin panel/card section titles (LP-091, e.g. "Backups", "Appearance")
  now sit on a tinted accent-color header bar spanning the full width
  of their panel, matching the treatment the Post/Page editor's sidebar
  boxes already had, instead of a plain heading with a thin underline —
  makes it faster to visually scan a page and see where one section
  ends and the next begins.
- Sidebar expand/collapse-all (LP-092): a small ⊞/⊟ icon button, one
  above the admin sidebar's nav and one below it, opens or closes every
  collapsible menu section (Posts, Media Manager, Pages, Appearance,
  Settings, Maintenance) at once instead of clicking each one
  individually. Stays in sync with expanding/collapsing sections one at
  a time, and remembers the result the same way individual sections
  already did.
- Merge categories (LP-010): a new "Merge into…" bulk action on the
  admin Categories list moves every post from the selected source
  categories to a chosen target category (without duplicating a post
  already in both), reparents the sources' child categories onto the
  target, and removes the source categories.
- Trash with restore, and bulk management, for Categories (LP-010): the
  admin Categories list gained a Trash tab and checkbox-driven bulk
  actions (Move to Trash / Restore / Delete Permanently), matching the
  existing Posts/Pages pattern. A trashed category disappears from
  every public archive, feed, sitemap, category picker, and the REST
  API, but stays recoverable until permanently deleted (only offered
  once already in the Trash).
- Category RSS/Atom feeds (LP-010): every category archive now has its
  own subscribable feed at `/category/{slug}/feed` (RSS 2.0) and
  `/category/{slug}/feed/atom` (Atom 1.0), listing just that category's
  published posts, newest first — the same item shape (excerpt/full
  content, author, publish date, optional featured-image enclosure) as
  the existing site-wide `/feed`. Respects the `feeds_enabled`,
  `feed_item_limit`, `feed_full_content`, `feed_cache_lifetime`, and
  `feed_featured_images` settings, and the `category_base` permalink
  option. Filterable via a new `feed_category_channel` hook, alongside
  the existing `feed_item` hook.

- Discussion settings (comments) for Pages (LP-009): Pages can now
  accept comments, matching Posts — an "Allow comments on this page"
  toggle in the Page editor, threaded guest/signed-in commenting with
  moderation, notifications, and spam protection all working the same
  way they already do for Posts. The admin Comments screen now shows
  and links to whichever a comment belongs to, Post or Page.
- Preview button for Pages (LP-009): the Page editor's Publish box
  gained a "Preview" link, matching the Post editor's — lets an
  author/editor see a Draft, Pending Review, Scheduled, or Private
  page exactly as it will render publicly, without publishing it and
  without any other visitor ever being able to reach it.
- Pending Review and Private pages (LP-009), matching the equivalent
  Posts features exactly: Contributors and other roles without
  `publish_posts` can now submit a page for review ("Submit for
  Review" in the Status field) instead of only ever saving a Draft; an
  Editor/Administrator can mark a page Private (visible only to its
  author or a user who can edit pages) via a new Visibility field or
  the list's new "Set Public"/"Set Private" bulk actions. A Private
  page is excluded from the XML sitemap, the REST API, and the Pages
  widget, and returns a normal 404 to anyone else who requests its URL
  directly.
- "Change author to&hellip;" bulk action for Pages (LP-009), matching
  the equivalent action already on the Posts list.
- Quick Edit for Pages (LP-009): a new "Quick Edit" row action on the
  Pages list (flat/paginated view only, not the drag-and-drop tree
  view) expands an inline form — Title, Slug, Parent, Status — and
  saves without navigating to the full editor or reloading the page.
- Search & Filtering for Pages (LP-009): the Pages list screen gained a
  collapsible "Search & Filter" panel — a single search box matching
  either the title or the content, plus filters for author, parent
  page, and a created-date range — mirroring the equivalent panel on
  the Posts list. Applying any filter (or a status tab other than
  "All") switches the list from the drag-and-drop tree view to the
  flat, paginated table, since a filtered result set can't preserve
  the tree's parent/child grouping.
- Duplicate Pages (LP-009): the Pages list screen (both the flat table
  and the tree view) gained a "Duplicate" row action next to Trash,
  matching Posts. Duplicating clones the title (suffixed " (Copy)"),
  content, excerpt, featured image, and SEO title/description as a new
  Draft — the parent is intentionally left unset rather than copied, so
  duplicating a page never silently doubles part of the page tree.
- Dummy Content plugin (LPP-005): a new bundled, inactive-by-default
  developer plugin (a Dummy Content section on Maintenance &rsaquo;
  Tools, once activated from Plugins) generates realistic placeholder
  content — one user per
  role, a small nested category tree, a varied tag set, placeholder
  images, posts mixing every status/content format, a shallow page
  hierarchy, and comments mixing status/threading/guest-and-registered
  authors — at a chosen volume (small/medium/large), so a theme or
  plugin can be exercised against real-shaped content without hand-
  authoring test data. Every generated record is tracked so "Remove All
  Generated Content" deletes exactly what was generated, never anything
  created by hand. Built on a new shared import layer
  (`ContentImportRegistry` plus `PostImporter`/`PageImporter`/
  `UserImporter`/`MediaImporter`/`CommentImporter`) intended to also back
  a future WordPress WXR importer (LPP-004).
- Footer Widget Area and Inactive Widgets (LP-048): the default theme now
  registers and renders a Footer Widget Area (Appearance &rsaquo;
  Widgets), shown above the footer navigation only when at least one
  widget is assigned to it — every existing widget type works there
  unchanged, laid out as a wrapping row of cards instead of a single
  stacked column. Removing a widget from any widget area no longer
  deletes it outright: it moves into a new "Inactive Widgets" section at
  the bottom of the Widgets screen, where its settings stay intact until
  it's reactivated into any widget area or deleted permanently.
- Visible update progress (LP-086): both update paths on Maintenance
  &rsaquo; Updates — GitHub's "Download & Install" and the manual ZIP
  "Confirm & Install" — now show a live, step-by-step checklist (e.g.
  Backing up files &rarr; Backing up database &rarr; Applying update
  files &rarr; Running database migrations &rarr; Clearing caches &rarr;
  Finishing up) while the operation runs, instead of a blank page with
  no feedback until it finishes. The Manual Update tab's existing
  byte-upload progress bar now hands off to a similar checklist once the
  file itself has finished uploading and the server starts
  validating/checking compatibility. A failed step is marked distinctly
  from a completed one, so the actual point of failure stays visible.
- Underline and fixed-palette font color in both the Markdown and
  WYSIWYG editors (LP-015/LP-016): the Markdown editor gained an
  Underline toolbar button and a Font Color button opening a swatch
  picker (red, orange, yellow, green, blue, purple, gray), inserting
  `++text++` and `[text]{.color}` respectively — new Markdown
  conventions, since Markdown has no native syntax for either; the
  WYSIWYG editor's toolbar already had Underline and gained a matching
  Font Color menu. Font color is a fixed palette rather than a free
  color picker, applied as a `has-{color}-color` CSS class rather than
  an inline `style` attribute, which this app's HTML sanitizer never
  allows. Switching a post or page between Markdown and HTML editing
  preserves both through the conversion. Blockquote formatting was
  already supported in both editors.
- Post & Page editor sidebar layout (LP-083): the New/Edit Post and
  New/Edit Page screens are now a classic two-column layout instead of
  one long stacked column — Title, Slug, the content editor, and Excerpt
  stay in the main column, while Publish (with the Save/Preview/Cancel
  buttons now inside it), Featured Image, Categories, Tags, Allow
  Comments, SEO, Parent Page, Custom Fields, and Author reassignment move
  into a right-hand sidebar of drag-reorderable boxes. SEO, Custom
  Fields, and Author reassignment can also be collapsed. Box order and
  collapsed state are remembered per user, separately for Posts and
  Pages, and persist across page reloads without a full-page save. Below
  782px wide, the layout collapses back to a single column. The Custom
  Fields row wraps its Remove button onto its own line instead of
  overflowing the sidebar, and the Publish box drops its old long-form
  field hints (kept from the previous single-column layout) to stay
  compact enough to drag other boxes past comfortably. The Featured
  Image preview is bigger in this sidebar box than the small
  logo/favicon-style preview it's styled from elsewhere.
- Configurable session storage location: a new `session_path` option in
  `config/config.php` lets an install write PHP session files somewhere
  other than the default `storage/sessions` (an absolute path outside
  this install, a tmpfs, etc.). Leave it blank (the default) to keep
  today's behavior; an invalid or unwritable path is ignored and PHP's
  own session storage setting is used instead, so a bad value never
  breaks logins.
- Pages: Trash, bulk actions, and a drag-and-drop tree view (LP-009):
  Pages can now be moved to Trash and restored, permanently deleted only
  once already trashed, and acted on in bulk (Trash, Restore, Delete
  Permanently, Publish, Mark as Draft, Change parent to&hellip;) from a
  new checkbox-and-bulk-actions bar on the Pages list. The unfiltered
  "All" tab now shows pages as a flat, depth-indented tree that can be
  reordered by dragging a page above or below its siblings. Deleting a
  page with children now moves them to the top level instead of leaving
  them pointing at a page that no longer exists. Public pages with at
  least one ancestor now show a breadcrumb trail (About &rsaquo; Team,
  etc.) above their title, in both the default theme and Duskline. The
  Page editor also gained a Permalink row (the live URL plus a
  "View Page" link) once a page is Published, matching the Post
  editor's existing row.
- Drag-and-drop reordering on Appearance &rsaquo; Widgets and Appearance
  &rsaquo; Menus (LP-048/LP-049): widgets within a sidebar, and menu items
  among their siblings, can now be reordered by dragging instead of only
  the Move Up/Move Down buttons — both stay available side by side. Menu
  item drag-and-drop only ever reorders among items sharing the same
  parent; re-parenting an item remains the "Parent Item" dropdown's job.
- Update an installed theme in place from a ZIP upload (LP-081): Appearance
  &rsaquo; Themes previously only let an administrator install a brand-new
  theme from a ZIP — re-uploading a newer ZIP for an already-installed
  theme hard-failed, forcing a destructive delete-then-reinstall cycle that
  wasn't even possible for the currently active theme. Each theme's details
  panel now has an "Update from ZIP" action (with a confirmation prompt,
  since it overwrites that theme's files) that works for the active theme
  too. The upload is validated and extracted to a staging directory first
  and only swapped into place afterward, with the previous version restored
  automatically if anything goes wrong partway through — a failed update
  never leaves a theme half-installed.
- Configurable permalink structure & settings (LP-078): a new Settings
  &rsaquo; Permalinks admin page lets a site owner choose how post URLs
  are built — Post name (`/post/%postname%`, today's behavior, stays the
  default), Day and name (`/%year%/%monthnum%/%day%/%postname%`), Month
  and name (`/%year%/%monthnum%/%postname%`), or a custom structure built
  from `%postname%`/`%year%`/`%monthnum%`/`%day%`/`%category%`/`%author%`
  tokens with a live preview — plus separate Category base and Tag base
  fields to rename the `/category/{slug}`/`/tag/{slug}` URL prefixes. A
  warning appears on the settings page when the site already has
  published posts, since changing the structure changes those posts'
  URLs going forward. Every place a post/category/tag URL was previously
  hand-built (feeds, the XML sitemap, the REST API, search results,
  admin screens, and every default theme template) now goes through the
  same `post_permalink()`/`category_permalink()`/`tag_permalink()` theme
  API, so a configured structure is honored everywhere at once. An
  unconfigured site's URLs are unchanged.
- Front Page & Archive Post Display (LP-079): a new "Post Display" tab
  under Appearance &rsaquo; Theme Options controls how posts appear on
  the front page and category/tag/author/date archives — Excerpt (a
  preview with a Read More link) or Full Content (the entire post
  inline), whether the featured image shows in listings, the automatic
  excerpt length in words, and the Read More link's text. A Read More
  tag can now be inserted into post content (a new toolbar button in
  both the Markdown and WYSIWYG editors) to choose the preview cutoff
  point by hand — takes priority over both the "Full Content" setting
  and the automatic excerpt (so a listing still stops at the tag even
  when the site is set to show full posts), but yields to a manually
  entered Excerpt field. New `the_content()`/
  `get_the_content()`/`the_excerpt()`/`get_the_excerpt()` theme API
  functions, modeled on WordPress's own, available to themes and
  plugins. Single-post pages are unaffected — always the full post,
  regardless of this setting.
- Featured image crop size setting, multi-file upload, and Media-Manager
  cropping (LP-080): the manually-cropped featured image's output size is
  now configurable (Media Manager &rsaquo; Thumbnail Settings), instead of
  always capping to the Large thumbnail size. The Media Manager's upload
  screen now accepts multiple files at once, uploading them one after
  another with a visible per-file progress list. A new "Create Cropped
  Featured Image" action on any already-uploaded image's Media Manager
  edit screen crops it into a brand-new, independently selectable Media
  Library item (its own file and thumbnails) — the original image is
  never modified, and cropping is no longer only reachable from inside a
  specific post's or page's own featured-image field.

### Changed

- WordPress Importer: a migrated Simple Download Monitor category now
  nests under one top-level "Downloads" Media folder instead of
  landing at the Media Library's own root, keeping a site's download
  categories visually separate from its regular media organization.
- The Pages widget and Categories widget (LP-104) now render a real
  nested list — a child page or subcategory indents under its parent
  instead of appearing in the same flat, alphabetized list as everything
  else. Both widgets' top level (and every nesting level's siblings) are
  alphabetical. The Pages widget's "Number of pages to show" setting was
  removed: a hard item limit is ambiguous against a tree (it could cut a
  parent's children off, or orphan a child whose parent fell outside the
  limit), so — matching WordPress's own core Pages widget, which has
  never had such a setting either — it now always lists every published
  page.
- Appearance &rsaquo; Menus' "Add Items" panel (LP-103) now indents child
  pages and subcategories under their parent, matching the "All Pages"
  admin list's existing tree view, instead of listing everything flat
  and alphabetized with no indication of hierarchy. Posts and Tags have
  no hierarchy in this app and stay flat as before.
- The default theme's homepage post listing (LP-102) no longer shows a
  "Welcome to {Site Name}" heading above the post list — it didn't
  correspond to any real content, unlike `single.php`/`page.php`/
  `archive.php`'s own headings, which title the actual post/page/archive
  being viewed.
- Pagination controls, both on the frontend (post archives, search,
  category/tag listings) and in the admin (Posts, Pages, Comments,
  Users, Media Manager) (LP-100), now truncate long page ranges with
  `…` ellipses instead of rendering a button for every page — a site
  with dozens of pages previously produced a wall of numbered squares
  wrapping across several rows. Previous/Next and First/Last controls
  were also added alongside the numbered pages. All of this lives in
  the single shared `render_pagination()` helper already used by every
  frontend template and every admin list view, so no markup diverged
  between the two surfaces.
- The default theme's front page and archive listings (LP-094) now
  show each post's featured image as a full-width banner above the
  title, instead of a small square thumbnail beside it — matching a
  classic blog/fansite layout. The existing "Show featured image in
  listings" Theme Option still controls whether it appears at all;
  there's no separate setting for the layout itself.
- Pages (LP-009): the admin sidebar's single "Pages" entry is now a
  submenu with **All Pages** and **New Page** children, matching Posts'
  existing menu structure. The single `admin/views/pages.php` view
  (list and editor combined behind an `?action=` query param) is split
  into `admin/views/pages/all-pages.php` and `admin/views/pages/new.php`,
  the same split Posts already went through — no change to what Pages
  can do, only to how the admin screens for them are organized and
  addressed.
- Appearance &rsaquo; Themes' form-handling logic (activate, delete,
  install/update from ZIP, and Branding) now lives in a dedicated
  `ThemesController` class instead of inline in the view template
  (LP-082) — an internal refactor with no user-facing behavior change,
  except that a form whose session token has expired now shows an error
  message instead of silently reloading the page with no feedback.
- Posts' form-handling logic (quick draft, trash, restore, delete
  permanently, duplicate, bulk actions, save, and revision restore) now
  lives in a dedicated `PostsController` class the same way (LP-082) —
  again an internal refactor with no user-facing behavior change, except
  that the Quick Draft form and bulk actions now show an error message
  instead of silently reloading the page if their session token expired.
  The post editor's image upload, format-conversion, and inline
  category-add requests moved into the same class — purely internal,
  no behavior change at all this time.
- Settings &rsaquo; General's Date format and Time format fields are now
  dropdowns of common presets (each showing a live-rendered example)
  with a "Custom" option that reveals a free-text field for any other
  PHP `date()` format string, replacing the previous always-visible
  plain text input.
- Admin visual polish, pass 3 (LP-085): the Post/Page editor's sidebar
  boxes (LP-083) now get the same shadow and panel-radius treatment as
  the rest of the admin panel instead of looking flatter than
  everything around them; the Dashboard's Recent Posts and Recent
  Comments widgets, and the Users list's role column, now show the
  same colored status-badge pills already used elsewhere
  (Posts/Pages/Comments lists) instead of plain text; the Login/Forgot
  Password/Reset Password screen gained the same card shadow and
  corner radius every other panel in the admin already has; and the
  shared card shadow itself (`--lp-admin-shadow`/`--lp-admin-shadow-hover`,
  used by every panel, sidebar box, and plugin/theme/media card) is
  noticeably more visible than before, since the previous value was too
  subtle to read as a shadow at all against the admin's light-gray
  background; and the Post/Page editor sidebar's box headers ("Publish",
  "Featured Image", "Categories", "Tags", etc.) now sit on a tinted
  accent-color background with an accent-colored title, replacing the
  plain white header bar that gave the sidebar no visual separation
  between a box's title and its own content.

### Fixed

- A trashed page could still be selected as a Parent Page, a Settings
  &rsaquo; Reading homepage/posts-page, or a Settings &rsaquo; Privacy
  policy page (LP-109) — `PageService::listAllForParentSelect()` had no
  status filter at all, unlike its `CategoryService` counterpart.
- A trashed post could still be selected and added from Appearance
  &rsaquo; Menus' "Add Items" panel (LP-108) —
  `PostService::listAllForMenuSelect()` had no status filter at all.
  Draft/Pending Review/Private/Scheduled posts remain selectable, as
  intended.
- A lightbox opened from a post/page image imported via the WordPress
  Importer could display the image at its old, much smaller thumbnail
  size instead of its real full resolution (e.g. a 1920×1080 photo
  opening the lightbox at 250×141). The importer intentionally rewrites
  every `<img src>` to the full-size original but leaves `width`/`height`
  at the source post's old display size; `ContentRenderer`'s lightbox
  pass wrongly trusted those attributes as the linked file's real
  dimensions whenever an image was self-linked (`href` equal to `src`).
  It now always resolves the real file's dimensions from disk in that
  case, falling back to the attributes only when the file can't be read.
- Content imported via the WordPress Importer could render with
  double-encoded HTML entities — e.g. a category named "TV & Movies" in
  the source site showing up as the literal text "TV &amp;amp; Movies"
  instead of "TV & Movies" (LP-113). WordPress HTML-entity-encodes
  plain-text fields before storing them; the importer was reading that
  already-encoded value verbatim and letting it be encoded a second time
  at render. Fixed at the source for every future import (term names,
  post/page titles and excerpts, comment author names/content, user
  display names, media alt text), and a new "Fix Double-Encoded Text"
  tool on Maintenance &rsaquo; Tools repairs content already affected by
  it from an import made before this fix.
- The WordPress Importer plugin (`content/plugins/wordpress-importer`)
  was missing from the update pipeline's core-paths list (`core-paths.php`
  and its hardcoded fallback in `include/bootstrap.php`), even though
  README.md already documented it as one of the bundled plugins a manual
  or automatic update preserves. UpdateService's `install()` never
  actually overlaid it, so an update could silently leave a site's copy
  of the plugin stale instead of replacing it with the newer bundled
  version. Added to both lists, matching the existing pattern for
  Font Awesome, Dummy Content, and Downloads.
- The admin Posts and Pages list screens' "Date" column (both the flat
  table and the Pages tree view) showed each item's `updated_at`
  (database row modification time) instead of its actual publish date
  — invisible for hand-authored content, where the two are normally the
  same moment, but glaringly wrong for anything backdated, most visibly
  every post/page brought in via the new WordPress Importer (LPP-004):
  a post originally published in 2018 showed today's date. Now shows
  `published_at` (falling back to `updated_at` for a Draft or other
  status with no publish date yet), matching how the public-facing
  theme has always displayed "the date" of a post or page. The same
  screens' sort order and "Created from"/"Created to" date-range filter
  (`PostService`/`PageService::paginateForAdmin()`) had the identical
  problem — both always used `created_at`, so "newest first" sorted by
  when a row was inserted rather than the content's own date, clustering
  every backdated/imported item at the top regardless of how old it
  actually was. Both now sort/filter by `COALESCE(published_at, created_at)`
  too, and the filter fields are relabeled "Date from"/"Date to" to
  match.
- The admin sidebar had no mobile layout at all (LP-096) — at phone
  widths it kept its fixed desktop width, squeezing the main content
  into a column so narrow that ordinary text wrapped one character
  per line. The sidebar now becomes a hamburger-triggered off-canvas
  drawer below 782px width (dismissible via a backdrop tap or Escape),
  matching the breakpoint already used elsewhere in the admin; nothing
  changes above that width. Two related phone-width overflow bugs
  found while fixing the above are fixed alongside it: the Post/Page
  editor's whole layout could be forced far wider than the screen by
  its own unwrapped toolbar row, and every admin list table (Posts,
  Pages, Comments, Categories, Tags, Users, Redirects, ...) could
  overflow its card with no way to reach the cut-off columns — list
  tables now scroll horizontally within their own card instead.
- The Theme Options "Content width" setting (Appearance &rsaquo;
  Theme Options &rsaquo; Layout) silently overrode a theme's own
  chosen content width even when the administrator had never touched
  that setting (LP-095) — it always emitted a `960px` default,
  loaded after the active theme's own stylesheet, so a theme's own
  `--lp-max-width` never actually took effect. Now defaults to "Use
  theme default" and only overrides the theme's own value when an
  administrator explicitly picks a specific width.
- Markdown editor toolbar buttons (Bold, Italic, headings, etc.) were
  nearly invisible in dark mode (LP-088) — the existing color override
  targeted `<a>` tags, but this project's bundled EasyMDE build
  actually renders toolbar buttons as `<button><i></i></button>`, so
  the rule matched nothing and every icon fell back to EasyMDE's own
  hardcoded black.
- Form fields (inputs, textareas, selects) used the exact same
  background color as the panel they sat inside (LP-088), making it
  hard to tell what was editable at a glance, most visible in dark
  mode but present in light mode too — now use their own subtly
  distinct background.
- Links across the admin (Posts/Pages list titles, breadcrumbs,
  sidebar nav, pagination, and more) could silently fall back to the
  browser's default purple "visited" color instead of the theme's own
  palette once clicked (LP-089), so two rows using identical markup
  could render in two different colors purely based on the visitor's
  own click history. Every link-color rule in the admin now has a
  matching `:visited` style.
- Action buttons across the admin were inconsistently styled — some
  screens' main "Save"/"Create"/"Activate" action used the same plain
  white/colorless button as an incidental "Filter" or "Cancel" button
  right next to it (LP-090), giving no visual signal for which one
  mattered. The neutral button style itself also blended into its
  surrounding panel, reading as unstyled rather than intentionally
  plain. 53 buttons across the admin were individually reviewed and
  given the correct primary/secondary treatment, and the neutral style
  now has its own subtle background.
- The Post/Page editor's SEO and Custom Fields sidebar boxes (LP-093)
  now start collapsed the first time a user ever opens either editor,
  matching classic WordPress's own postbox defaults for optional/
  secondary fields, instead of rendering fully expanded until the user
  manually collapses them once. A user's own saved preference — including
  explicitly leaving everything expanded — is never overridden by this;
  it only applies before any preference has ever been saved.
- Dashboard's Recent Posts/Recent Comments status badges (e.g.
  "Scheduled", "Pending", "Approved") could render as an oversized blob
  instead of a compact pill whenever they sat next to a title long
  enough to wrap onto two lines — the list row's flexbox stretched the
  badge to match the row's full height by default, and a 999px border
  radius turned that stretched shape into a blob that swallowed its own
  text.
- Manual/automatic updates could silently fail to deliver a newly-added
  bundled file or plugin (e.g. the new Dummy Content plugin) to a site
  running code from before that addition existed — the updater only ever
  consulted the *currently-installed* code's own list of "core" paths to
  decide what to overlay from the uploaded package, so a path only just
  added to that list could never reach an older install no matter what
  the uploaded package actually contained. The list is now its own file
  (`core-paths.php`) that the updater reads from the *uploaded package*
  as well as the running install, so newly-added paths always come
  through correctly regardless of how old the site being updated is.
  If `core-paths.php` itself is ever missing (an interrupted or
  otherwise half-applied update), the site no longer goes down entirely
  — it now falls back to a built-in default list instead of a fatal
  error on every page.
- Media Manager: selecting multiple files to upload always failed every
  file with "network error" shown next to each, even though the uploads
  themselves succeeded. `admin/views/media/upload.php`'s multi-file AJAX
  responses were never discarding `admin/index.php`'s output buffer
  before sending JSON, so the actual response body was buffered admin
  HTML followed by the JSON, not valid JSON on its own — the browser's
  `response.json()` call threw on the malformed body. Single-file uploads
  were unaffected (they redirect normally rather than going through the
  AJAX path).
- Appearance &rsaquo; Themes: activating a theme could silently fail with
  no error shown. Each theme's card rendered its own CSRF-protected
  Activate form, and its details dialog further down the page rendered a
  second, separate Activate form for the same theme — issuing a fresh
  CSRF token for the second form invalidated the token already embedded
  in the first, so submitting the visible card's Activate button posted
  a stale token and the request was silently rejected. Both forms now
  share a single issued token.
- Insert Media's "Link To: None" now means no link at all. Previously,
  every inserted image still ended up wrapped in a link regardless of
  this choice — an unlinked image automatically gets a self-link so it
  can open in the PhotoSwipe lightbox (LP-076), which "None" was never
  actually opting out of, only "Media File" was ever meant to override.
  Both the Markdown and WYSIWYG editors now add a `no-lightbox` marker
  when "None" is chosen, genuinely producing a plain, non-clickable
  image; choosing "Media File" is unaffected.
- Thumbnail generation never corrected an image's orientation from its
  EXIF data on any server without the PHP `exif` extension installed —
  common on budget shared hosting — silently producing sideways or
  upside-down thumbnails for photos straight off a phone or camera
  instead of failing loudly. `ThumbnailService` checked whether the real
  `exif_read_data()` function existed before ever consulting its EXIF
  reader, even when a reader had been supplied directly; that guard now
  only applies to the built-in reader, not a supplied one.

## [0.6.0] — 2026-08-10

### Added

- Post editor Permalink row (LP-008): once a post is Published, the
  admin edit screen now shows a "Permalink" row above the Slug field with
  the live URL and a "View Post" link, opening in a new tab.
- Performance (LP-008): `MediaService::find()`, `UserService::findById()`,
  and `ThumbnailService::thumbnailsFor()` now memoize by id for the life
  of a request, eliminating repeat identical queries the Featured Image
  and author-link theme APIs were making per post on the homepage,
  archive, category/tag/author, and search-results listings (rendering
  one post's thumbnail alone could re-query the same media row up to five
  times). Every write method on these ids evicts the cache, so a save
  still reads back fresh data in the same request.
- Text and image alignment in both editors (LP-016): a new alignment
  toolbar (left/center/right/justify) for paragraphs and headings, and
  an Alignment choice (None/Left/Center/Right) in the Insert Media
  picker's Attachment Display Settings step for images. The WYSIWYG
  editor applies these directly; the Markdown editor uses a new, minimal
  trailing-marker syntax (`{.center}` after a heading/paragraph,
  `{.aligncenter}` after an image) — Markdown has no native attribute
  syntax, so this is a small project-defined convention, not
  CommonMark — with matching toolbar buttons that insert it.
- Configurable lightbox image size (LP-031): Settings &rsaquo; Media
  gains a "Lightbox image size" option — choose which size the featured-
  image lightbox loads when opened (any enabled thumbnail size, or the
  raw original). Defaults to the existing `large` size (~1024px), so
  behavior is unchanged unless explicitly reconfigured.
- Insert Media: Attachment Display Settings (LP-075): the "Insert from
  Media Manager" picker in both the Markdown and WYSIWYG post/page
  editors now shows a Size (Thumbnail/Medium/Large/Full — only sizes an
  image actually has) and Link To (None/Media File) step before
  inserting, matching the classic "Attachment Display Settings" choice
  from other blogging software. Previously every insert dropped in one
  fixed-size image pointing at the original file with no way to link it
  to itself.
- Content-Embedded Image Lightbox (LP-076): images inserted into post/page
  body content now open the PhotoSwipe lightbox (LP-031) on the public
  front end, not just the featured image. A plain inserted image becomes
  lightbox-clickable automatically; an image an author explicitly linked
  to its own full-size file keeps that link and gains the same lightbox
  behavior; a link to anything else is left untouched. A new
  `no-lightbox` class opts an individual image out.
- Media Viewer & Lightbox, second pass (LP-031): the lightbox now shows
  each image's dimensions and, when a new "Show filenames in lightbox"
  setting (Settings &rsaquo; Media, off by default) is turned on, its
  filename. A download button saves the currently displayed full-size
  image. A slideshow toggle auto-advances through a gallery every 4
  seconds until turned off or the lightbox is closed. Individual images
  are now deep-linkable — opening one updates the URL with a
  `#lp-media-{id}` hash, and loading a page with that hash present
  reopens the same image directly. Videos in the Media Manager can now
  have a poster image (shown before playback) and a WebVTT
  caption/subtitle track, both chosen from already-uploaded media via new
  `<select>` fields on the video edit view.
- Direct Media Link & Embed Code (LP-074): the Media Manager's image edit
  view gained a "Direct Link & Embed Code" panel with a ready-to-copy
  direct image URL and an HTML embed snippet (`<a><img
  class="alignnone size-full" ...></a>`, matching the classic full-size-
  image insert markup), for pasting into other tools without hand-
  building the HTML.
- Search now covers Categories, Tags, and Authors as well as Posts and
  Pages (LP-014). Author results only ever include a user with at least
  one published post. A search results page mixes all five result types,
  ranked together by relevance.
- Two new classic widgets (LP-048): **Statistics** shows published post,
  published page, approved comment, and registered user counts. **Social
  Links** renders a row of profile links for a fixed set of platforms
  (Website, Email, Mastodon, Bluesky, Twitter/X, GitHub, YouTube,
  Instagram, Discord) — only platforms with a URL configured are shown.
  Icons render automatically when the optional Font Awesome plugin is
  active; otherwise each link falls back to visible platform text.
- Manual Update (ZIP Upload) — remaining pre-update safety checks (LP-026):
  a manual/GitHub update now briefly enables maintenance mode for its
  duration (restored to whatever it was set to beforehand once the update
  finishes), and warns — without blocking — if another user has been
  active in the admin area recently, or if a core file appears to have
  been hand-edited since the last install and is about to be overwritten.
  Both automatic backups (files and database) taken before an update are
  now verified immediately after being written, so a corrupted or
  truncated backup is caught before the update ever proceeds, rather than
  only discovered if a restore is later needed.
- Manual Update (ZIP Upload) improvements (LP-026): the upload box on
  Maintenance &rsaquo; Updates now accepts drag-and-drop and shows a real
  progress bar while the archive itself uploads. Three new pre-update
  checks run before an upload is ever staged: the connected database
  server's version against this project's documented minimum (MySQL 5.6.4+
  / MariaDB 10.0.5+), `config/config.php`'s own health (present, valid,
  every required setting non-empty), and that the backup destination is
  writable with enough free space. A package whose version string looks
  like a development build (`-dev`/`-alpha`/`-beta`/`-rc`) now surfaces a
  warning rather than being treated identically to a stable release. A
  successful update now also purges the external reverse-proxy/edge cache
  (if one is configured), not just this app's own local file cache.
- Optimize images after import (LP-041): the FTP Media Import screen gains
  an "Optimize images after import" checkbox, off by default and always
  opt-in per import run. When checked, each imported image is re-encoded
  through GD at the same JPEG/WebP quality settings thumbnails already use
  (Settings &rsaquo; Media), keeping the smaller result only — the file is
  left untouched if recompression wouldn't actually shrink it. Animated
  GIFs are never touched.
- Draft scheduling (LP-018): a Draft post can now carry a planned publish
  date via the same "Publish date" field the admin post editor already
  offered for Scheduled posts. It has no effect on visibility — a Draft
  stays private regardless — but the date survives a later Draft →
  Scheduled switch without needing to be re-entered.
- Bulk comment moderation (LP-025): the admin Comments screen gains a
  "Bulk actions" control (Approve, Unapprove, Mark as Spam, Move to
  Trash, Delete Permanently) with a "select all" checkbox, matching the
  bulk-actions pattern already used on the Users screen.
- Discussion Settings (LP-047): a new Settings &rsaquo; Discussion admin
  screen covering comment defaults, moderation, notifications, and
  avatars. Comment author name/email can each be made optional; commenting
  can require a registered/logged-in account; comments on a post can
  auto-close after a configurable number of days; a "Save my name and
  email in this browser" cookie-consent checkbox can be shown on the
  comment form; threaded comments can be disabled or capped at a maximum
  visual nesting depth; comments can be paginated (configurable per-page
  count, first/last default page, oldest/newest ordering). New
  moderation controls: require manual approval for every comment (or
  disable the existing auto-approve-previously-approved-commenter trust
  signal specifically), hold a comment for moderation if it contains more
  than a configurable number of links or matches a configurable
  moderation-keyword list, and reject a comment as Spam outright if it
  matches a configurable disallowed-keyword list — checked against the
  comment content and the author's name/email/website. A new
  `comment_is_spam` filter runs alongside the existing Akismet (LP-025)
  integration on both the public comment form and the REST API, so a
  future spam-detection plugin (e.g. the planned Lumora Shield) needs only
  one hook to cover both. New admin/author email notifications for new
  comments and comments awaiting moderation, with additional configurable
  recipients, sent via the existing LP-058 mail service. Avatars can be
  turned off site-wide, given a maximum Gravatar rating and a choice of
  built-in default image, or given a locally uploaded default image
  (reusing the same upload-a-media-item pattern Branding's logo/favicon
  already use) — shown next to each comment on the default theme.
- Twitter/X Auto-Embed (LP-070): a bare tweet-status link alone on its own
  line in a post/page now auto-embeds, alongside the five existing
  Auto-Embed providers (YouTube, Vimeo, SoundCloud, Spotify, CodePen).
  Unlike those five, Twitter/X has no plain-iframe embed, so `EmbedService`
  emits a `<blockquote class="twitter-tweet">` instead of an `<iframe>`,
  and the default theme conditionally loads `platform.twitter.com/widgets.js`
  (which renders the blockquote into a tweet) only on pages that actually
  contain one, via a new `ScriptEmbeds` bridge class mirroring the existing
  `MediaViewer`/PhotoSwipe conditional-loading pattern. Settings &rsaquo;
  Embeds gains a Twitter/X toggle alongside the other five, and the
  Content-Security-Policy's `script-src`/`frame-src`/`connect-src`
  directives widen for `platform.twitter.com`/`syndication.twitter.com`
  only while that toggle is on.
- Bluesky Auto-Embed (LP-071): a bare Bluesky post link on its own line
  auto-embeds the same way, as a seventh Auto-Embed provider. Bluesky's
  official embed needs a resolved AT-URI and content hash that can't be
  derived from the pasted link alone, so a new `BlueskyResolverService`
  resolves and caches each distinct Bluesky link once, when the containing
  post/page is saved — never on page render — via Bluesky's own oEmbed
  endpoint; only the two needed values are cached, not Bluesky's raw
  response. A down or slow Bluesky never blocks saving or breaks the page:
  an unresolved link simply stays a plain link until a later save resolves
  it. The default theme conditionally loads `embed.bsky.app/static/
  embed.js` only on pages that actually contain a resolved Bluesky embed.
  Settings &rsaquo; Embeds gains a Bluesky toggle, with updated hint text
  noting this is the one provider that does contact the network (at save
  time only).

### Fixed

- The WYSIWYG editor's "Insert from Media Manager" silently rewrote every
  inserted image's URL into a path relative to the admin editor's own
  page location (TinyMCE's `relative_urls` default) — correct only from
  that admin page, so the same stored HTML 404'd once rendered on the
  actual public post/page. Caught while testing LP-075/LP-076: a WYSIWYG-
  inserted image displayed fine in the editor but showed only its
  filename as a broken link on the front end, and its lightbox reported
  "The image cannot be loaded." Fixed by disabling that rewriting
  (`relative_urls: false`) so an inserted image's URL is stored exactly
  as given. Pre-existing content that already has a broken relative
  image URL from before this fix needs the image re-inserted to pick up
  a correct URL — this fix only prevents new occurrences.
- The WYSIWYG editor's "Link To: Media File" step (LP-075) could open a
  visibly stretched/blurry lightbox when the chosen display size differed
  from the linked file's own size (e.g. a Thumbnail-size image linked to
  the Full-size original) — the lightbox was sizing itself using the
  inline image's own smaller dimensions rather than the linked file's
  real ones. Fixed by having the editor embed the linked file's own real
  dimensions directly at insert time, which the lightbox now trusts over
  guessing from the inline image.
- The lightbox's dimensions/filename display (`.lp-pswp-meta`) sat
  directly on top of PhotoSwipe's own toolbar (zoom, close, and the new
  download/slideshow buttons), silently blocking clicks on all of them
  even though the buttons stayed visibly clickable-looking. An initial
  `pointer-events: none` fix wasn't enough on its own — PhotoSwipe
  auto-applies its own `pswp__hide-on-close` class to elements registered
  this way, and that class's own CSS
  (`.pswp--ui-visible .pswp__hide-on-close { pointer-events: auto; }`)
  has higher specificity, silently winning pointer-events back the
  moment the lightbox finished opening — exactly when a click was
  attempted. Fixed by making that display (and the caption bar below the
  image) purely visual with `pointer-events: none !important`, since
  neither ever needs to receive clicks.
- Theme and admin CSS/JS assets had no cache-busting query string on
  their URLs, so a browser that loaded a page even once before a fix
  shipped kept using its cached copy of that file indefinitely — the
  previous fix above wasn't visible in any browser that had already
  loaded the lightbox once. `theme_url()` and `admin_asset_url()` now
  append `?v={file's modification time}` automatically for any path that
  resolves to a real file, so every future asset change invalidates
  itself with no manual version bumping required.
- The "Show filenames in lightbox" setting (added last entry) never
  actually reached the browser — it was passed via an inline
  `<script>window.lpMediaViewer = ...</script>` tag, which this
  project's own Content-Security-Policy silently blocks (`script-src`
  has no inline-execution allowance). Fixed by passing it as a
  `data-show-filenames` attribute on the existing external lightbox
  `<script>` tag instead, which isn't inline execution and so isn't
  subject to that policy.
- The lightbox's download and slideshow buttons were nearly invisible
  against the dark toolbar. Their icon `<path>` elements carried an
  explicit `fill="currentColor"`, which resolves against
  PhotoSwipe's `.pswp__icn` `color` property (a dark grey meant for icon
  shadows/outlines) rather than inheriting its `fill` property (white,
  the actual icon color PhotoSwipe's own zoom/close icons use). Fixed by
  removing that attribute so the icons inherit the correct color the
  same way PhotoSwipe's own do.
- Images in a Markdown-authored post/page never triggered the lightbox
  on the front end at all, unlike WYSIWYG-authored images. Markdown has
  no attribute syntax, so a Markdown image never carries width/height —
  and that was the only signal used to recognize a lightbox-eligible
  image, both for deciding whether to load PhotoSwipe's assets at all
  and for PhotoSwipe's own gallery selector. Fixed by adding a dedicated
  marker that's always present regardless of whether dimensions are
  known.
- The lightbox could open with the image stretched full-screen and
  visibly out of aspect ratio. PhotoSwipe uses the reported image
  dimensions to lay out the slide before the real image finishes
  loading, not just as a caption hint — so a missing dimension (every
  Markdown-authored image) or a wrong one (an author-linked thumbnail
  pointing at a larger original) produced a distorted result once the
  real image loaded into a slide sized for the wrong numbers. Fixed by
  reading a linked image's real pixel dimensions directly off disk
  whenever the inline image's own declared size doesn't actually
  describe the file being linked to.

## [0.5.0] — 2026-08-04

### Added

- User Management, completed (LP-032): the admin Users screen now has
  Trash/Restore (a `trashed_at` soft-delete, mirroring the Posts admin's
  own Trash tab — permanent delete is only offered from the Trash view),
  bulk actions (Trash, Restore, Delete Permanently, and Change role,
  guarded the same way as the existing single-row actions: never your own
  account, never the last remaining Administrator, never a user who has
  authored posts or pages), a Search &amp; Filter panel (username/email/
  display name term plus a role dropdown), and avatars — Gravatar by
  default (based on the account email) with an optional per-user
  uploaded override. A trashed user can no longer log in, and an already
  open session for an account trashed mid-session stops working on its
  very next request rather than lingering until it expires; trashing a
  user now also revokes their "Remember Me" tokens, the same as deleting
  one already did.
- Media Manager improvements (LP-068): a "Select all" checkbox above the
  media grid toggles every item's checkbox in the bulk-actions form. The
  Virtual Folder System's "Manage" and "New Folder" toggles now render as
  clear, styled buttons instead of bare text links (`New Folder` visually
  distinguished as the primary action). Folders can now be reordered by
  dragging one folder onto another to reparent it, or onto the "Folders"
  heading to move it back to top level — implemented client-side with
  native HTML5 drag-and-drop, reusing each folder's existing rename form
  and the same server-side cycle-prevention `FolderService::update()`
  already enforced for manual renames — folder links now always show a
  folder glyph and a bordered chip background (rather than only on
  hover), so they read as folders at rest and not just plain links, with
  a grip glyph fading in on hover/focus as an affordance that they can be
  dragged. The "Search & Filter" panels on
  the All Posts and Media Manager screens are now collapsible (a native
  `<details>`/`<summary>` disclosure restyled to match the panel header
  it replaces) and collapsed by default.
- Default Editor (LP-066/LP-067, merged from LP-065 — see `DECISIONS.md`):
  administrators can now set a site-wide default content editor (Plain
  Text, Markdown, or WYSIWYG) on Settings &rsaquo; General, which every
  brand-new post/page opens in unless a user has their own preference.
  A new "My Profile" admin page lets any signed-in user (regardless of
  role) set their own editor preference, with a "Use site default"
  option; administrators can also set it for another user from the
  existing Users edit screen. An optional "lock every user to the
  default" toggle disables per-user overrides without deleting them —
  turning it back off restores whatever each user had chosen. New
  `get_default_editor()`/`get_active_editor($userId)`/
  `registered_editors()` helpers centralize the selection logic, and the
  editor list itself is extensible via `apply_filters('registered_editors',
  ...)` so a plugin can add one without modifying core.
- Auto-Embed (LP-023): pasting a bare YouTube, Vimeo, SoundCloud, Spotify,
  or CodePen link alone on its own line in a post or page now
  automatically expands into an embedded player when rendered — no manual
  `<iframe>` copy-pasting required. A link inline within a sentence is
  always left as a plain link, and an unrecognized or malformed link is
  never modified. Implemented as a fixed, developer-maintained provider
  allowlist with regex-based ID extraction from the URL — never the
  oEmbed HTTP discovery protocol, so this makes no outbound network
  request at any point. New Settings &rsaquo; Embeds admin page
  (site-wide toggle, per-provider toggles, max embed width). Hooks into
  `ContentRenderer`'s existing `content_html` filter, which already runs
  after `HtmlSanitizer::clean()`, so comments (which never pass through
  that filter) are unaffected and no new tag needed adding to the
  sanitizer's allowlist. Extensible via two new filters,
  `apply_filters('embed_providers', ...)` (register additional providers)
  and `apply_filters('embed_html', ...)` (adjust generated markup).
  Twitter/X is deliberately not included in this pass — see
  `TODO.md`'s LP-023 for why.
- Font Awesome (LPP-002), Lumora Press's first bundled first-party plugin
  (`content/plugins/font-awesome`) — first-pass "core plugin foundation"
  scope. A new Settings &rsaquo; Appearance &rsaquo; Font Awesome admin
  page (only visible while the plugin is active) lets an administrator
  enable Font Awesome (off by default, so nothing loads until opted in),
  choose CDN or self-hosted delivery, pin a version, and turn on
  compatibility mode (loads the v4-shims stylesheet alongside Font Awesome
  6 for old `fa fa-camera`-style class names). Adds an `[icon
  name="camera"]` shortcode (with `style`/`size`/`rotate`/`flip`/
  `animation`/`color`/`class`/`label` attributes, decorative icons marked
  `aria-hidden`, labeled ones getting `role="img" aria-label`) usable in
  any post/page body, plus a small developer API: `lp_fontawesome_enabled()`,
  `lp_fontawesome_enqueue()`, `lp_icon()`, and `lp_register_icon_pack()`.
  Self-hosted delivery's origin is added to the Content-Security-Policy
  automatically. Two small pieces of core plumbing landed alongside it,
  both generically reusable by future plugins rather than Font-Awesome-
  specific: `LumoraPress\Core\ActiveConfig` (a `PressConfig` static bridge
  matching `ActiveTheme`/`ActiveContentRenderer`'s existing shape) and a
  new `do_action('head_assets')` call in the default theme's `header.php`
  just before `</head>` — a `wp_head()`-equivalent extension point that
  didn't exist anywhere in this codebase before now. See `TODO-PLUGINS.md`'s
  LPP-002 for exactly what's deferred (Pro/Kit support, SVG rendering, the
  icon picker, the admin diagnostics page, icon metadata caching,
  localization). `content/plugins/font-awesome` is included in the
  manual/GitHub update overlay path (`include/bootstrap.php`'s
  `$updateCorePaths`), the same "bundled first-party, not user-installed"
  treatment `content/themes/default` already gets — a site updating from
  a release before this plugin existed receives it, and its own file
  updates reach an existing install, the same as core. Any other,
  user-installed plugin under `content/plugins/` is still never touched
  by an update.
- Theme Options (LP-034, originally scoped as LP-023 — see DECISIONS.md's
  "LP-023 merged into LP-034" entry): a new Appearance &rsaquo; Theme Options
  admin page lets administrators customize the active theme's colors,
  typography, and content width without editing CSS. Backed by a new
  `LumoraPress\Core\Theme\ThemeOptions` service — themes and plugins can
  register their own sections/fields via `add_action('register_theme_options',
  ...)`, the same hook-based extensibility pattern already used elsewhere in
  this app. Nine built-in options ship this pass: six colors (Accent, Text,
  Muted Text, Background, Alt Background, Border — each independently
  resettable to "Use theme default," which preserves the default theme's own
  dark-mode color variants rather than forcing a light-mode value onto every
  visitor), three typography/layout controls (Body font, Base font size, Line
  height, Content width), plus a Google Fonts URL + font-family pair (a new
  Url control type, restricted by an allowed-hosts check to
  `fonts.googleapis.com` so this field can't become a way to load an
  arbitrary stylesheet on every visitor's browser) that overrides the Body
  font selection when set. Values are exposed to themes via a new
  `theme_option()` helper and rendered as CSS custom properties
  (`theme_options_css()`) in `<head>`, reusing the default theme's existing
  `--lp-*` token names so no theme markup changes were needed beyond adding
  three new typography tokens. See TODO.md's LP-034 for exactly what's
  covered vs. still deferred (Homepage/Header/Footer/Blog options, per-theme
  option scoping, Import/Export, and more).
- Posts (LP-008) gained most of its remaining checklist (configurable
  permalinks deliberately excluded — see TODO.md). Publishing workflow:
  Pending Review status (Contributors submit for review instead of only
  saving a draft), Private posts (visible only to the author or staff
  with `edit_posts`, independent of Draft/Published/Scheduled status),
  Sticky posts (pinned to the top of the homepage only), and Schedule
  unpublishing (an optional date after which a post automatically stops
  being publicly visible, computed live with no background job — same
  approach scheduled publishing already used). The post editor gained
  Custom Fields (a repeatable key/value row editor), a Preview button
  (view a draft/scheduled/pending/private post exactly as it will render
  publicly, without publishing it or exposing it to anyone else), and a
  live URL preview as the slug is typed. Editors/Administrators can now
  reassign a post's author from the edit screen, and every author has a
  public archive page (`/author/{slug}`) with their name linked to it
  from post listings. The admin Posts list gained a Search & Filter
  panel (title, author, category, tag, date range) and three new bulk
  actions: Change author, Change category (adds the category to every
  selected post without removing others already assigned), and Change
  visibility. Saving a post now enforces a title length limit and runs
  Html-format content through the same sanitizer used at render time,
  closing a gap where the REST API's raw `content` field could otherwise
  carry unsanitized stored HTML.
- Media Manager (LP-005) is now complete. Broadened supported
  file types with word-processing documents (doc/docx/rtf/odt) and
  rar/7z archives — SVG is deliberately excluded from the allow-list
  since a browser executes an SVG's embedded script when it's opened
  directly, a stored-XSS risk. The Folders sidebar gained a search box
  (`FolderService::search()`) that matches folder names and keeps every
  ancestor of a match visible so the tree stays coherent. The bulk-action
  bar gained "Download selected" (streams a ZIP of the selected files'
  real content), "Rename selected" (a find/replace substring rename
  across selected filenames — display name only, URLs untouched), and
  "Change metadata on selected" (only overwrites fields an admin actually
  filled in, leaving the rest alone per file). The single-file media edit
  page gained a "Replace file" action: uploads new content under the same
  item's existing id/URL/folder/metadata so every place already linking
  to it keeps working; the replacement must match the original's file
  extension. Internally, `MediaService` now delegates every filesystem
  operation through a new `MediaStorageInterface` (implemented today by
  `LocalFilesystemStorage`, `app/Services/Storage/`) instead of touching
  disk directly — laying the groundwork for a future S3/R2 storage driver
  without changing any current behavior.
- Media Manager (LP-005) gained dimensions and file-size search filters
  plus Smart Collections. The Search &amp; Filter form now has width/
  height min/max fields (images only — other file types have no
  dimensions) and a file-size min/max field (KB), backed by six new
  `MediaService::query()` filter keys. The Views sidebar gained five new
  capped, unpaginated collections alongside LP-006's existing ones:
  Recently Uploaded, Missing Alt Text, Large Files, ZIP Downloads, and
  Featured Images (posts'/pages' featured images specifically, not the
  site logo/favicon/OG image); Unused Files reuses LP-006's existing
  "Unused Media" view rather than duplicating it. New `MediaService`
  methods: `largestFiles()`, `missingAltText()`, `findMany()`.
- "Discover Directories" on Media Manager &rsaquo; Import from Server
  (LP-064): instead of typing exact absolute server paths from memory, an
  administrator can now scan for real subdirectories next to the Lumora
  Press install and check off which ones to add to the allowed import
  directories list. The scan is a bounded, read-only, one-level-deep look
  at the install's parent directory only — never configurable to an
  arbitrary starting path, never recursive, and nothing is added until
  explicitly selected and submitted. Same `manage_options`-only gate as
  the rest of the Import Settings panel.
- Admin visual polish, pass 2 (LP-063): dropdowns that previously fell
  outside the `.lp-field` wrapper (the Theme Editor's theme picker, the
  Menus page's menu picker, the Posts list's bulk-action select, the
  Media Manager grid's bulk-action/target-folder selects) now get the
  same bordered/rounded/focus-ring chrome as every other form field
  instead of rendering as a bare native dropdown. Plain list-item links
  that had no color/underline rule of their own — Dashboard's Recent
  Posts/Recent Comments/Popular Downloads, the Media Manager's Views/
  Folders sidebar, a media item's generated-thumbnail-size links — now
  use the admin theme's accent color with underline-on-hover instead of
  the browser's default blue/purple/underlined styling. Media grid items
  gained the same shadow/hover-lift card treatment
  Theme/Plugin cards already have, and a filename's stray default
  underline (visible under its already-custom text color) is gone.
  Follow-up same day: the Comments page's Approve/Unapprove/Spam/Trash
  row actions now use the same pill-chip button treatment as every other
  admin row action instead of a separate, never-updated plain-underline
  style; and every table-cell link across the admin (post/page titles, a
  comment's excerpt and parent post) picked up the same default-blue/
  purple-link fix already applied to Dashboard/sidebar list links.
- Custom CSS is now its own Appearance sub-page (LP-062), alongside
  Themes/Widgets/Menus/Theme Editor, instead of a section embedded at the
  bottom of Appearance &rsaquo; Themes.
- Thumbnail and Media Import settings moved from Settings &rsaquo; Media
  onto their corresponding Media Manager sub-pages (LP-061): thumbnail
  size/quality/default-featured-image settings now live on Media Manager
  &rsaquo; Thumbnails, and the allowed server-import-directories list now
  lives on Media Manager &rsaquo; Import from Server, next to the tools
  that actually use them. Settings &rsaquo; Media now holds only the
  Statistics (download tracking) section. Both moved settings sections
  still require the `manage_options` capability (Administrator only),
  even though the rest of Media Manager only requires `upload_files`
  (also held by Author/Editor) — unlike everything else on those pages,
  these are site-wide configuration, not per-file actions.
- Media admin restructured into a "Media Manager" nav section (LP-060):
  the single, 922-line `/admin/media` page — previously three workflows
  crammed behind an `?action=` query param plus two panels embedded in
  the library view — is now four proper sub-pages: **Media** (the
  library grid and per-item editor), **Upload** (a dedicated add-new-file
  page), **Import from Server** (the existing LP-041 flow, now its own
  page instead of `?action=import`), and **Thumbnails** (bulk regenerate/
  orphan cleanup, previously a panel on the library page). No behavior
  changed — same forms, same handlers, same CSRF actions — only the
  navigation structure.
- Release notes on the Maintenance &rsaquo; Updates page are now rendered
  as actual Markdown (LP-059) instead of shown as literal `##`/`*`/`**`
  source text in a `<pre>` block — reuses the same `ContentRenderer`
  Markdown-to-sanitized-HTML pipeline already built for post/page content.
- Modernized admin row-action buttons (Duplicate, Trash, Delete, Restore,
  Remove, Revoke, etc. across Posts, Pages, Categories, Tags, Comments,
  Media, Users, API Tokens, Widgets, Menus, Redirects, and the Theme File
  Editor) from bare underlined text links into small bordered pill chips.
  Along the way, fixed a color-semantics bug: every one of these actions
  previously rendered in the same danger/error red regardless of what it
  actually did, so a fully reversible action like "Duplicate" or "Restore"
  looked as alarming as an irreversible "Delete". Genuinely destructive
  actions now get a distinct danger-red chip (`.lp-button--link--danger`);
  everything else uses a neutral accent-colored chip.
- Modernized the Maintenance &rsaquo; Updates page's GitHub/Manual Update
  tab bar (LP-057) into a pill-style segmented control with a solid
  accent-colored active tab, replacing the earlier flat underline style.
- Classic Widgets (LP-048): a new Appearance &rsaquo; Widgets admin screen
  backed by a persisted `widgets_config` option — widget areas registered
  by a theme can now have individual widget instances added, configured,
  reordered, and removed independently. Ten built-in widget types ship:
  Text, Custom HTML, Search, Navigation Menu, Pages, Categories, Recent
  Posts, Recent Comments, Archives (backed by a new `/archive/{year}/{month}`
  route), Tag Cloud, and Meta.
- Navigation Menus (LP-049): a new Appearance &rsaquo; Menus admin screen
  for building named, reusable menus (create/rename/duplicate/delete) and
  assigning them independently to theme-registered locations, persisted as
  `nav_menus`/`nav_menu_locations` options. Menu items (Pages, Posts,
  Categories, Tags, Custom Links) support unlimited-depth submenus, and
  the default theme renders real nested dropdown navigation.
- Password Reset (LP-058): a self-service "forgot password" flow —
  request a reset link by email, click it, set a new password. Uses
  single-use selector/validator tokens (modeled on the existing "Remember
  Me" tokens) with a 60-second per-account cooldown and IP-based rate
  limiting on the request form, and never reveals whether a submitted
  email is actually registered. Resetting a password also revokes that
  user's other "Remember Me" sessions. Sent via a new dependency-free
  `Mailer`/`NativeMailer` (PHP's built-in `mail()`), since no mail library
  exists in this codebase yet.
- Akismet spam-checking integration and configurable login lockout
  (LP-025): a built-in, opt-in Akismet client checks new comments for spam
  (Settings &rsaquo; Security &rsaquo; Spam Protection — API key entry,
  key verification, automatic fallback to existing local protections if
  Akismet is unavailable), and moderator Spam/Not-Spam actions now report
  back to Akismet to improve its accuracy. The existing login-attempt
  throttle's thresholds (max attempts, window, lockout duration) are now
  admin-configurable on the same Security page rather than hardcoded, and
  that page documents a user-enumeration audit confirming no exploitable
  surface exists in this codebase (no XML-RPC, public author archives, or
  REST user-listing).
- SEO Tools (LP-022): per-post/page meta title and description overrides,
  self-referencing canonical URLs, a `/sitemap.xml` route, `BlogPosting`/
  `WebSite` JSON-LD structured data, and a new Settings &rsaquo; Redirects
  admin page (`RedirectService`) for exact-path 301/302 redirects checked
  before a request falls through to a real 404.
- Cache API & LiteSpeed Support (LP-037/LP-038): a new framework-agnostic
  `CacheManager` (purge by URL/tag/entire cache, configurable lifetimes)
  with a `LiteSpeedCacheDriver` that auto-detects LiteSpeed/OpenLiteSpeed
  and purges via `X-LiteSpeed-Purge` headers, falling back to an inert
  null driver elsewhere. Eligible public pages (homepage, category/tag/
  search/page/archive listings — not single posts, which embed a
  session-bound comment-form CSRF token) are cached for guests only, with
  conditional-GET (ETag/304) support. Saving a post, page, category, tag,
  comment, media item, or any site setting/widget/menu/theme change
  automatically purges the cache. Settings &rsaquo; Cache shows the
  detected driver, lets an admin override auto-detection, and adds a
  manual "Purge Entire Cache" button with a recent-purge log.
- Reading Settings (LP-046): a new Settings &rsaquo; Reading admin page —
  choose a static page (plus a separate posts-listing page) as the
  homepage instead of the latest-posts feed, set posts-per-page, and
  discourage search engines from indexing the site (a virtual
  `/robots.txt` plus a site-wide `noindex,nofollow` meta tag).
- General settings expansion (LP-042): the Settings &rsaquo; General page
  gained Site (tagline, website URL, admin email, timezone), Date & Time
  (date/time format, applied via new `the_date()`/`the_time()` theme
  helpers), Footer (custom copyright text), and SEO & Social Sharing
  (site meta description, default Open Graph image) sections. Also fixes
  a bug where the Settings &rsaquo; General timezone field silently had no
  effect — `bootstrap.php` was only ever reading the file-config copy of
  the timezone written once at install, never the database option.
- Media Statistics (LP-006): a "Views" panel on the Media Manager (All
  Files / Unused Media / Most Downloaded / Recently Downloaded / Never
  Downloaded) and a "Popular Downloads" Dashboard widget, backed by a new
  `download` counter recorded when a visitor uses a document/archive/
  audio/video item's new `/media/{id}/download` link (gated by a
  Settings &rsaquo; Media &rsaquo; Statistics toggle, default on). Image
  views and audio/video plays are not tracked — there's no PHP-mediated
  request to count them without adding overhead to every page load.
- Posts: Trash, Duplicate, and Bulk Actions (LP-008): the admin Posts list
  gained a Trash with restore (deleting a post now soft-deletes it by
  default), a "Duplicate" action that clones a post as a new Draft, bulk
  checkbox actions (Move to Trash / Restore / Delete Permanently /
  Publish / Mark as Draft), and live status-count tabs (All/Draft/
  Published/Scheduled/Trash). Restoring a trashed post always returns it
  to Draft rather than its prior status.
- Updates page GitHub/Manual tabs (LP-057): the Maintenance &rsaquo;
  Updates page is now split into a GitHub tab (default) and a Manual
  Update tab, so the ZIP-upload form is no longer buried at the bottom of
  a long scroll. A validation error on a manual upload automatically
  opens the Manual Update tab instead of hiding it behind GitHub.
- Manual/GitHub updates now remove obsolete core files (LP-026): a new
  `UpdateManifest` tracks which top-level core paths were installed as of
  the last successful update or restore, so an update that drops a
  previously-shipped top-level file or directory actually removes it
  instead of leaving it behind forever.
- Admin content page visual polish (LP-055): panels, tables, buttons, form
  fields, alerts, theme/plugin cards, status-bar typography, and the
  breadcrumb trail across the admin panel gained a more polished look
  (shadows, rounded corners, hover/focus feedback, a bordered breadcrumb
  pill) — a CSS-only change with matching dark-mode variants.
- Admin sidebar top-level nav refinement (LP-056): removed the icon from
  top-level sidebar nav items (Dashboard, Posts, Media, etc. — sub-nav
  items keep theirs) and uppercased top-level labels for a cleaner look;
  also replaced the "New Post" sidebar icon (previously invisible against
  the sidebar background) with `🆕`.

### Fixed

- **Media Manager: viewing a folder also showed every file filed under
  its subfolders, not just files filed directly in that folder.**
  `admin/views/media/media.php` built its folder-view filter as the
  current folder plus every descendant id
  (`FolderService::descendantIds()`), so e.g. viewing "Wallpapers" showed
  its own files mixed in with everything under "Wallpapers &rsaquo; Game
  of Thrones" and "Wallpapers &rsaquo; Battlestar Galactica" too, with no
  way to tell which folder a given file actually belonged to. Now scoped
  to just the current folder's own id — only "All Files" shows every
  file regardless of folder.
- **All Posts: any bulk action (Publish, Mark as Draft, Move to Trash,
  Add category, etc.) actually duplicated whichever post was listed
  first instead, landing on "Post duplicated as a new draft." (LP-068).**
  Root cause: the bulk-action `<form>` wrapped the posts `<table>`, whose
  rows each had their own per-row Duplicate/Trash/Restore/Delete
  Permanently `<form>` nested inside it — invalid HTML. Browsers drop
  the inner `<form>` start tag but still close the *outer* form on the
  first inner `</form>`, so every row's hidden `name="form"`/`name="id"`
  fields ended up merged into the bulk-action form's own POST body;
  since same-named fields keep only their last value, every "Apply"
  click was actually processed as whichever row-action form happened to
  close the outer form first. Fixed by moving each row's hidden
  inputs/button out of a nested `<form>` and associating them with a
  standalone form via the HTML `form=""` attribute instead
  (`admin/views/posts/all-posts.php`).
- **Add New Post/Page: the Markdown (and, more narrowly, WYSIWYG) editor
  could open pre-filled with whatever was last typed into a *different*
  post's or page's editor (LP-068).** EasyMDE's autosave `uniqueId` was
  derived from the content `<textarea>`'s `id`, which is a static string
  (`post-content`/`page-content`) shared by every post/page, so its
  localStorage-backed autosave restored the last-saved draft from
  *any* post/page — including into a brand-new, never-saved one. Now
  keyed by a real per-post/per-page id and disabled entirely when
  there's no id yet to key it by. The WYSIWYG (TinyMCE) editor had a
  narrower version of the same bug — its default autosave key already
  includes the page URL, which differs per *existing* post/page, but
  "Add New Post"/"Add New Page" is the same URL every time — fixed the
  same way (`admin/assets/js/content-editor.js`, `admin/views/posts/new.php`,
  `admin/views/pages.php`).
- **Every remaining inline `onclick=`/`onsubmit=`/`onchange=`/`oninput=`
  attribute across the admin was silently blocked by the
  Content-Security-Policy header (LP-069), meaning most delete/restore/
  reset actions submitted with no browser confirmation prompt at all.**
  17 admin views affected (theme options, cache, users, menus, API
  tokens, post/page editing and listings, widgets, plugins, comments,
  redirects, themes, media, tags, categories, theme file editor,
  maintenance backups), each with one or more `onsubmit="return
  confirm(...)"` delete/restore/reset confirmations, plus two
  `onchange="this.form.submit()"` auto-submitting selects and one
  `oninput` pair unchecking a paired "use theme default" checkbox. Fixed
  with three new generic, CSP-compliant scripts registered in
  `admin/views/layout-footer.php`: `confirm-submit.js`
  (`data-lp-confirm="message"` on a form or a specific submit button),
  `auto-submit.js` (`data-lp-auto-submit` on a select), and
  `color-field-reset.js` (`data-lp-color-reset-target="checkbox-id"` on
  a color input) — replacing every bespoke inline handler rather than
  writing a one-off script per view.
- **The Content-Security-Policy header silently blocked the Media
  Manager's new "Select all" checkbox, and an identical pre-existing bug
  on the All Posts screen's own "select all" checkbox (LP-068/LP-069).**
  `script-src 'self'` (no `'unsafe-inline'`) blocks inline `onclick="..."`
  attributes with no console error or other visible failure — the
  checkbox still toggled its own checked state (native browser behavior),
  but the handler that was meant to cascade that to every other checkbox
  never ran. Both now use a new external, CSP-compliant
  `admin/assets/js/select-all.js` instead.
- **The Content-Security-Policy header silently blocked every inline
  `<style>` tag**, including the pre-existing Custom CSS field — not just
  the new Theme Options CSS. `style-src 'self'` allows no inline styles
  without a nonce or hash, and CSP enforcement fails silently client-side
  (no console error, no PHP error), so this had likely been quietly
  breaking Custom CSS since it shipped. Fixed with a new per-request nonce
  (`LumoraPress\Core\Security\CspNonce`) threaded through the existing
  `csp_directives` filter and applied to both inline `<style>` tags in the
  default theme's `header.php`; `fonts.googleapis.com`/`fonts.gstatic.com`
  were also added to `style-src`/`font-src` for the new Google Fonts
  option, which would have hit the same class of silent block. See
  DECISIONS.md for the full root-cause story. The same-day follow-up below
  covers the three admin views that hit a related but different problem.
- **Three admin views' bulk-progress bars and menu structure indents used
  inline `style="..."` attributes**, a different CSP problem than inline
  `<style>` tags — nonces only cover `<style>`/`<script>` elements, not
  attributes, so these were very likely silently non-functional too.
  `admin/views/media/thumbnails.php`, `admin/views/media/import.php`
  (progress bar width), and `admin/views/appearance/menus.php` (menu item
  indent) now emit `data-style-width`/`data-style-margin-left` attributes
  instead; a new `admin/assets/js/dynamic-style.js`, enqueued globally from
  `admin/views/layout-footer.php`, applies them as real style properties on
  `DOMContentLoaded` — setting `element.style.<property>` via script is
  unaffected by `style-src`, unlike inline markup.
- **Follow-up, same day: the Tag Cloud widget's public-facing per-tag font
  size hit the same inline-`style`-attribute CSP problem noted as a known
  gap above.** `app/Core/Widgets/CoreWidgets.php`'s `tag_cloud` widget now
  emits `data-style-font-size="1.4em"` instead of `style="font-size:
  1.4em"`. Since this is public-facing rather than admin-only, the fix
  follows the "Public-Facing CSS Rule" and lives in the default theme
  itself: a new `content/themes/default/assets/js/dynamic-style.js` (same
  `data-style-*` → `element.style.<property>` pattern as the admin version
  above, but theme-scoped) is enqueued from the theme's own `footer.php`.
- **The Posts and Media "Search & Filter" panels stacked one full-width
  field per line**, pushing the panel to several screen-heights tall on
  wide viewports. Both filter `<form>`s (`admin/views/posts/all-posts.php`,
  `admin/views/media/media.php`) now use a new `.lp-admin__filter-form`
  wrapping flex-row layout (`admin/assets/css/admin.css`) instead, matching
  the existing flex-row pattern already used for the bulk-actions bar.
- **Password reset emails linked to a root-relative URL** (e.g.
  `/lumorapress/admin/reset-password`) instead of a full address the
  recipient's mail client could resolve. `admin/index.php` now builds the
  reset link with `home_url()` (absolute) instead of `admin_url()`
  (root-relative) — the same distinction RSS feeds and canonical tags
  already rely on `home_url()` for.
- **Widget saves silently failed with "That widget no longer exists"; Custom
  HTML/Text widget content never persisted (LP-048).** A newly added widget
  was saved into the persisted `widgets_config` option without an `id`.
  `WidgetManager::setWidgets()` generates one for any entry missing it, but
  only in memory for that request — since `include/bootstrap.php` reloads
  `widgets_config` and calls `setWidgets()` fresh on every request, an
  id-less widget got a *different* random id every page load, so the hidden
  `widget_id` field baked into the admin Widgets form never matched by the
  time it was submitted. `admin/views/appearance/widgets.php`'s `add_widget`
  handler now reads the generated id back before persisting, and
  `bootstrap.php` backfills and persists ids for any already-saved widget
  missing one, so widgets created before this fix self-heal on the next
  request.
- **Media Manager: folder rename box ran off the sidebar's edge on nested
  folders (LP-068).** The Virtual Folder System's "Manage" panel rename
  `<input>` had `width: auto` (browser default ~20 characters) with no
  max-width, so at deeper nesting levels (each level adds indentation via
  `.lp-folder-tree__children`'s `margin-left`/`padding-left`) the input
  pushed past the sidebar's visible boundary. `admin/assets/css/admin.css`
  now caps the input at a fixed width with `max-width: 100%` and
  `box-sizing: border-box`, and the containing form wraps instead of
  forcing a single line.

## [0.4.0] — 2026-08-01 — "Updates"

### Added

- GitHub Releases updater (LP-027): the Maintenance &rsaquo; Updates page can
  now check GitHub directly for new releases, alongside the existing
  manual-ZIP-upload path. "Check for Updates" queries the GitHub Releases
  API (configurable repository, optional personal access token for
  private forks/higher rate limits, and a stable/pre-release channel
  setting); "Download & Check" downloads the release — preferring the
  curated `LumoraPress-v{version}.zip` release asset and verifying its
  SHA-256 checksum when the release publishes one, falling back to
  GitHub's `zipball` archive otherwise — and feeds it straight into the
  same validate/backup/migrate/rollback pipeline manual uploads already
  use, so the confirm/install/cancel screen is identical either way.
  `{prefix}update_log` gained a `source` column (`github`/`manual`) shown
  on the update history table. A **Backups** panel lists every automatic
  backup pair with a confirm-gated "Restore" button. Since this codebase
  has no queue/cron infrastructure, "scheduled" checking is instead
  triggered opportunistically from the Dashboard page (throttled to an
  admin-configurable hourly/daily/weekly interval, or disabled entirely),
  which also surfaces a Dashboard notice when a newer version is found —
  this only ever checks, never downloads or installs anything on its own.
- Revisions (LP-017): editing a Post or Page from the admin now
  automatically snapshots the previous state to a new `{prefix}revisions`
  table before saving — no separate "save revision" action, no cron job.
  A "Revision History" panel on the edit screen lists every snapshot,
  shows a line-based diff against the current content, and can restore
  one (which itself snapshots the current state first, so a restore is
  always undoable). Retention is configurable under Settings &rsaquo; General
  (default 25 revisions per post/page, 0 = unlimited).
- Theme File Editor (LP-050): a fourth Appearance sub-page for browsing
  and editing a theme's files directly from the admin — CodeMirror 5
  syntax highlighting, find/replace, PHP syntax checking before save,
  automatic per-file backups with restore, and a warning banner when
  editing the currently-active theme. Every path is re-resolved and
  containment-checked before use, so a tampered path or a symlink planted
  inside a theme directory can't be used to read or write outside it.
- Plugin Browser (LP-045): the Plugins page now shows installed plugins
  as visual cards (preview image, active/inactive status, search, and
  filtering) with a details panel, mirroring the Theme Browser's design.
  Plugins can be uploaded, installed, replaced/updated, activated,
  deactivated, or deleted entirely from the browser, with rollback on a
  failed installation.
- Posts admin navigation (LP-054): Categories and Tags are now children
  of a Posts submenu (All Posts / New Post / Categories / Tags), matching
  classic WordPress, instead of three separate top-level entries. Old
  bookmarked `/admin/categories` and `/admin/tags` links still redirect
  to their new locations.
- Admin sidebar icons (LP-053): every sidebar navigation entry and
  sub-page now has a leading icon, purely decorative to screen readers.

### Fixed

- Manual and GitHub-sourced updates could leave a release's `docs/`
  contents (`CHANGELOG.md`/`HISTORY.md`/`TROUBLESHOOTING.md`) frozen at
  whatever version was installed originally: `docs/` was missing from the
  set of paths an update overlays, even though it ships with every
  release the same way `README.md`/`LICENSE.md` do.

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
